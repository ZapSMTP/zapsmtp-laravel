<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Http\Controllers;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZapSMTP\Laravel\Config;
use ZapSMTP\Laravel\Http\Middleware\VerifyWebhookSignature;
use ZapSMTP\Laravel\Jobs\ProcessWebhook;
use ZapSMTP\Laravel\Webhooks\WebhookProcessor;
use ZapSMTP\WebhookEvent;

/**
 * Answers a verified webhook request: its events are dispatched at once, or
 * from a queued job when config zapsmtp.webhook.queue.enabled is on.
 */
final class WebhookController
{
    public function __invoke(Request $request, WebhookProcessor $processor, Bus $bus, Repository $repository): JsonResponse
    {
        $events = $request->attributes->get(VerifyWebhookSignature::EVENTS_ATTRIBUTE);
        if (!\is_array($events) || $events === [] || array_filter($events, static fn ($e) => !$e instanceof WebhookEvent) !== []) {
            return new JsonResponse(['message' => $events === [] ? 'No events' : 'Webhook signature not verified'], $events === [] ? 200 : 400);
        }
        /** @var list<WebhookEvent> $events */
        $deliveryId = $request->headers->get('Zap-Event-Id');
        $config = Config::from($repository);
        if ($config->queueEnabled()) {
            $job = new ProcessWebhook(array_map(static fn (WebhookEvent $e) => $e->toArray(), $events), $deliveryId);
            $job->onConnection($config->queueConnection())->onQueue($config->queueName());
            $bus->dispatch($job);

            return new JsonResponse(['received' => \count($events), 'queued' => true], 202);
        }

        return new JsonResponse(['received' => \count($events), 'processed' => $processor->process($events, $deliveryId)]);
    }
}
