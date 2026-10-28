<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Route;
use ZapSMTP\Laravel\Console\EnvFile;
use ZapSMTP\Laravel\Http\Webhooks;
use ZapSMTP\Laravel\Tests\Support\RecordingHttpClient;

final class CommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/zap-laravel-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $app = $this->app;
        self::assertInstanceOf(\Illuminate\Foundation\Application::class, $app);
        $app->useEnvironmentPath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', \GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir);
        $published = $this->app?->configPath('zapsmtp.php');
        if ($published !== null && is_file($published)) {
            unlink($published);
        }
        parent::tearDown();
    }

    private function write(string $file, string $content): void
    {
        file_put_contents("{$this->dir}/$file", $content);
    }

    private function read(string $file): string
    {
        return (string) file_get_contents("{$this->dir}/$file");
    }

    public function testInstallPublishesTheConfigAndAddsMissingEnvEntries(): void
    {
        $this->write('.env', "APP_NAME=Shop\nMAIL_MAILER=smtp\nZAPSMTP_API_KEY=zap_existing");
        $this->write('.env.example', "APP_NAME=Shop\n");
        $this->command('zapsmtp:install')
            ->expectsOutputToContain('MAIL_MAILER is smtp')
            ->expectsOutputToContain('Next steps')
            ->assertSuccessful();
        self::assertSame("APP_NAME=Shop\nMAIL_MAILER=smtp\nZAPSMTP_API_KEY=zap_existing\nZAPSMTP_WEBHOOK_SECRET=\n", $this->read('.env'));
        self::assertSame("APP_NAME=Shop\nZAPSMTP_API_KEY=\nMAIL_MAILER=zapsmtp\nZAPSMTP_WEBHOOK_SECRET=\n", $this->read('.env.example'));
        self::assertFileExists((string) $this->app?->configPath('zapsmtp.php'));
        $this->command('zapsmtp:install')->assertSuccessful();
        self::assertSame("APP_NAME=Shop\nZAPSMTP_API_KEY=\nMAIL_MAILER=zapsmtp\nZAPSMTP_WEBHOOK_SECRET=\n", $this->read('.env.example'));
    }

    public function testInstallWarnsWithoutEnvFiles(): void
    {
        $this->command('zapsmtp:install', ['--force' => true])
            ->expectsOutputToContain('.env not found')
            ->assertSuccessful();
    }

    public function testEnvFileHandlesQuotesExportsAndEmptyValues(): void
    {
        $this->write('.env', "export A=\"x\"\nB=\nC=1");
        $env = new EnvFile("{$this->dir}/.env");
        self::assertSame('x', $env->get('A'));
        self::assertSame(EnvFile::PRESENT, $env->add('A', 'x'));
        self::assertSame(EnvFile::DIFFERENT, $env->add('C', '2'));
        self::assertSame(EnvFile::PRESENT, $env->add('B', ''));
        self::assertSame(EnvFile::ADDED, $env->add('B', 'whsec_1', true));
        self::assertSame(EnvFile::ADDED, $env->add('D', 'two words'));
        self::assertSame("export A=\"x\"\nB=whsec_1\nC=1\nD=\"two words\"\n", $this->read('.env'));
        self::assertNull((new EnvFile("{$this->dir}/missing"))->get('A'));
        $this->write('.empty', '');
        self::assertSame(EnvFile::ADDED, (new EnvFile("{$this->dir}/.empty"))->add('A', '1'));
        self::assertSame("A=1\n", $this->read('.empty'));
    }

    public function testTestSendsThroughTheMailerAndShowsTheId(): void
    {
        $http = new RecordingHttpClient(new Response(200, [], '{"id":"019a-test"}'));
        $this->fakeApi($http);
        $this->command('zapsmtp:test', ['to' => 'jane@example.org'])
            ->expectsOutputToContain('email id 019a-test')
            ->assertSuccessful();
        self::assertSame(['jane@example.org'], $http->body()['to']);
        self::assertSame('ZapSMTP test', $http->body()['subject']);
        $this->command('zapsmtp:test', ['to' => 'not-an-address'])->assertExitCode(2);
        $this->command('zapsmtp:test', ['to' => 'jane@example.org', '--mailer' => 'array'])
            ->expectsOutputToContain('does not use the zapsmtp transport')
            ->expectsOutputToContain('Sent to jane@example.org')
            ->assertSuccessful();
        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['error' => ['code' => 'domain_not_verified', 'message' => 'domain not verified']], 422)));
        $manager = $this->app?->make('mail.manager');
        self::assertInstanceOf(\Illuminate\Mail\MailManager::class, $manager);
        $manager->forgetMailers();
        $this->command('zapsmtp:test', ['to' => 'jane@example.org'])
            ->expectsOutputToContain('Not sent: ZapSMTP: domain not verified')
            ->assertFailed();
    }

    public function testStatusChecksTheKeyAndListsDomains(): void
    {
        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['object' => 'list', 'has_more' => false, 'data' => [
            ['name' => 'acme.com', 'status' => 'verified', 'dkim_verified' => true, 'spf_verified' => false, 'verified_at' => '2026-10-20T14:03:10Z'],
        ]])));
        $this->command('zapsmtp:status')
            ->expectsOutputToContain('https://api.test/v1')
            ->expectsOutputToContain('valid')
            ->expectsTable(['Domain', 'Status', 'DKIM', 'SPF', 'DMARC', 'Verified at'], [['acme.com', 'verified', 'yes', 'no', '-', '2026-10-20T14:03:10Z']])
            ->assertSuccessful();
    }

    public function testStatusWarnsAboutMissingDomainsAndSecrets(): void
    {
        $this->config()->set('zapsmtp.webhook.secret', null);
        $this->config()->set('mail.default', 'smtp');
        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['object' => 'list', 'has_more' => false, 'data' => []])));
        $this->command('zapsmtp:status')
            ->expectsOutputToContain('not zapsmtp')
            ->expectsOutputToContain('not set')
            ->expectsOutputToContain('No sending domain yet')
            ->assertSuccessful();
        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['object' => 'list', 'has_more' => false, 'data' => [['name' => 'a.test', 'status' => 'pending']]])));
        $this->command('zapsmtp:status')->expectsOutputToContain('No domain is verified yet')->assertSuccessful();
    }

    public function testStatusReportsARefusedKeyAndOtherErrors(): void
    {
        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['error' => ['code' => 'invalid_api_key', 'message' => 'invalid API key']], 401)));
        $this->command('zapsmtp:status')->expectsOutputToContain('The API key was refused')->assertFailed();
        $this->app?->instance(\ZapSMTP\Client::class, new \ZapSMTP\Client('k', ['http_client' => new RecordingHttpClient(RecordingHttpClient::json([], 500)), 'max_retries' => 0]));
        $this->command('zapsmtp:status')->expectsOutputToContain('HTTP 500')->assertFailed();
    }

    public function testWebhookCreatesTheEndpointAndStoresItsSecret(): void
    {
        Route::zapsmtpWebhooks('/hooks/zap'); // @phpstan-ignore staticMethod.notFound (a macro)
        $this->write('.env', "ZAPSMTP_WEBHOOK_SECRET=\n");
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(['object' => 'list', 'has_more' => false, 'data' => [['id' => 'wh_other', 'url' => 'https://elsewhere.test/x']]]),
            RecordingHttpClient::json(['id' => 'wh_1', 'url' => 'https://app.acme.test/hooks/zap', 'secret' => 'whsec_new', 'events' => ['email.bounced'], 'enabled' => true]),
        );
        $this->fakeApi($http);
        $this->command('zapsmtp:webhook', ['--event' => ['email.bounced']])
            ->expectsOutputToContain('Created the webhook wh_1')
            ->expectsOutputToContain('Stored the secret in .env')
            ->assertSuccessful();
        self::assertSame(['url' => 'https://app.acme.test/hooks/zap', 'events' => ['email.bounced']], $http->body(1));
        self::assertSame("ZAPSMTP_WEBHOOK_SECRET=whsec_new\n", $this->read('.env'));
    }

    public function testWebhookShowsAnExistingEndpointAndRotatesItsSecret(): void
    {
        $this->write('.env', "ZAPSMTP_WEBHOOK_SECRET=whsec_old\n");
        $hook = ['id' => 'wh_1', 'url' => 'https://app.acme.test/zapsmtp/webhooks', 'events' => [], 'enabled' => false, 'disabled_reason' => 'failing'];
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(['object' => 'list', 'has_more' => false, 'data' => [$hook]]),
            RecordingHttpClient::json($hook + ['secret' => 'whsec_rotated']),
        );
        $this->fakeApi($http);
        $this->command('zapsmtp:webhook', ['--rotate' => true])
            ->expectsOutputToContain('already sends to https://app.acme.test/zapsmtp/webhooks')
            ->expectsOutputToContain('Rotated its secret')
            ->expectsOutputToContain('failing')
            ->expectsOutputToContain('has another value')
            ->assertSuccessful();
        self::assertSame('/v1/webhooks/wh_1/rotate-secret', $http->requests[1]->getUri()->getPath());

        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['object' => 'list', 'has_more' => false, 'data' => [$hook + ['secret' => 'whsec_old']]])));
        $this->command('zapsmtp:webhook')->expectsOutputToContain('is this webhook\'s secret')->assertSuccessful();
        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['object' => 'list', 'has_more' => false, 'data' => [$hook]])));
        $this->command('zapsmtp:webhook')->expectsOutputToContain('did not return the secret')->assertSuccessful();
    }

    public function testWebhookNeedsHttpsAndReportsApiErrors(): void
    {
        $this->command('zapsmtp:webhook', ['--url' => 'http://localhost/hooks'])->expectsOutputToContain('https:// URLs only')->assertFailed();
        $this->config()->set('app.url', null);
        $this->command('zapsmtp:webhook')->expectsOutputToContain('https:// URLs only')->assertFailed();
        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['error' => ['code' => 'invalid_api_key', 'message' => 'invalid API key']], 401)));
        $this->command('zapsmtp:webhook', ['--url' => 'https://x.test/h'])->expectsOutputToContain('invalid API key')->assertFailed();
        self::assertNull(Route::getRoutes()->getByName(Webhooks::ROUTE_NAME));
        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['object' => 'list', 'has_more' => false, 'data' => [['id' => 'wh_9', 'url' => 'https://x.test/h', 'secret' => 'whsec_9']]])));
        $this->command('zapsmtp:webhook', ['--url' => 'https://x.test/h'])->expectsOutputToContain('.env not found')->assertSuccessful();
    }
}
