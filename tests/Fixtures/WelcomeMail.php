<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests\Fixtures;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class WelcomeMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('hello@acme.test', 'Acme, Inc.'),
            replyTo: [new Address('support@acme.test', 'Support')],
            subject: 'Welcome',
            tags: ['category:welcome', 'onboarding'],
            metadata: ['user_id' => '42'],
            using: [static function (Email $m): void {
                $m->text('Hi');
                $m->getHeaders()->addTextHeader('X-Campaign', 'autumn');
                $m->getHeaders()->addTextHeader('X-Zap-Internal', 'dropped');
                $m->addPart((new DataPart('PNG', 'logo.png', 'image/png'))->asInline());
            }],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Hi <img src="cid:logo.png"></p>');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(static fn () => '%PDF', 'terms.pdf')->withMime('application/pdf')];
    }
}
