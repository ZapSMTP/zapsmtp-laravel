<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use ZapSMTP\Client;
use Illuminate\Mail\MailManager;
use ZapSMTP\Internal\BaseService;
use ZapSMTP\Laravel\Testing\ZapSMTPFake;

/**
 * The ZapSMTP client: every API resource is a static method.
 *
 *     ZapSMTP::emails()->send([...]);
 *     ZapSMTP::domains()->list();
 *     ZapSMTP::webhooks()->verify($payload, $headers, $secret);
 *
 * @method static \ZapSMTP\Service\EmailsService emails()
 * @method static \ZapSMTP\Service\ThreadsService threads()
 * @method static \ZapSMTP\Service\InboundService inbound()
 * @method static \ZapSMTP\Service\SimulatorService simulator()
 * @method static \ZapSMTP\Service\StatsService stats()
 * @method static \ZapSMTP\Service\DomainsService domains()
 * @method static \ZapSMTP\Service\ApiKeysService apiKeys()
 * @method static \ZapSMTP\Service\SmtpCredentialsService smtpCredentials()
 * @method static \ZapSMTP\Service\WebhooksService webhooks()
 * @method static \ZapSMTP\Service\TemplatesService templates()
 * @method static \ZapSMTP\Service\SuppressionsService suppressions()
 * @method static \ZapSMTP\Service\DedicatedIpsService dedicatedIps()
 * @method static \ZapSMTP\Service\LogsService logs()
 * @method static \ZapSMTP\Service\UsageService usage()
 * @method static \ZapSMTP\Service\TemplateModulesService templateModules()
 * @method static \ZapSMTP\Service\TemplateAssetsService templateAssets()
 * @method static \ZapSMTP\Service\TemplateTimersService templateTimers()
 * @method static \ZapSMTP\Service\ActionsService actions()
 * @method static \ZapSMTP\Service\VariablesService variables()
 * @method static \ZapSMTP\ApiResponse|null lastResponse()
 * @method static string getBaseUrl()
 *
 * In tests, ZapSMTP::fake() stops real sends and records them:
 *
 *     ZapSMTP::fake();
 *     ZapSMTP::assertSent(fn (array $email) => in_array('jane@example.org', $email['to']));
 *
 * @see Client
 * @see ZapSMTPFake
 */
final class ZapSMTP extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    /**
     * Replaces the client, and the zapsmtp mailer's, with a fake that sends
     * nothing and records every request, for the assertions below.
     */
    public static function fake(): ZapSMTPFake
    {
        $app = static::getFacadeApplication();
        if ($app === null) {
            throw new \RuntimeException('ZapSMTP::fake() needs a Laravel application');
        }
        $fake = new ZapSMTPFake();
        $app->instance(ZapSMTPFake::class, $fake);
        static::swap($fake->client());
        if ($app->resolved('mail.manager')) {
            $manager = $app->make('mail.manager');
            if ($manager instanceof MailManager) {
                $manager->forgetMailers(); // their transports hold the real client
            }
        }

        return $fake;
    }

    /** @param (callable(array<string, mixed>): bool)|null $filter */
    public static function assertSent(?callable $filter = null, int $times = 0): void
    {
        self::fakeInstance()->assertSent($filter, $times);
    }

    public static function assertSentTo(string $address): void
    {
        self::fakeInstance()->assertSentTo($address);
    }

    /** @param (callable(array<string, mixed>, array<string, mixed>): bool)|null $filter */
    public static function assertSentTemplate(string $id, ?callable $filter = null): void
    {
        self::fakeInstance()->assertSentTemplate($id, $filter);
    }

    public static function assertNothingSent(): void
    {
        self::fakeInstance()->assertNothingSent();
    }

    public static function assertSentCount(int $count): void
    {
        self::fakeInstance()->assertSentCount($count);
    }

    /** @return list<array<string, mixed>> the emails sent while faking */
    public static function sent(): array
    {
        return self::fakeInstance()->sent();
    }

    private static function fakeInstance(): ZapSMTPFake
    {
        $app = static::getFacadeApplication();
        $fake = $app !== null && $app->bound(ZapSMTPFake::class) ? $app->make(ZapSMTPFake::class) : null;
        if (!$fake instanceof ZapSMTPFake) {
            throw new \RuntimeException('Call ZapSMTP::fake() before its assertions');
        }

        return $fake;
    }

    /**
     * A resource of the client (ZapSMTP::emails()), else a client method.
     *
     * @param string $method
     * @param array<array-key, mixed> $args
     */
    public static function __callStatic($method, $args): mixed
    {
        $client = static::getFacadeRoot();
        if ($args === [] && $client instanceof Client && property_exists($client, $method)) {
            $service = (new \ReflectionProperty($client, $method))->isPublic() ? $client->{$method} : null;
            if ($service instanceof BaseService) {
                return $service;
            }
        }

        return parent::__callStatic($method, $args);
    }
}
