<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\RawMessage;
use ZapSMTP\Client;
use ZapSMTP\Exception\ValidationException;
use ZapSMTP\Laravel\Exceptions\InvalidConfigurationException;
use ZapSMTP\Laravel\Mail\MessageOptions;
use ZapSMTP\Laravel\Mail\ZapSMTPTransport;
use ZapSMTP\Laravel\Tests\Fixtures\InvoicePaid;
use ZapSMTP\Laravel\Tests\Fixtures\ReceiptMail;
use ZapSMTP\Laravel\Tests\Fixtures\WelcomeMail;
use ZapSMTP\Laravel\Tests\Support\RecordingHttpClient;

final class MailTransportTest extends TestCase
{
    public function testSendsAMailableThroughTheApi(): void
    {
        $http = new RecordingHttpClient(new Response(200, ['X-Request-Id' => 'req_1'], '{"id":"019a-email"}'));
        $this->fakeApi($http);
        Event::fake([MessageSent::class]);

        $sent = Mail::to([new Address('jane@example.org', 'Jane Doe'), 'john@example.org'])
            ->cc('cc@example.org')
            ->bcc('bcc@example.org')
            ->send(new WelcomeMail());

        self::assertInstanceOf(SentMessage::class, $sent);
        self::assertSame('019a-email', $sent->getMessageId());
        self::assertSame('019a-email', ZapSMTPTransport::emailId($sent));
        Event::assertDispatched(MessageSent::class, static fn (MessageSent $e) => $e->sent->getMessageId() === '019a-email');
        self::assertCount(1, $http->requests);
        $req = $http->requests[0];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.test/v1/emails', (string) $req->getUri());
        self::assertNotSame('', $req->getHeaderLine('Idempotency-Key'));
        self::assertSame([
            'from' => '"Acme, Inc." <hello@acme.test>',
            'to' => ['Jane Doe <jane@example.org>', 'john@example.org'],
            'cc' => ['cc@example.org'],
            'bcc' => ['bcc@example.org'],
            'reply_to' => ['Support <support@acme.test>'],
            'subject' => 'Welcome',
            'html' => '<p>Hi <img src="cid:logo.png"></p>',
            'text' => 'Hi',
            'headers' => ['X-Campaign' => 'autumn'],
            'attachments' => [
                ['filename' => 'logo.png', 'content' => base64_encode('PNG'), 'content_type' => 'image/png', 'content_id' => 'logo.png'],
                ['filename' => 'terms.pdf', 'content' => base64_encode('%PDF'), 'content_type' => 'application/pdf'],
            ],
            'tags' => [['name' => 'category', 'value' => 'welcome'], ['name' => 'onboarding', 'value' => 'true']],
            'metadata' => ['user_id' => '42'],
        ], $http->body());
    }

    public function testSendsAQueuedTemplateMailableWithItsOptions(): void
    {
        $this->config()->set('queue.default', 'sync');
        $http = new RecordingHttpClient();
        $this->fakeApi($http);
        Mail::to('jane@example.org')->queue(new ReceiptMail('A-1'));

        self::assertCount(1, $http->requests);
        self::assertSame('receipt-A-1', $http->requests[0]->getHeaderLine('Idempotency-Key'));
        $body = $http->body();
        self::assertSame(['id' => 'receipt', 'variables' => ['order' => 'A-1']], $body['template']);
        self::assertArrayNotHasKey('subject', $body);
        self::assertArrayNotHasKey('html', $body);
        self::assertArrayNotHasKey('text', $body);
        self::assertArrayNotHasKey('headers', $body);
        self::assertSame([['name' => 'category', 'value' => 'receipt'], ['name' => 'kind', 'value' => 'order']], $body['tags']);
        self::assertSame(['source' => 'shop', 'order_id' => 'A-1', 'paid' => 'true', 'total' => '12.5'], $body['metadata']);
        self::assertSame('transactional', $body['stream']);
        self::assertSame('2030-01-02T01:04:05Z', $body['scheduled_at']);
        self::assertSame(['opens' => true, 'clicks' => false], $body['tracking']);
        self::assertSame('Acme <hello@acme.test>', $body['from']);
    }

    public function testATemplateCanKeepItsOwnSubject(): void
    {
        $http = new RecordingHttpClient();
        $this->fakeApi($http);
        $mail = (new ReceiptMail('B-2'))->zapsmtpTemplate('receipt', ['order' => 'B-2'], 'Your receipt');
        Mail::to('jane@example.org')->send($mail);
        self::assertSame('Your receipt', $http->body()['subject']);
        self::assertSame('receipt', $mail->zapsmtpOptions()['template']['id'] ?? null);
    }

    public function testSendsNotificationsWithZapSMTPMailMessage(): void
    {
        $http = new RecordingHttpClient();
        $this->fakeApi($http);
        Notification::send(new AnonymousNotifiable(), new InvoicePaid()); // no route: nothing sent
        (new AnonymousNotifiable())->route('mail', 'jane@example.org')->notify(new InvoicePaid());
        self::assertCount(1, $http->requests);
        $body = $http->body();
        self::assertSame(['jane@example.org'], $body['to']);
        self::assertSame('Invoice paid', $body['subject']);
        self::assertIsString($body['html']);
        self::assertStringContainsString('Thanks!', $body['html']);
        self::assertSame([['name' => 'category', 'value' => 'invoice']], $body['tags']);
        self::assertSame(['invoice_id' => 'INV-7'], $body['metadata']);
        self::assertSame('broadcast', $body['stream']);
    }

    public function testEmbedsInlineImagesByTheirContentId(): void
    {
        $http = new RecordingHttpClient();
        $this->fakeApi($http);
        $path = sys_get_temp_dir() . '/zap-logo-' . bin2hex(random_bytes(3)) . '.png';
        file_put_contents($path, 'PNG');
        try {
            Mail::send([], [], static function (Message $m) use ($path): void {
                $cid = $m->embed($path);
                $m->to('jane@example.org')->subject('Embedded')->html('<img src="' . $cid . '">');
                $m->getSymfonyMessage()->addPart((new DataPart('GIF', 'pixel.gif', 'image/gif'))->asInline()->setContentId('px@acme'));
            });
        } finally {
            unlink($path);
        }
        $body = $http->body();
        self::assertIsString($body['html']);
        /** @var list<array<string, string>> $attachments */
        $attachments = $body['attachments'];
        self::assertStringContainsString('cid:' . $attachments[0]['content_id'], $body['html']);
        self::assertSame('image/png', $attachments[0]['content_type']);
        self::assertSame('px@acme', $attachments[1]['content_id']);
        self::assertArrayNotHasKey('text', $body);
    }

    public function testSendsTextOnlyAndBccOnlyMail(): void
    {
        $http = new RecordingHttpClient();
        $this->fakeApi($http);
        Mail::raw('Plain text', static function (Message $m): void {
            $m->bcc('hidden@example.org')->subject('Bcc only');
        });
        $body = $http->body();
        self::assertSame('Plain text', $body['text']);
        self::assertSame(['hidden@example.org'], $body['bcc']);
        self::assertArrayNotHasKey('to', $body);
        self::assertArrayNotHasKey('html', $body);
    }

    public function testUsesTheEnvelopeSenderWithoutFromAndReadsStreamedBodies(): void
    {
        $http = new RecordingHttpClient();
        $this->fakeApi($http);
        $transport = $this->transport();
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, '<b>streamed</b>');
        rewind($stream);
        $email = (new Email())->sender('bounce@acme.test')->to('y@example.org')->html($stream);
        $transport->send($email, new Envelope(new MimeAddress('bounce@acme.test'), [new MimeAddress('y@example.org')]));
        self::assertSame('<b>streamed</b>', $http->body()['html']);
        self::assertSame('bounce@acme.test', $http->body()['from']);
        self::assertSame('zapsmtp', (string) $transport);
        self::assertNull(ZapSMTPTransport::emailId(new \Symfony\Component\Mailer\SentMessage(new RawMessage('x'), new Envelope(new MimeAddress('a@acme.test'), [new MimeAddress('b@example.org')]))));
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('ZapSMTP sends structured messages');
        $transport->send(new RawMessage('raw'), new Envelope(new MimeAddress('a@acme.test'), [new MimeAddress('b@example.org')]));
    }

    public function testKeepsTheEmailIdOffWhenTheApiGivesNone(): void
    {
        $this->fakeApi(new RecordingHttpClient(new Response(200, [], '{}')));
        $sent = Mail::raw('x', static fn (Message $m) => $m->to('a@example.org'));
        self::assertNotNull($sent);
        self::assertNull(ZapSMTPTransport::emailId($sent));
    }

    public function testTurnsApiErrorsIntoTransportExceptions(): void
    {
        $this->fakeApi(new RecordingHttpClient(RecordingHttpClient::json(['error' => ['code' => 'invalid_parameter', 'message' => 'invalid to address', 'type' => 'invalid_request_error']], 422)));
        try {
            Mail::raw('x', static fn (Message $m) => $m->to('bad@example.org'));
            self::fail('no exception');
        } catch (TransportException $e) {
            self::assertSame('ZapSMTP: invalid to address', $e->getMessage());
            self::assertSame(422, $e->getCode());
            self::assertInstanceOf(ValidationException::class, $e->getPrevious());
        }
    }

    public function testAMailerCanCarryItsOwnKey(): void
    {
        $this->config()->set('mail.mailers.other', ['transport' => 'zapsmtp', 'key' => 'zap_other_key']);
        $transport = $this->transport('other');
        self::assertInstanceOf(ZapSMTPTransport::class, $transport);
        $client = (new \ReflectionProperty($transport, 'client'))->getValue($transport);
        self::assertInstanceOf(Client::class, $client);
        self::assertNotSame($this->app?->make(Client::class), $client);
        $this->config()->set('mail.mailers.broken', ['transport' => 'zapsmtp', 'key' => '']);
        $this->expectException(InvalidConfigurationException::class);
        $this->transport('broken');
    }

    public function testValidatesOptions(): void
    {
        $mail = new ReceiptMail('C');
        foreach ([
            static fn () => $mail->zapsmtpTag('bad name', 'x'),
            static fn () => $mail->zapsmtpStream('bulk'),
            static fn () => $mail->zapsmtpIdempotencyKey(''),
            static fn () => $mail->zapsmtpIdempotencyKey("  \t"),
            static fn () => $mail->zapsmtpTemplate(''),
        ] as $call) {
            try {
                $call();
                self::fail('no exception');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('ZapSMTP', $e->getMessage());
            }
        }
        self::assertSame('in 2 hours', $mail->zapsmtpScheduleAt('in 2 hours')->zapsmtpOptions()['scheduled_at'] ?? null);
    }

    public function testReadsOptionsDefensively(): void
    {
        $email = new Email();
        self::assertSame([], MessageOptions::read($email->getHeaders()));
        MessageOptions::attach($email, []);
        self::assertFalse($email->getHeaders()->has(MessageOptions::HEADER));
        $email->getHeaders()->addTextHeader(MessageOptions::HEADER, '%%%');
        self::assertSame([], MessageOptions::read($email->getHeaders()));
        MessageOptions::attach($email, ['stream' => 'broadcast']);
        MessageOptions::attach($email->html('<p>x</p>'), ['template' => ['id' => 't', 'variables' => []]]);
        self::assertSame(['stream' => 'broadcast', 'template' => ['id' => 't', 'variables' => []]], MessageOptions::read($email->getHeaders()));
        self::assertNull($email->getTextBody());
        $bare = new Email();
        MessageOptions::attach($bare, ['template' => ['id' => 't', 'variables' => []]]);
        self::assertSame('', $bare->getTextBody());
    }

    public function testAMailableWithTheTraitKeepsItsOwnView(): void
    {
        $http = new RecordingHttpClient();
        $this->fakeApi($http);
        Mail::to('jane@example.org')->send(new \ZapSMTP\Laravel\Tests\Fixtures\TaggedMail());
        $body = $http->body();
        self::assertSame('<p>Tagged</p>', $body['html']);
        self::assertSame([['name' => 'flow', 'value' => 'signup']], $body['tags']);
    }

    public function testTheTransportRefusesAnEmptyIdempotencyKeyBeforeSending(): void
    {
        $http = new RecordingHttpClient();
        $this->fakeApi($http);
        $email = (new Email())->from('a@acme.test')->to('b@example.org')->subject('s')->text('t');
        MessageOptions::attach($email, ['idempotency_key' => ' ']);
        try {
            $this->transport()->send($email);
            self::fail('no exception');
        } catch (TransportException $e) {
            self::assertSame('ZapSMTP: idempotency_key must not be empty', $e->getMessage());
            self::assertInstanceOf(ValidationException::class, $e->getPrevious());
        }
        self::assertCount(0, $http->requests);
    }
}
