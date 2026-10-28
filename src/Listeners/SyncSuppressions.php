<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Listeners;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Date;
use ZapSMTP\Laravel\Config;
use ZapSMTP\Laravel\Events\WebhookReceived;

/**
 * Stamps the recipient's model on a bounce, a complaint or a suppressed send
 * (config zapsmtp.suppression), so the app can stop emailing the address:
 *
 *     User::whereNull('email_suppressed_at')->each(...)
 *
 * Registered by the service provider when zapsmtp.suppression.enabled is on.
 */
final class SyncSuppressions
{
    public function __construct(private readonly Repository $config)
    {
    }

    /** Marks the recipient's rows; returns how many were marked. */
    public function handle(WebhookReceived $event): int
    {
        $config = Config::from($this->config);
        $address = $event->recipient();
        if (!$config->suppressionEnabled() || $address === null || $address === ''
            || !\in_array($event->type(), $config->suppressionEvents(), true)) {
            return 0;
        }
        $model = $config->suppressionModel();
        $query = $model::query();
        $column = $query->getQuery()->getGrammar()->wrap($config->suppressionEmailColumn());

        /** @var literal-string $lower the column comes from config, quoted by the grammar */
        $lower = "LOWER($column)";

        return $query
            ->where(new Expression($lower), strtolower($address))
            ->whereNull($config->suppressionColumn())
            ->update([$config->suppressionColumn() => Date::now()]);
    }
}
