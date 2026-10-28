<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Mail;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;

/**
 * Carries a message's ZapSMTP options (tags, metadata, stream, schedule,
 * tracking, template, idempotency key) from a Mailable or MailMessage to the
 * transport, in a header the transport removes before sending.
 *
 * @internal
 *
 * @phpstan-type Options array{
 *     tags?: array<string, string>,
 *     metadata?: array<string, string>,
 *     stream?: string,
 *     scheduled_at?: string,
 *     tracking?: array{opens?: bool, clicks?: bool, replies?: bool},
 *     idempotency_key?: string,
 *     template?: array{id: string, variables: array<string, mixed>, subject?: string},
 * }
 */
final class MessageOptions
{
    public const HEADER = 'X-ZapSMTP-Options';

    /**
     * Stores options on a message, merged with any it has.
     *
     * @param Options $options
     */
    public static function attach(Email $email, array $options): void
    {
        if ($options === []) {
            return;
        }
        $merged = array_replace_recursive(self::read($email->getHeaders()), $options);
        $email->getHeaders()->remove(self::HEADER);
        $email->getHeaders()->addTextHeader(self::HEADER, base64_encode(json_encode($merged, \JSON_THROW_ON_ERROR)));
        // a template brings its own body: a message needs one to be valid
        if (isset($merged['template']) && $email->getTextBody() === null && $email->getHtmlBody() === null) {
            $email->text('');
        }
    }

    /**
     * The options on a message's headers; [] when none.
     *
     * @return Options
     */
    public static function read(Headers $headers): array
    {
        $header = $headers->get(self::HEADER);
        if ($header === null) {
            return [];
        }
        $json = base64_decode($header->getBodyAsString(), true);
        $options = $json === false ? null : json_decode($json, true);

        /** @var Options */
        return \is_array($options) ? $options : [];
    }
}
