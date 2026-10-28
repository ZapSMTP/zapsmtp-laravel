<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use ZapSMTP\Client;
use ZapSMTP\Laravel\Facades\ZapSMTP;
use ZapSMTP\Laravel\Tests\Fixtures\ReceiptMail;
use ZapSMTP\Laravel\Testing\ZapSMTPFake;

final class FakeTest extends TestCase
{
    public function testRecordsMailAndApiSendsWithoutTheNetwork(): void
    {
        $this->transport(); // a mailer resolved before faking keeps no real client
        $fake = ZapSMTP::fake();
        ZapSMTP::assertNothingSent();

        Mail::to(new \Illuminate\Mail\Mailables\Address('jane@example.org', 'Jane'))->send(new ReceiptMail('A-1'));
        $sent = ZapSMTP::emails()->send(['from' => 'a@acme.test', 'to' => 'bob@example.org', 'subject' => 'Hi', 'text' => 'x']);
        self::assertSame('00000000-0000-7000-8000-000000000002', $sent->id);
        $batch = ZapSMTP::emails()->sendBatch([['to' => 'c@example.org'], ['to' => 'd@example.org']]);
        self::assertSame(['00000000-0000-7000-8000-000000000003', '00000000-0000-7000-8000-000000000004'], array_map(static fn ($d) => $d->id, $batch->data ?? []));
        self::assertSame([], ZapSMTP::domains()->list()->data);
        ZapSMTP::domains()->delete('d1');
        ZapSMTP::domains()->verify('d1');

        ZapSMTP::assertSentCount(4);
        ZapSMTP::assertSent();
        ZapSMTP::assertSent(static fn (array $e) => $e['to'] === 'bob@example.org', 1);
        ZapSMTP::assertSentTo('jane@example.org');
        ZapSMTP::assertSentTo('D@example.org');
        ZapSMTP::assertSentTemplate('receipt');
        ZapSMTP::assertSentTemplate('receipt', static fn (array $vars, array $email) => $vars['order'] === 'A-1' && $email['tags'] !== []);
        self::assertCount(4, ZapSMTP::sent());
        $app = $this->app;
        self::assertNotNull($app);
        self::assertSame($fake, $app->make(ZapSMTPFake::class));
        self::assertSame($fake->client(), $app->make(Client::class));
        self::assertCount(6, $fake->requests());
        self::assertSame(['method' => 'DELETE', 'path' => '/domains/d1', 'query' => '', 'body' => ''], $fake->requests()[4]);
    }

    public function testAssertionsFailWhenTheyShould(): void
    {
        ZapSMTP::fake();
        ZapSMTP::emails()->send(['to' => 'a@example.org', 'template' => ['id' => 'other']]);
        $failures = [
            static fn () => ZapSMTP::assertNothingSent(),
            static fn () => ZapSMTP::assertSentCount(2),
            static fn () => ZapSMTP::assertSent(static fn (array $e) => false),
            static fn () => ZapSMTP::assertSent(null, 2),
            static fn () => ZapSMTP::assertSentTo('b@example.org'),
            static fn () => ZapSMTP::assertSentTemplate('receipt'),
            static fn () => ZapSMTP::assertSentTemplate('other', static fn () => false),
        ];
        foreach ($failures as $i => $assertion) {
            try {
                $assertion();
                self::fail("assertion $i passed");
            } catch (AssertionFailedError $e) {
                self::assertNotSame("assertion $i passed", $e->getMessage());
            }
        }
    }

    public function testAssertionsNeedAFake(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Call ZapSMTP::fake() before its assertions');
        ZapSMTP::assertNothingSent();
    }

    public function testIgnoresRequestsThatAreNotSends(): void
    {
        $fake = new ZapSMTPFake();
        $fake->client()->templates->render('t1');
        $fake->client()->emails->sendBatch([]);
        self::assertSame([], $fake->sent());
    }
}
