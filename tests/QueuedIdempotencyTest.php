<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Exception\TransportException;
use ZapSMTP\Laravel\Mail\QueuedSendContext;
use ZapSMTP\Laravel\Tests\Fixtures\InvoicePaid;
use ZapSMTP\Laravel\Tests\Fixtures\ReceiptMail;
use ZapSMTP\Laravel\Tests\Fixtures\TaggedMail;
use ZapSMTP\Laravel\Tests\Support\RecordingHttpClient;

/** Queued mail keeps its Idempotency-Key when the job is retried. */
final class QueuedIdempotencyTest extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make('config');
        \assert($config instanceof \Illuminate\Contracts\Config\Repository);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('queue.default', 'database');
        $config->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'connection' => 'testing']);
        $config->set('queue.failed.driver', 'null');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('jobs', static function (Blueprint $t): void {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
    }

    private function work(): void
    {
        $this->command('queue:work', ['--once' => true, '--tries' => 3, '--backoff' => 0, '--stop-when-empty' => true])->run();
    }

    private static function failure(): Response
    {
        return RecordingHttpClient::json(['error' => ['code' => 'internal_error', 'message' => 'boom']], 500);
    }

    public function testARetriedQueuedMailableReusesItsKey(): void
    {
        // attempt 1: the send and its two SDK retries fail; attempt 2 succeeds
        $http = new RecordingHttpClient(self::failure(), self::failure(), self::failure(), new Response(200, [], '{"id":"e1"}'));
        $this->fakeApi($http);
        Mail::to('jane@example.org')->queue(new TaggedMail());
        $this->work();
        $this->work();
        self::assertCount(4, $http->requests);
        $keys = array_map(static fn ($r) => $r->getHeaderLine('Idempotency-Key'), $http->requests);
        self::assertCount(1, array_unique($keys));
        self::assertStringStartsWith('laravel-', $keys[0]);

        // another queued email gets another key
        Mail::to('jane@example.org')->queue(new TaggedMail());
        $this->work();
        self::assertNotSame($keys[0], $http->requests[4]->getHeaderLine('Idempotency-Key'));
    }

    public function testQueuedNotificationsGetAStableKeyAndTheOverrideWins(): void
    {
        $http = new RecordingHttpClient(self::failure(), self::failure(), self::failure(), new Response(200, [], '{"id":"e1"}'));
        $this->fakeApi($http);
        (new AnonymousNotifiable())->route('mail', 'jane@example.org')->notify(new QueuedInvoicePaid());
        $this->work();
        $this->work();
        self::assertCount(4, $http->requests);
        self::assertSame($http->requests[0]->getHeaderLine('Idempotency-Key'), $http->requests[3]->getHeaderLine('Idempotency-Key'));

        Mail::to('jane@example.org')->queue(new ReceiptMail('A-9'));
        $this->work();
        self::assertSame('receipt-A-9', $http->requests[4]->getHeaderLine('Idempotency-Key'));
    }

    public function testARetryOfAnEmailAnEarlierAttemptSentIsNotSentAgain(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(['error' => ['code' => 'idempotency_key_reused', 'message' => 'key reused']], 409));
        $this->fakeApi($http);
        Mail::to('jane@example.org')->queue(new TaggedMail());
        $this->work();
        self::assertCount(1, $http->requests);
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->count()); // done, not failed
    }

    public function testOutsideAJobOrWithItsOwnKeyAConflictIsAnError(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(['error' => ['code' => 'idempotency_key_reused', 'message' => 'key reused']], 409));
        $this->fakeApi($http);
        foreach ([new TaggedMail(), (new TaggedMail())->zapsmtpIdempotencyKey('own-key')] as $mail) {
            try {
                Mail::to('jane@example.org')->send($mail);
                self::fail('no exception');
            } catch (TransportException $e) {
                self::assertSame('ZapSMTP: key reused', $e->getMessage());
            }
        }
        // a send outside a job gets a fresh random key each time
        self::assertNotSame($http->requests[0]->getHeaderLine('Idempotency-Key'), '');
        $this->config()->set('queue.default', 'sync');
        $context = $this->app?->make(QueuedSendContext::class);
        self::assertInstanceOf(QueuedSendContext::class, $context);
        self::assertNull($context->nextKey(['a@example.org'], 's'));
    }

    public function testAJobWithoutAUuidGetsNoKey(): void
    {
        $context = new QueuedSendContext();
        $job = $this->createStub(\Illuminate\Contracts\Queue\Job::class);
        $job->method('uuid')->willReturn(null);
        $context->start($job);
        self::assertNull($context->nextKey(['a@example.org'], 's'));
        $uuidJob = $this->createStub(\Illuminate\Contracts\Queue\Job::class);
        $uuidJob->method('uuid')->willReturn('u-1');
        $context->start($uuidJob);
        $first = $context->nextKey(['b@example.org', 'a@example.org'], 's');
        $context->start($uuidJob);
        self::assertSame($first, $context->nextKey(['a@example.org', 'b@example.org'], 's'));
        self::assertNotSame($first, $context->nextKey(['a@example.org', 'b@example.org'], 's'));
        $context->stop();
        self::assertNull($context->nextKey(['a@example.org'], 's'));
    }
}

final class QueuedInvoicePaid extends \Illuminate\Notifications\Notification implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): \ZapSMTP\Laravel\Notifications\ZapSMTPMailMessage
    {
        return (new InvoicePaid())->toMail($notifiable);
    }
}
