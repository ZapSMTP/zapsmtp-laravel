<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Webhooks;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use ZapSMTP\Laravel\Config;
use ZapSMTP\Laravel\Events;
use ZapSMTP\WebhookEvent;

/**
 * Turns verified webhook events into Laravel events, once each: an event
 * already processed (same id, kept in the cache) is skipped.
 */
final class WebhookProcessor
{
    /** @var array<string, class-string<Events\ZapSMTPWebhookEvent>> */
    public const EVENTS = [
        WebhookEvent::EMAIL_SENT => Events\EmailSent::class,
        WebhookEvent::EMAIL_DELIVERED => Events\EmailDelivered::class,
        WebhookEvent::EMAIL_DELIVERY_DELAYED => Events\EmailDeliveryDelayed::class,
        WebhookEvent::EMAIL_BOUNCED => Events\EmailBounced::class,
        WebhookEvent::EMAIL_COMPLAINED => Events\EmailComplained::class,
        WebhookEvent::EMAIL_FAILED => Events\EmailFailed::class,
        WebhookEvent::EMAIL_OPENED => Events\EmailOpened::class,
        WebhookEvent::EMAIL_CLICKED => Events\EmailClicked::class,
        WebhookEvent::EMAIL_REPLIED => Events\EmailReplied::class,
        WebhookEvent::EMAIL_SUPPRESSED => Events\EmailSuppressed::class,
        WebhookEvent::EMAIL_RECEIVED => Events\EmailReceived::class,
        WebhookEvent::THREAD_CREATED => Events\ThreadCreated::class,
        WebhookEvent::THREAD_REPLIED => Events\ThreadReplied::class,
    ];

    private const CACHE_PREFIX = 'zapsmtp:webhook:';

    public function __construct(
        private readonly Dispatcher $events,
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {
    }

    /**
     * The id an event is deduplicated on: its own id; else, alone in a
     * request, the request's Zap-Event-Id; else (in a batch) type and email id.
     */
    public static function eventId(WebhookEvent $event, ?string $deliveryId, bool $batch): ?string
    {
        if ($event->id !== null && $event->id !== '') {
            return $event->id;
        }
        if (!$batch && $deliveryId !== null && $deliveryId !== '') {
            return $deliveryId;
        }
        $emailId = $event->data['email_id'] ?? null;

        return \is_string($emailId) && $emailId !== '' ? ($event->type ?? '') . ':' . $emailId : null;
    }

    /**
     * Dispatches WebhookReceived and the type's own event for each event not
     * seen before; returns how many were dispatched.
     *
     * @param list<WebhookEvent> $events
     */
    public function process(array $events, ?string $deliveryId): int
    {
        $config = Config::from($this->config);
        $store = $config->dedupeEnabled() ? $this->cache->store($config->dedupeStore()) : null;
        $batch = \count($events) > 1;
        $done = 0;
        foreach ($events as $event) {
            $id = self::eventId($event, $deliveryId, $batch);
            $key = $id === null ? null : self::CACHE_PREFIX . hash('sha256', $id);
            if ($store !== null && $key !== null && !$store->add($key, true, $config->dedupeTtl())) {
                continue; // processed before
            }
            try {
                $this->events->dispatch(new Events\WebhookReceived($event, $id));
                $class = self::EVENTS[$event->type ?? ''] ?? null;
                if ($class !== null) {
                    $this->events->dispatch(new $class($event, $id));
                }
            } catch (\Throwable $e) {
                // not processed: let ZapSMTP's retry deliver it again
                if ($store !== null && $key !== null) {
                    $store->forget($key);
                }
                throw $e;
            }
            $done++;
        }

        return $done;
    }
}
