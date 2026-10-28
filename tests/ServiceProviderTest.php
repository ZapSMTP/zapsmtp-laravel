<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\ServiceProvider;
use ZapSMTP\Client;
use ZapSMTP\Laravel\Facades\ZapSMTP;
use ZapSMTP\Laravel\Tests\Support\RecordingHttpClient;
use ZapSMTP\Laravel\ZapSMTPServiceProvider;
use ZapSMTP\Service\DomainsService;
use ZapSMTP\Service\EmailsService;

final class ServiceProviderTest extends TestCase
{
    public function testRegistersTheMailerWithoutConfigMailEdits(): void
    {
        self::assertSame(['transport' => 'zapsmtp'], $this->config()->get('mail.mailers.zapsmtp'));
        self::assertInstanceOf(\ZapSMTP\Laravel\Mail\ZapSMTPTransport::class, $this->transport());
    }

    public function testKeepsAMailerTheAppDefined(): void
    {
        $this->config()->set('mail.mailers.zapsmtp', ['transport' => 'zapsmtp', 'key' => 'zap_own']);
        (new ZapSMTPServiceProvider($this->app ?? self::fail()))->register();
        self::assertSame(['transport' => 'zapsmtp', 'key' => 'zap_own'], $this->config()->get('mail.mailers.zapsmtp'));
    }

    public function testTheFacadeGivesTheClientsResources(): void
    {
        $http = new RecordingHttpClient(new Response(200, ['X-Request-Id' => 'req_9'], '{"object":"list","data":[],"has_more":false}'));
        $this->fakeApi($http);
        self::assertInstanceOf(EmailsService::class, ZapSMTP::emails());
        self::assertInstanceOf(DomainsService::class, ZapSMTP::domains());
        ZapSMTP::domains()->list();
        self::assertSame('req_9', ZapSMTP::lastResponse()?->requestId);
        self::assertSame('https://api.test/v1', ZapSMTP::getBaseUrl());
        $this->expectException(\Error::class);
        ZapSMTP::transport(); // @phpstan-ignore staticMethod.notFound
    }

    public function testTheFacadeDocumentsEveryResource(): void
    {
        $doc = (string) (new \ReflectionClass(ZapSMTP::class))->getDocComment();
        foreach ((new \ReflectionClass(Client::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            $type = $p->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertStringContainsString("@method static \\{$type->getName()} {$p->getName()}()", $doc);
        }
    }

    public function testPublishesTheConfigAndTheMigration(): void
    {
        foreach (['zapsmtp-config', 'zapsmtp-migrations'] as $tag) {
            $paths = ServiceProvider::pathsToPublish(ZapSMTPServiceProvider::class, $tag);
            self::assertCount(1, $paths);
            self::assertFileExists((string) array_key_first($paths));
        }
    }

    public function testRegistersTheCommands(): void
    {
        $commands = array_keys(\Illuminate\Support\Facades\Artisan::all());
        foreach (['zapsmtp:install', 'zapsmtp:test', 'zapsmtp:status', 'zapsmtp:webhook'] as $c) {
            self::assertContains($c, $commands);
        }
    }

    public function testFakeNeedsAnApplication(): void
    {
        $app = ZapSMTP::getFacadeApplication();
        ZapSMTP::setFacadeApplication(null);
        try {
            $this->expectException(\RuntimeException::class);
            ZapSMTP::fake();
        } finally {
            ZapSMTP::setFacadeApplication($app);
        }
    }
}
