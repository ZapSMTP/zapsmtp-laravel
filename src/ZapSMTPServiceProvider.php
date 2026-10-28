<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZapSMTP\Client;
use ZapSMTP\Laravel\Events\WebhookReceived;
use ZapSMTP\Laravel\Exceptions\InvalidConfigurationException;
use ZapSMTP\Laravel\Http\Webhooks;
use ZapSMTP\Laravel\Listeners\SyncSuppressions;
use ZapSMTP\Laravel\Mail\QueuedSendContext;
use ZapSMTP\Laravel\Mail\ZapSMTPTransport;
use ZapSMTP\Laravel\Testing\ZapSMTPFake;

/**
 * Registers the ZapSMTP client (container, facade), the zapsmtp mailer, the
 * Route::zapsmtpWebhooks() macro, the artisan commands and, when enabled,
 * the suppression sync. Holds no state of its own (safe under Octane).
 */
final class ZapSMTPServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/zapsmtp.php', 'zapsmtp');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            /** @var Repository $config */
            $config = $app->make('config');

            return Config::from($config)->client();
        });
        $this->app->alias(Client::class, 'zapsmtp');
        $this->app->singleton(QueuedSendContext::class);

        // MAIL_MAILER=zapsmtp works without editing config/mail.php
        /** @var Repository $config */
        $config = $this->app->make('config');
        if (!$config->has('mail.mailers.zapsmtp')) {
            $config->set('mail.mailers.zapsmtp', ['transport' => 'zapsmtp']);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/zapsmtp.php' => $this->app->configPath('zapsmtp.php')], 'zapsmtp-config');
            $this->publishes([
                __DIR__ . '/../database/migrations/add_zapsmtp_suppressed_at_to_users_table.php.stub'
                    => $this->app->databasePath('migrations/' . date('Y_m_d_His') . '_add_zapsmtp_suppressed_at_to_users_table.php'),
            ], 'zapsmtp-migrations');
            $this->commands([Console\InstallCommand::class, Console\TestCommand::class, Console\StatusCommand::class, Console\WebhookCommand::class]);
        }

        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('zapsmtp', function (array $mailer): ZapSMTPTransport {
                // a mailer may carry its own key: 'zapsmtp_eu' => ['transport' => 'zapsmtp', 'key' => env(...)]
                $key = $mailer['key'] ?? null;
                if ($key !== null && (!\is_string($key) || $key === '')) {
                    throw new InvalidConfigurationException('The key of a zapsmtp mailer (config mail.mailers.*.key) must be a non-empty string');
                }
                /** @var Repository $config */
                $config = $this->app->make('config');
                // while faking, every zapsmtp mailer (its own key or not) uses the fake
                $fake = $this->app->bound(ZapSMTPFake::class) ? $this->app->make(ZapSMTPFake::class) : null;
                $client = match (true) {
                    $fake instanceof ZapSMTPFake => $fake->client(),
                    $key === null => $this->app->make(Client::class),
                    default => Config::from($config)->client($key),
                };
                \assert($client instanceof Client);

                $queue = $this->app->make(QueuedSendContext::class);
                \assert($queue instanceof QueuedSendContext);

                $logger = $this->app->make('log');

                return new ZapSMTPTransport($client, $queue, null, $logger instanceof \Psr\Log\LoggerInterface ? $logger : null);
            });
        });

        $events = $this->app->make(Dispatcher::class);
        \assert($events instanceof Dispatcher);
        $events->listen(JobProcessing::class, function (JobProcessing $e): void {
            $this->queuedSends()->start($e->job);
        });
        $events->listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], function (): void {
            $this->queuedSends()->stop();
        });

        Router::macro('zapsmtpWebhooks', fn (string $uri = Webhooks::DEFAULT_URI): Route => Webhooks::route($uri));

        /** @var Repository $config */
        $config = $this->app->make('config');
        if (Config::from($config)->suppressionEnabled()) {
            $events->listen(WebhookReceived::class, SyncSuppressions::class);
        }
    }

    private function queuedSends(): QueuedSendContext
    {
        $context = $this->app->make(QueuedSendContext::class);
        \assert($context instanceof QueuedSendContext);

        return $context;
    }
}
