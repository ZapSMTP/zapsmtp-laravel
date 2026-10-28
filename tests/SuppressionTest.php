<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use ZapSMTP\Laravel\Events\WebhookReceived;
use ZapSMTP\Laravel\Listeners\SyncSuppressions;
use ZapSMTP\WebhookEvent;

final class SuppressionTest extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make('config');
        \assert($config instanceof \Illuminate\Contracts\Config\Repository);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('zapsmtp.suppression', ['enabled' => true, 'model' => SuppressionUser::class]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('email');
            $t->timestamp('email_suppressed_at')->nullable();
        });
        SuppressionUser::query()->insert([['email' => 'Jane@Example.org'], ['email' => 'john@example.org']]);
    }

    private function event(string $type, string $to): WebhookReceived
    {
        return new WebhookReceived(new WebhookEvent(['type' => $type, 'data' => ['to' => $to]]));
    }

    public function testMarksTheRecipientOnABounceOnce(): void
    {
        self::assertTrue(Event::hasListeners(WebhookReceived::class));
        Event::dispatch($this->event('email.bounced', 'jane@example.org'));
        self::assertNotNull(SuppressionUser::query()->where('email', 'Jane@Example.org')->value('email_suppressed_at'));
        $listener = $this->app?->make(SyncSuppressions::class);
        self::assertInstanceOf(SyncSuppressions::class, $listener);
        self::assertSame(0, $listener->handle($this->event('email.complained', 'jane@example.org')));
        self::assertSame(1, $listener->handle($this->event('email.complained', 'JOHN@example.org')));
    }

    public function testIgnoresOtherEventsAndMissingRecipients(): void
    {
        $listener = $this->app?->make(SyncSuppressions::class);
        self::assertInstanceOf(SyncSuppressions::class, $listener);
        self::assertSame(0, $listener->handle($this->event('email.delivered', 'jane@example.org')));
        self::assertSame(0, $listener->handle(new WebhookReceived(new WebhookEvent(['type' => 'email.bounced', 'data' => []]))));
        $this->config()->set('zapsmtp.suppression.enabled', false);
        self::assertSame(0, $listener->handle($this->event('email.bounced', 'jane@example.org')));
        self::assertNull(SuppressionUser::query()->where('email', 'Jane@Example.org')->value('email_suppressed_at'));
    }
}

final class SuppressionUser extends Model
{
    protected $table = 'users';

    public $timestamps = false;
}
