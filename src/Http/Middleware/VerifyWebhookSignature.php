<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZapSMTP\Exception\WebhookVerificationException;
use ZapSMTP\Laravel\Config;
use ZapSMTP\Webhook;

/**
 * Lets a webhook request through only when its Zap-Signature is valid; the
 * verified events (one, or a batch) are then the request attribute
 * "zapsmtp_events". A bad signature is answered 400, as ZapSMTP's docs ask.
 */
final class VerifyWebhookSignature
{
    public const EVENTS_ATTRIBUTE = 'zapsmtp_events';

    public function __construct(private readonly Repository $config)
    {
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $config = Config::from($this->config);
        try {
            $events = Webhook::verifyEvents($request->getContent(), $request->headers->all(), $config->webhookSecret(), $config->webhookTolerance());
        } catch (WebhookVerificationException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        }
        $request->attributes->set(self::EVENTS_ATTRIBUTE, $events);

        return $next($request);
    }
}
