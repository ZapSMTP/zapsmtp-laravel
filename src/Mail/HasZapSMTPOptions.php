<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Mail;

/**
 * Fluent ZapSMTP options for a message (Mailable or MailMessage).
 *
 * @phpstan-import-type Options from MessageOptions
 */
trait HasZapSMTPOptions
{
    /** @var Options */
    protected array $zapsmtpOptions = [];

    /**
     * The options set so far.
     *
     * @return Options
     */
    public function zapsmtpOptions(): array
    {
        return $this->zapsmtpOptions;
    }

    /**
     * Tags (name => value), returned in webhooks as data.tags. Names and
     * values: letters, digits, _ and -.
     *
     * @param array<string, string> $tags
     */
    public function zapsmtpTags(array $tags): static
    {
        foreach ($tags as $name => $value) {
            $this->zapsmtpTag((string) $name, $value);
        }

        return $this;
    }

    public function zapsmtpTag(string $name, string $value): static
    {
        foreach ([$name, $value] as $v) {
            if (preg_match('/^[A-Za-z0-9_-]{1,256}$/', $v) !== 1) {
                throw new \InvalidArgumentException("ZapSMTP tag names and values are 1-256 letters, digits, _ or -: \"$v\"");
            }
        }
        $this->zapsmtpOptions['tags'][$name] = $value;

        return $this;
    }

    /**
     * Metadata (key => value), kept with the email and returned in webhooks.
     *
     * @param array<string, string|int|float|bool> $metadata
     */
    public function zapsmtpMetadata(array $metadata): static
    {
        foreach ($metadata as $key => $value) {
            $this->zapsmtpOptions['metadata'][(string) $key] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $this;
    }

    /** The stream: transactional (default) or broadcast. */
    public function zapsmtpStream(string $stream): static
    {
        if (!\in_array($stream, ['transactional', 'broadcast'], true)) {
            throw new \InvalidArgumentException("The ZapSMTP stream is transactional or broadcast, not \"$stream\"");
        }
        $this->zapsmtpOptions['stream'] = $stream;

        return $this;
    }

    /**
     * Sends it later: a time, or the API's relative form ("in 2 hours").
     */
    public function zapsmtpScheduleAt(\DateTimeInterface|string $at): static
    {
        $this->zapsmtpOptions['scheduled_at'] = $at instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')
            : $at;

        return $this;
    }

    /** Open, click and reply tracking for this email; null keeps the domain's setting. */
    public function zapsmtpTracking(?bool $opens = null, ?bool $clicks = null, ?bool $replies = null): static
    {
        $this->zapsmtpOptions['tracking'] = array_filter(['opens' => $opens, 'clicks' => $clicks, 'replies' => $replies], static fn ($v) => $v !== null);

        return $this;
    }

    /** The Idempotency-Key of the send: the same key never sends twice (for 24 hours). */
    public function zapsmtpIdempotencyKey(string $key): static
    {
        if (trim($key) === '') {
            // the API does not deduplicate on an empty key
            throw new \InvalidArgumentException('The ZapSMTP idempotency key must not be empty');
        }
        $this->zapsmtpOptions['idempotency_key'] = $key;

        return $this;
    }

    /**
     * Sends a stored ZapSMTP template instead of the message's own body. The
     * template's subject is used unless $subject is given; From, recipients,
     * attachments and the other options still come from the message.
     *
     * @param array<string, mixed> $variables
     */
    public function zapsmtpTemplate(string $id, array $variables = [], ?string $subject = null): static
    {
        if ($id === '') {
            throw new \InvalidArgumentException('The ZapSMTP template id must not be empty');
        }
        $this->zapsmtpOptions['template'] = ['id' => $id, 'variables' => $variables] + ($subject === null ? [] : ['subject' => $subject]);

        return $this;
    }
}
