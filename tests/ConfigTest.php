<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use ZapSMTP\Client;
use ZapSMTP\Laravel\Config;
use ZapSMTP\Laravel\Exceptions\InvalidConfigurationException;
use ZapSMTP\Laravel\Tests\Fixtures\WelcomeMail;

final class ConfigTest extends TestCase
{
    public function testBuildsTheClientFromConfig(): void
    {
        $this->config()->set('zapsmtp.base_url', 'https://dev.test/v1/');
        $this->config()->set('zapsmtp.timeout', '12.5');
        $this->config()->set('zapsmtp.max_retries', '5');
        $client = $this->app?->make(Client::class);
        self::assertInstanceOf(Client::class, $client);
        self::assertSame($client, $this->app?->make('zapsmtp'));
        self::assertSame('https://dev.test/v1', $client->getBaseUrl());
        self::assertSame(12.5, $client->getTimeout());
        self::assertSame(5, $client->getMaxRetries());
    }

    public function testDefaults(): void
    {
        $config = new Config([]);
        self::assertNull($config->apiKey());
        self::assertNull($config->baseUrl());
        self::assertSame(300, $config->webhookTolerance());
        self::assertTrue($config->dedupeEnabled());
        self::assertNull($config->dedupeStore());
        self::assertSame(86400, $config->dedupeTtl());
        self::assertFalse($config->queueEnabled());
        self::assertNull($config->queueConnection());
        self::assertNull($config->queueName());
        self::assertFalse($config->suppressionEnabled());
        self::assertSame('email', $config->suppressionEmailColumn());
        self::assertSame('email_suppressed_at', $config->suppressionColumn());
        self::assertSame(['email.bounced', 'email.complained', 'email.suppressed'], $config->suppressionEvents());
        self::assertTrue((new Config(['webhook' => ['dedupe' => ['enabled' => 'yes']]]))->dedupeEnabled());
        self::assertSame(Client::DEFAULT_BASE_URL, (new Config(['api_key' => 'zap_x', 'base_url' => '']))->client()->getBaseUrl());
    }

    /** @return iterable<string, array{array<string, mixed>, callable(Config): mixed, string}> */
    public static function invalid(): iterable
    {
        yield 'api_key' => [['api_key' => 5], static fn (Config $c) => $c->apiKey(), 'zapsmtp.api_key must be a string, int given'];
        yield 'base_url' => [['base_url' => 'ftp://x'], static fn (Config $c) => $c->baseUrl(), 'zapsmtp.base_url must be an http(s) URL'];
        yield 'timeout' => [['timeout' => 0], static fn (Config $c) => $c->client(), 'zapsmtp.timeout must be a number above 0'];
        yield 'max_retries' => [['max_retries' => 'x'], static fn (Config $c) => $c->client(), 'zapsmtp.max_retries must be an integer of at least 0'];
        yield 'tolerance' => [['webhook' => ['tolerance' => 0]], static fn (Config $c) => $c->webhookTolerance(), 'zapsmtp.webhook.tolerance'];
        yield 'dedupe' => [['webhook' => ['dedupe' => ['enabled' => 'maybe']]], static fn (Config $c) => $c->dedupeEnabled(), 'must be true or false'];
        yield 'model' => [['suppression' => ['model' => 'stdClass']], static fn (Config $c) => $c->suppressionModel(), 'an Eloquent model class'];
        yield 'events' => [['suppression' => ['events' => [1]]], static fn (Config $c) => $c->suppressionEvents(), 'a list of event types'];
        yield 'secret' => [[], static fn (Config $c) => $c->webhookSecret(), 'Set ZAPSMTP_WEBHOOK_SECRET'];
    }

    /**
     * @param array<string, mixed> $values
     * @param callable(Config): mixed $read
     */
    #[DataProvider('invalid')]
    public function testRefusesInvalidValuesNamingTheKey(array $values, callable $read, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $read(new Config($values));
    }

    public function testAnInvalidConfigFailsTheMailerClearly(): void
    {
        $this->config()->set('zapsmtp.timeout', -1);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('zapsmtp.timeout');
        \Illuminate\Support\Facades\Mail::to('a@example.org')->send(new WelcomeMail());
    }

    public function testTheConfigFileReadsBothKeyVariables(): void
    {
        putenv('ZAP_API_KEY=zap_generic');
        try {
            /** @var array{api_key: string} $config */
            $config = require __DIR__ . '/../config/zapsmtp.php';
            self::assertSame('zap_generic', $config['api_key']);
            putenv('ZAPSMTP_API_KEY=zap_specific');
            /** @var array<string, mixed> $config */
            $config = require __DIR__ . '/../config/zapsmtp.php';
            self::assertSame('zap_specific', $config['api_key']);
            $parsed = new Config($config);
            self::assertSame(60.0, $parsed->client()->getTimeout());
            self::assertSame(259200, $parsed->dedupeTtl());
        } finally {
            putenv('ZAP_API_KEY');
            putenv('ZAPSMTP_API_KEY');
        }
    }

    public function testFallsBackToTheEnvironmentForTheKey(): void
    {
        $this->config()->set('zapsmtp.api_key', '');
        putenv('ZAP_API_KEY=zap_from_env');
        try {
            self::assertInstanceOf(Client::class, $this->app?->make(Client::class));
        } finally {
            putenv('ZAP_API_KEY');
        }
    }
}
