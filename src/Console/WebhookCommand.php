<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use ZapSMTP\Client;
use ZapSMTP\Exception\ZapSMTPException;
use ZapSMTP\Laravel\Http\Webhooks;
use ZapSMTP\Model\Webhook;

/**
 * php artisan zapsmtp:webhook: finds or creates the ZapSMTP webhook for this
 * app's endpoint and stores its secret in .env (ZAPSMTP_WEBHOOK_SECRET) when
 * that is not set.
 */
final class WebhookCommand extends Command
{
    protected $signature = 'zapsmtp:webhook
        {--url= : The endpoint URL (default: the zapsmtp.webhooks route, or APP_URL/zapsmtp/webhooks)}
        {--event=* : Event types to send (default: all)}
        {--rotate : Rotate the secret of an existing webhook}';

    protected $description = 'Create or show the ZapSMTP webhook for this app and store its secret';

    public function handle(Repository $config, Router $router): int
    {
        $url = $this->option('url');
        if (!\is_string($url) || $url === '') {
            $appUrl = $config->get('app.url');
            $base = rtrim(\is_string($appUrl) ? $appUrl : '', '/');
            $router->getRoutes()->refreshNameLookups();
            $route = $router->getRoutes()->getByName(Webhooks::ROUTE_NAME);
            $url = $base . '/' . ltrim($route === null ? Webhooks::DEFAULT_URI : $route->uri(), '/');
        }
        if (!str_starts_with($url, 'https://')) {
            $this->components->error("ZapSMTP sends webhooks to https:// URLs only, not $url (set APP_URL or pass --url)");

            return self::FAILURE;
        }
        try {
            $client = $this->laravel->make(Client::class);
            \assert($client instanceof Client);
            $hook = null;
            foreach ($client->webhooks->list() as $w) {
                if ($w->url === $url) {
                    $hook = $w;
                    break;
                }
            }
            if ($hook === null) {
                $events = array_values(array_filter((array) $this->option('event'), 'is_string'));
                $hook = $client->webhooks->create(['url' => $url] + ($events === [] ? [] : ['events' => $events]));
                $this->components->info("Created the webhook {$hook->id} for $url");
            } else {
                $this->components->info("The webhook {$hook->id} already sends to $url");
                if ($this->option('rotate')) {
                    $hook = $client->webhooks->rotateSecret((string) $hook->id);
                    $this->components->info('Rotated its secret (the old one keeps signing for 24 hours)');
                }
            }
        } catch (ZapSMTPException|\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $this->show($hook);
        $this->storeSecret($hook->secret);

        return self::SUCCESS;
    }

    private function show(Webhook $hook): void
    {
        $this->components->twoColumnDetail('Events', $hook->events === null || $hook->events === [] ? 'all' : implode(', ', $hook->events));
        $this->components->twoColumnDetail('Enabled', $hook->enabled === false ? '<fg=red>no</> ' . ($hook->disabled_reason ?? '') : 'yes');
    }

    private function storeSecret(?string $secret): void
    {
        if ($secret === null || !str_starts_with($secret, 'whsec_')) {
            $this->components->warn('The API did not return the secret: copy it from the dashboard into ZAPSMTP_WEBHOOK_SECRET, or run with --rotate');

            return;
        }
        $app = $this->laravel;
        $env = new EnvFile($app instanceof \Illuminate\Foundation\Application ? $app->environmentFilePath() : $app->basePath('.env'));
        $result = $env->add('ZAPSMTP_WEBHOOK_SECRET', $secret, true);
        if ($result === EnvFile::ADDED) {
            $this->components->info('Stored the secret in .env as ZAPSMTP_WEBHOOK_SECRET (hint: ' . substr($secret, 0, 10) . '...)');
        } elseif ($result === EnvFile::PRESENT) {
            $this->components->info('ZAPSMTP_WEBHOOK_SECRET in .env is this webhook\'s secret');
        } else {
            $this->components->warn("Set ZAPSMTP_WEBHOOK_SECRET=$secret in your environment (.env " . ($result === EnvFile::NO_FILE ? 'not found' : 'has another value, left alone') . ')');
        }
    }
}
