<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Http;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use ZapSMTP\Laravel\Http\Controllers\WebhookController;
use ZapSMTP\Laravel\Http\Middleware\VerifyWebhookSignature;

/**
 * The webhook endpoint. In routes/web.php or routes/api.php:
 *
 *     Route::zapsmtpWebhooks();                    // POST /zapsmtp/webhooks
 *     Route::zapsmtpWebhooks('/hooks/zapsmtp');    // another path
 *
 * CSRF protection is turned off for it; the Zap-Signature check replaces it.
 */
final class Webhooks
{
    public const DEFAULT_URI = 'zapsmtp/webhooks';

    public const ROUTE_NAME = 'zapsmtp.webhooks';

    private const CSRF = [
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
    ];

    public static function route(string $uri = self::DEFAULT_URI): Route
    {
        $route = RouteFacade::post($uri, WebhookController::class);
        $route->middleware(VerifyWebhookSignature::class);
        $route->withoutMiddleware(array_values(array_filter(self::CSRF, 'class_exists')));

        return $route->name(self::ROUTE_NAME);
    }
}
