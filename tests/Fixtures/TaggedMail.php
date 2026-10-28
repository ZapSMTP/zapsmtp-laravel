<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use ZapSMTP\Laravel\Mail\InteractsWithZapSMTP;

final class TaggedMail extends Mailable
{
    use InteractsWithZapSMTP;

    public function __construct()
    {
        $this->zapsmtpTag('flow', 'signup');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tagged');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Tagged</p>');
    }
}
