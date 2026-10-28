<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use ZapSMTP\Laravel\Facades\ZapSMTP;
use ZapSMTP\Laravel\Mail\ZapSMTPTransport;
use ZapSMTP\Laravel\Tests\Support\RecordingHttpClient;

/** Delivery isolation (found by Codex review). */
final class DeliveryTest extends TestCase
{
    public function testAnExplicitEnvelopeIsWhoReceivesIt(): void
    {
        $http = new RecordingHttpClient();
        $this->fakeApi($http);
        $email = (new Email())->from('billing@acme.test')->to('customer@example.org')->cc('manager@example.org')
            ->bcc('audit@example.org')->subject('Invoice')->text('private');
        $this->transport()->send($email, new Envelope(new Address('billing@acme.test'), [new Address('sink@staging.test', 'Sink')]));
        $body = $http->body();
        self::assertSame(['sink@staging.test'], $body['to']); // Symfony keeps envelope addresses without names
        self::assertArrayNotHasKey('cc', $body);
        self::assertArrayNotHasKey('bcc', $body);
        self::assertStringNotContainsString('customer@', (string) json_encode($body));

        // the same recipients in another order or case: the headers as they are
        $this->transport()->send($email, new Envelope(new Address('billing@acme.test'),
            [new Address('AUDIT@example.org'), new Address('customer@example.org'), new Address('manager@example.org')]));
        $body = $http->body(1);
        self::assertSame(['customer@example.org'], $body['to']);
        self::assertSame(['manager@example.org'], $body['cc']);
        self::assertSame(['audit@example.org'], $body['bcc']);
    }

    public function testTheFakeAlsoTakesMailersWithTheirOwnKey(): void
    {
        $this->config()->set('mail.mailers.marketing', ['transport' => 'zapsmtp', 'key' => 'zap_marketing_key']);
        $fake = ZapSMTP::fake();
        $transport = $this->transport('marketing');
        self::assertInstanceOf(ZapSMTPTransport::class, $transport);
        self::assertSame($fake->client(), (new \ReflectionProperty($transport, 'client'))->getValue($transport));
        Mail::mailer('marketing')->raw('hi', static fn (\Illuminate\Mail\Message $m) => $m->to('jane@example.org'));
        ZapSMTP::assertSentTo('jane@example.org');
    }
}
