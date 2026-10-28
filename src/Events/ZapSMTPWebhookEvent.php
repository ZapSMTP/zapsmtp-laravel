<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

use ZapSMTP\WebhookEvent;

/**
 * A verified ZapSMTP webhook event, as a Laravel event. Listen for
 * WebhookReceived (every event) or a typed one:
 *
 *     Event::listen(function (EmailBounced $e) {
 *         User::where('email', $e->recipient())->update(['bounced' => true]);
 *     });
 *
 * Each event is dispatched once: repeated deliveries are skipped (config
 * zapsmtp.webhook.dedupe).
 */
abstract class ZapSMTPWebhookEvent
{
    /**
     * @param WebhookEvent $event the event as ZapSMTP sent it
     * @param string|null $id the id it was deduplicated on (evt_..., or the Zap-Event-Id header)
     */
    final public function __construct(
        public readonly WebhookEvent $event,
        public readonly ?string $id = null,
    ) {
    }

    /** The event type, e.g. email.delivered. */
    public function type(): string
    {
        return $this->event->type ?? '';
    }

    /**
     * The event's data (fields depend on the type; see the docs' Webhook events page).
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->event->data ?? [];
    }

    /** The ZapSMTP email id the event is about. */
    public function emailId(): ?string
    {
        return $this->string('email_id');
    }

    /** The recipient (data.to): one address per event. */
    public function recipient(): ?string
    {
        return $this->string('to');
    }

    /** @return array<string, mixed> the email's tags */
    public function tags(): array
    {
        return $this->map('tags');
    }

    /** @return array<string, mixed> the email's metadata */
    public function metadata(): array
    {
        return $this->map('metadata');
    }

    protected function string(string $key, ?string $in = null): ?string
    {
        $data = $in === null ? $this->data() : $this->map($in);
        $v = $data[$key] ?? null;

        return \is_string($v) ? $v : null;
    }

    /** @return array<string, mixed> */
    protected function map(string $key): array
    {
        $v = $this->data()[$key] ?? null;

        /** @var array<string, mixed> */
        return \is_array($v) ? $v : [];
    }
}
