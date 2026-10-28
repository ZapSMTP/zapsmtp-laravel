<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Mail;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use ZapSMTP\Client;
use ZapSMTP\Exception\ConflictException;
use ZapSMTP\Exception\ZapSMTPException;

/**
 * The zapsmtp mail transport: Mailables, notifications and Mail::raw() go
 * through the ZapSMTP API (emails->send), queued mail included.
 *
 * - From, To, Cc, Bcc, Reply-To, subject, HTML and text, custom headers
 *   (X-Zap-* and the headers the API sets itself are left out), attachments
 *   and inline images (embed: their content id is kept, so cid: works).
 * - Laravel's tag('name:value') becomes a ZapSMTP tag (a tag without a value
 *   is sent as name=true), metadata('key', 'value') becomes metadata.
 * - The InteractsWithZapSMTP / ZapSMTPMailMessage options: tags, metadata,
 *   stream, scheduled_at, tracking, template, idempotency key.
 *
 * Mail sent from a queued job (queued Mailables and notifications included)
 * gets an Idempotency-Key that stays the same when the job is retried, so a
 * retry never sends twice; zapsmtpIdempotencyKey() overrides it.
 *
 * After sending, the ZapSMTP email id is the SentMessage's message id
 * ($sent->getMessageId(), also on MessageSent) and the X-Zap-Email-ID header
 * of the original message.
 */
final class ZapSMTPTransport extends AbstractTransport
{
    /** Header added to the sent message: the ZapSMTP email id. */
    public const EMAIL_ID_HEADER = 'X-Zap-Email-ID';

    /** Headers the API sets itself, or refuses. */
    private const RESERVED = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'mime-version', 'content-type',
        'content-transfer-encoding', 'dkim-signature', 'return-path', 'received', 'sender', 'x-zapref',
    ];

    public function __construct(
        private readonly Client $client,
        private readonly ?QueuedSendContext $queue = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    /** The ZapSMTP email id of a sent message; null when another transport sent it. */
    public static function emailId(SentMessage|\Illuminate\Mail\SentMessage $sent): ?string
    {
        $original = $sent->getOriginalMessage();

        return $original instanceof Message ? $original->getHeaders()->get(self::EMAIL_ID_HEADER)?->getBodyAsString() : null;
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (!$original instanceof Message) {
            throw new TransportException('ZapSMTP sends structured messages (Symfony\Component\Mime\Message), not raw ones');
        }
        $email = MessageConverter::toEmail($original);
        $options = MessageOptions::read($email->getHeaders());
        /** @var array{to: string|list<string>} $params checked by the API */
        $params = $this->params($email, $message, $options);
        // the caller's key; else, in a queued job, one that survives the job's retries
        $key = $options['idempotency_key'] ?? $this->queue?->nextKey(
            array_values(array_map(static fn (Address $a) => strtolower($a->getAddress()), $message->getEnvelope()->getRecipients())),
            (string) $email->getSubject(),
        );
        try {
            $sent = $this->client->emails->send($params, $key === null ? [] : ['idempotency_key' => $key]);
        } catch (ConflictException $e) {
            if ($e->getErrorCode() !== 'idempotency_key_reused' || isset($options['idempotency_key']) || $key === null) {
                throw new TransportException('ZapSMTP: ' . $e->getMessage(), $e->getStatusCode(), $e);
            }
            // a retried job whose email was sent by an earlier attempt and
            // rendered differently this time: it is not sent again
            $this->getLogger()->notice('ZapSMTP: an earlier attempt of this queued job sent this email; not sent again', ['idempotency_key' => $key]);

            return;
        } catch (ZapSMTPException $e) {
            throw new TransportException('ZapSMTP: ' . $e->getMessage(), $e->getStatusCode(), $e);
        }
        if ($sent->id !== null) {
            $message->setMessageId($sent->id);
            $original->getHeaders()->remove(self::EMAIL_ID_HEADER);
            $original->getHeaders()->addTextHeader(self::EMAIL_ID_HEADER, $sent->id);
        }
    }

    /**
     * The emails->send parameters of a message.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function params(Email $email, SentMessage $message, array $options): array
    {
        $from = $email->getFrom();
        $template = \is_array($options['template'] ?? null) ? $options['template'] : null;
        $html = self::body($email->getHtmlBody());
        $params = [
            'from' => $from === [] ? self::address($message->getEnvelope()->getSender()) : self::address($from[0]),
            ...self::recipients($email, $message),
            'reply_to' => self::addresses($email->getReplyTo()),
            'subject' => $template === null ? $email->getSubject() : ($template['subject'] ?? null),
            'html' => $template === null ? $html : null,
            'text' => $template === null ? self::body($email->getTextBody()) : null,
        ];
        $headers = [];
        $tags = \is_array($options['tags'] ?? null) ? $options['tags'] : [];
        $metadata = [];
        /** @var HeaderInterface $header */
        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());
            if ($header instanceof TagHeader) {
                $parts = preg_split('/[:=]/', $header->getValue(), 2) ?: [];
                $tags[(string) ($parts[0] ?? '')] ??= $parts[1] ?? 'true';
            } elseif ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getValue();
            } elseif (!\in_array($name, self::RESERVED, true) && !str_starts_with($name, 'x-zap-') && $name !== strtolower(MessageOptions::HEADER)) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }
        $metadata = [...$metadata, ...(\is_array($options['metadata'] ?? null) ? $options['metadata'] : [])];
        $attachments = [];
        foreach ($email->getAttachments() as $part) {
            $attachments[] = self::attachment($part, (string) $html);
        }
        $params += [
            'headers' => $headers,
            'attachments' => $attachments,
            'tags' => array_map(static fn ($n, $v) => ['name' => (string) $n, 'value' => \is_scalar($v) ? (string) $v : ''], array_keys($tags), $tags),
            'metadata' => $metadata,
            'stream' => $options['stream'] ?? null,
            'scheduled_at' => $options['scheduled_at'] ?? null,
            'tracking' => $options['tracking'] ?? null,
            'template' => $template === null ? null : ['id' => $template['id'] ?? null, 'variables' => $template['variables'] ?? []],
        ];

        return array_filter($params, static fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /**
     * Who receives it. Normally To, Cc and Bcc as the headers say; when the
     * envelope names other recipients (a sink in staging, a redirect), exactly
     * the envelope's, all as To, and never the header recipients.
     *
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>}
     */
    private static function recipients(Email $email, SentMessage $message): array
    {
        $headers = [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()];
        $envelope = $message->getEnvelope()->getRecipients();
        if (self::addressSet($headers) === self::addressSet($envelope)) {
            return ['to' => self::addresses($email->getTo()), 'cc' => self::addresses($email->getCc()), 'bcc' => self::addresses($email->getBcc())];
        }

        return ['to' => self::addresses($envelope), 'cc' => [], 'bcc' => []];
    }

    /**
     * The addresses, lowercased, sorted and unique.
     *
     * @param array<Address> $addresses
     *
     * @return list<string>
     */
    private static function addressSet(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $a) {
            $out[strtolower($a->getAddress())] = true;
        }
        ksort($out);

        return array_map('strval', array_keys($out));
    }

    /**
     * @return array{filename: string, content: string, content_type: string, content_id?: string}
     */
    private static function attachment(DataPart $part, string $html): array
    {
        $filename = $part->getFilename() ?? 'attachment';
        $a = [
            'filename' => $filename,
            'content' => base64_encode($part->getBody()),
            'content_type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
        ];
        if ($part->getDisposition() === 'inline') {
            // the HTML refers to an inline part by its name (Symfony's embed) or
            // by its content id (Laravel's embed)
            $a['content_id'] = str_contains($html, 'cid:' . $filename) || !$part->hasContentId() ? $filename : $part->getContentId();
        }

        return $a;
    }

    /** @param resource|string|null $body */
    private static function body(mixed $body): ?string
    {
        if (\is_resource($body)) {
            $body = stream_get_contents($body);
        }

        return \is_string($body) ? $body : null;
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(self::address(...), $addresses));
    }

    /** "Name <address>", the name quoted when it has to be. */
    private static function address(Address $a): string
    {
        $name = $a->getName();
        if ($name === '') {
            return $a->getAddress();
        }
        if (preg_match('/^[\w !#$%&\'*+\/=?^`{|}~-]+$/u', $name) !== 1) {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }

        return $name . ' <' . $a->getAddress() . '>';
    }

    public function __toString(): string
    {
        return 'zapsmtp';
    }
}
