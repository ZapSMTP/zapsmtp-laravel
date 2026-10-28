<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\Mailer\Transport\TransportInterface;
use ZapSMTP\Client;
use ZapSMTP\Laravel\Tests\Support\RecordingHttpClient;
use ZapSMTP\Laravel\ZapSMTPServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param Application $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ZapSMTPServiceProvider::class];
    }

    /**
     * @param Application $app
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['ZapSMTP' => \ZapSMTP\Laravel\Facades\ZapSMTP::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('zapsmtp.api_key', 'zap_test_laravel');
        $config->set('zapsmtp.webhook.secret', 'whsec_laravel_test');
        $config->set('mail.default', 'zapsmtp');
        $config->set('mail.from', ['address' => 'hello@acme.test', 'name' => 'Acme']);
        $config->set('app.url', 'https://app.acme.test');
        $config->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function config(): Repository
    {
        $config = $this->app?->make('config');
        self::assertInstanceOf(Repository::class, $config);

        return $config;
    }

    /** @param array<string, mixed> $parameters */
    protected function command(string $command, array $parameters = []): \Illuminate\Testing\PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(\Illuminate\Testing\PendingCommand::class, $pending);

        return $pending;
    }

    /** The transport of a mailer. */
    protected function transport(string $mailer = 'zapsmtp'): TransportInterface
    {
        $m = \Illuminate\Support\Facades\Mail::mailer($mailer);
        self::assertInstanceOf(\Illuminate\Mail\Mailer::class, $m);

        return $m->getSymfonyTransport();
    }

    /** Puts a client on a recording HTTP client in the container. */
    protected function fakeApi(RecordingHttpClient $http): Client
    {
        $client = new Client('zap_test_laravel', ['http_client' => $http, 'base_url' => 'https://api.test/v1', 'sleeper' => static function (): void {
        }]);
        $this->app?->instance(Client::class, $client);

        return $client;
    }
}
