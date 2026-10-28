<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use ZapSMTP\Laravel\Webhooks\WebhookProcessor;
use ZapSMTP\WebhookEvent;

/** Dispatches verified webhook events from the queue (config zapsmtp.webhook.queue). */
final class ProcessWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param list<array<array-key, mixed>> $events the events as received
     */
    public function __construct(public readonly array $events, public readonly ?string $deliveryId)
    {
    }

    public function handle(WebhookProcessor $processor): void
    {
        $processor->process(array_map(static fn (array $e) => new WebhookEvent($e), $this->events), $this->deliveryId);
    }
}
