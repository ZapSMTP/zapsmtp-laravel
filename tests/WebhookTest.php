<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use ZapSMTP\Laravel\Events;
use ZapSMTP\Laravel\Exceptions\InvalidConfigurationException;
use ZapSMTP\Laravel\Http\Controllers\WebhookController;
use ZapSMTP\Laravel\Http\Webhooks;
use ZapSMTP\Laravel\Jobs\ProcessWebhook;
use ZapSMTP\Laravel\Webhooks\WebhookProcessor;
use ZapSMTP\WebhookEvent;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_laravel_test';

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(static function (): void {
            Route::zapsmtpWebhooks(); // @phpstan-ignore staticMethod.notFound (a macro)
        });
        Webhooks::route('/hooks/other')->name('other');
    }

    /** @param array<string, string> $headers */
    private function deliver(string $payload, ?string $signature = null, array $headers = [], string $uri = '/zapsmtp/webhooks'): Response
    {
        $t = time();
        $signature ??= "t=$t,v1=" . hash_hmac('sha256', "$t.$payload", self::SECRET);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ZAP_SIGNATURE' => $signature];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call('POST', $uri, [], [], [], $server, $payload)->baseResponse;
    }

    /** @param array<string, mixed>|null $json */
    private static function assertAnswer(Response $res, int $status, ?array $json = null): void
    {
        self::assertSame($status, $res->getStatusCode(), (string) $res->getContent());
        if ($json !== null) {
            self::assertSame($json, json_decode((string) $res->getContent(), true));
        }
    }

    public function testDispatchesAVerifiedEventInTheWebGroupWithoutCsrf(): void
    {
        Event::fake([Events\WebhookReceived::class, Events\EmailDelivered::class, Events\EmailBounced::class]);
        $payload = '{"id":"evt_1","type":"email.delivered","created_at":"2026-01-01T00:00:00Z","data":{"email_id":"019a","to":"jane@example.org","tags":{"category":"receipt"},"metadata":{"order_id":"A-1"}}}';
        self::assertAnswer($this->deliver($payload), 200, ['received' => 1, 'processed' => 1]);
        Event::assertDispatched(Events\WebhookReceived::class, static fn (Events\WebhookReceived $e) => $e->id === 'evt_1' && $e->type() === WebhookEvent::EMAIL_DELIVERED);
        Event::assertDispatched(Events\EmailDelivered::class, static fn (Events\EmailDelivered $e) => $e->emailId() === '019a'
            && $e->recipient() === 'jane@example.org' && $e->tags() === ['category' => 'receipt'] && $e->metadata() === ['order_id' => 'A-1']
            && $e->event->created_at === '2026-01-01T00:00:00Z');
        Event::assertNotDispatched(Events\EmailBounced::class);
        $route = Route::getRoutes()->getByName(Webhooks::ROUTE_NAME);
        self::assertNotNull($route);
        self::assertSame('zapsmtp/webhooks', $route->uri());
        self::assertContains(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, $route->excludedMiddleware());
    }

    public function testDeduplicatesOnTheEventIdAndRetriesAFailedOne(): void
    {
        Event::fake([Events\WebhookReceived::class]);
        $payload = '{"id":"evt_dup","type":"email.sent","data":{}}';
        self::assertAnswer($this->deliver($payload), 200, ['received' => 1, 'processed' => 1]);
        self::assertAnswer($this->deliver($payload), 200, ['received' => 1, 'processed' => 0]);
        Event::assertDispatchedTimes(Events\WebhookReceived::class, 1);

        $this->config()->set('zapsmtp.webhook.dedupe.enabled', false);
        self::assertAnswer($this->deliver($payload), 200, ['received' => 1, 'processed' => 1]);
    }

    public function testForgetsAnEventWhoseListenerFailed(): void
    {
        $calls = 0;
        Event::listen(Events\EmailSent::class, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('listener failed');
            }
        });
        $payload = '{"id":"evt_fail","type":"email.sent","data":{}}';
        $this->withoutExceptionHandling();
        try {
            $this->deliver($payload);
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertSame('listener failed', $e->getMessage());
        }
        self::assertAnswer($this->deliver($payload), 200, ['received' => 1, 'processed' => 1]);
        self::assertSame(2, $calls);
    }

    public function testHandlesABatchOneEventPerItem(): void
    {
        Event::fake([Events\WebhookReceived::class, Events\EmailClicked::class, Events\EmailReceived::class]);
        $batch = '[{"id":"evt_b1","type":"email.clicked","data":{"email_id":"e1","click":{"link":"https://acme.com/x","action":"confirm_order","unique":true}}},'
            . '{"type":"email.received","data":{"email_id":"in-1"}}]';
        self::assertAnswer($this->deliver($batch, null, ['Zap-Event-Id' => 'batch_1']), 200, ['received' => 2, 'processed' => 2]);
        Event::assertDispatched(Events\EmailClicked::class, static fn (Events\EmailClicked $e) => $e->link() === 'https://acme.com/x'
            && $e->action() === 'confirm_order' && $e->isUnique());
        Event::assertDispatched(Events\EmailReceived::class, static fn (Events\EmailReceived $e) => $e->id === 'email.received:in-1');
        self::assertAnswer($this->deliver('[]'), 200, ['message' => 'No events']);
    }

    public function testTakesTheEventIdFromTheHeaderForASingleEventWithoutOne(): void
    {
        Event::fake([Events\WebhookReceived::class, Events\EmailReceived::class]);
        $payload = '{"type":"email.received","created_at":"2026-01-01T00:00:00Z","data":{"email_id":"in-1"}}';
        self::assertAnswer($this->deliver($payload, null, ['Zap-Event-Id' => 'evt_in_1'], '/hooks/other'), 200);
        Event::assertDispatched(Events\EmailReceived::class, static fn (Events\EmailReceived $e) => $e->id === 'evt_in_1');
        self::assertAnswer($this->deliver('{"type":"thread.created","data":{}}'), 200, ['received' => 1, 'processed' => 1]);
        Event::assertDispatched(Events\WebhookReceived::class, static fn (Events\WebhookReceived $e) => $e->id === null && $e->emailId() === null);
    }

    public function testDispatchesOnlyWebhookReceivedForAnUnknownType(): void
    {
        Event::fake([Events\WebhookReceived::class]);
        self::assertAnswer($this->deliver('{"id":"evt_9","type":"domain.verified","data":{}}'), 200);
        self::assertAnswer($this->deliver('{"id":"evt_10","data":[]}'), 200);
        Event::assertDispatched(Events\WebhookReceived::class, static fn (Events\WebhookReceived $e) => $e->type() === '' && $e->data() === []);
        Event::assertDispatchedTimes(Events\WebhookReceived::class, 2);
    }

    public function testTypedEventsReadTheirData(): void
    {
        foreach (WebhookProcessor::EVENTS as $type => $class) {
            self::assertSame($type, (new $class(new WebhookEvent(['type' => $type])))->type());
        }
        $bounced = new Events\EmailBounced(new WebhookEvent(['data' => ['bounce' => ['type' => 'Permanent', 'message' => '5.1.1 no such user', 'suppressed' => true]]]));
        self::assertSame('Permanent', $bounced->bounceType());
        self::assertSame('5.1.1 no such user', $bounced->bounceMessage());
        self::assertTrue($bounced->isSuppressed());
        $opened = new Events\EmailOpened(new WebhookEvent(['data' => ['open' => ['machine' => true]]]));
        self::assertTrue($opened->isMachineOpen());
        $replied = new Events\EmailReplied(new WebhookEvent(['data' => ['reply' => ['type' => 'human', 'text' => 'Thanks']]]));
        self::assertSame('human', $replied->replyType());
        self::assertSame('Thanks', $replied->reply()['text']);
        $empty = new Events\EmailClicked(new WebhookEvent([]));
        self::assertNull($empty->link());
        self::assertFalse($empty->isUnique());
        self::assertSame([], $empty->tags());
    }

    public function testQueuesTheEventsWhenAsked(): void
    {
        $this->config()->set('zapsmtp.webhook.queue', ['enabled' => true, 'connection' => 'sync', 'queue' => 'webhooks']);
        Bus::fake();
        self::assertAnswer($this->deliver('{"id":"evt_q","type":"email.sent","data":{}}', null, ['Zap-Event-Id' => 'evt_q']), 202, ['received' => 1, 'queued' => true]);
        Bus::assertDispatched(ProcessWebhook::class, static fn (ProcessWebhook $j) => $j->events === [['id' => 'evt_q', 'type' => 'email.sent', 'data' => []]]
            && $j->deliveryId === 'evt_q' && $j->connection === 'sync' && $j->queue === 'webhooks');
    }

    public function testTheJobDispatchesTheEvents(): void
    {
        Event::fake([Events\EmailSent::class]);
        $processor = $this->app?->make(WebhookProcessor::class);
        self::assertInstanceOf(WebhookProcessor::class, $processor);
        (new ProcessWebhook([['id' => 'evt_job', 'type' => 'email.sent', 'data' => []]], null))->handle($processor);
        Event::assertDispatched(Events\EmailSent::class, static fn (Events\EmailSent $e) => $e->id === 'evt_job');
    }

    public function testRefusesABadSignatureWith400(): void
    {
        Event::fake([Events\WebhookReceived::class]);
        self::assertAnswer($this->deliver('{"id":"evt_1","type":"email.delivered"}', 't=1,v1=' . str_repeat('0', 64)), 400,
            ['message' => 'Webhook signature is too old (or from the future)']);
        self::assertAnswer($this->call('POST', '/zapsmtp/webhooks', [], [], [], [], '{}')->baseResponse, 400, ['message' => 'Missing Zap-Signature header']);
        Event::assertNotDispatched(Events\WebhookReceived::class);
    }

    public function testUsesTheConfiguredTolerance(): void
    {
        $this->config()->set('zapsmtp.webhook.tolerance', 1000);
        $t = time() - 900;
        $payload = '{"id":"evt_t","type":"email.sent","data":{}}';
        self::assertAnswer($this->deliver($payload, "t=$t,v1=" . hash_hmac('sha256', "$t.$payload", self::SECRET)), 200);
        $this->config()->set('zapsmtp.webhook.tolerance', '600');
        self::assertAnswer($this->deliver($payload, "t=$t,v1=" . hash_hmac('sha256', "$t.$payload", self::SECRET)), 400);
    }

    public function testNeedsASecret(): void
    {
        $this->config()->set('zapsmtp.webhook.secret', null);
        $this->withoutExceptionHandling();
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Set ZAPSMTP_WEBHOOK_SECRET');
        $this->deliver('{}');
    }

    public function testTheControllerRefusesAnUnverifiedRequest(): void
    {
        $controller = $this->app?->make(WebhookController::class);
        self::assertInstanceOf(WebhookController::class, $controller);
        $res = $this->app?->call($controller, ['request' => Request::create('/x', 'POST')]);
        self::assertInstanceOf(\Illuminate\Http\JsonResponse::class, $res);
        self::assertSame(400, $res->getStatusCode());
    }

    public function testTheDedupeKeyIsStoredInTheConfiguredCache(): void
    {
        $this->config()->set('cache.stores.zap', ['driver' => 'array']);
        $this->config()->set('zapsmtp.webhook.dedupe.store', 'zap');
        Event::fake([Events\WebhookReceived::class]);
        $this->deliver('{"id":"evt_store","type":"email.sent","data":{}}');
        $factory = $this->app?->make('cache');
        self::assertInstanceOf(\Illuminate\Contracts\Cache\Factory::class, $factory);
        $cache = $factory->store('zap');
        self::assertInstanceOf(Cache::class, $cache);
        self::assertTrue($cache->has('zapsmtp:webhook:' . hash('sha256', 'evt_store')));
    }
}
