<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use ZapSMTP\Laravel\Mail\InteractsWithZapSMTP;

/** A queued Mailable sent with a stored template. */
final class ReceiptMail extends Mailable implements ShouldQueue
{
    use InteractsWithZapSMTP;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $order)
    {
        $this->zapsmtpTemplate('receipt', ['order' => $order])
            ->zapsmtpTags(['category' => 'receipt'])
            ->zapsmtpTag('kind', 'order')
            ->zapsmtpMetadata(['order_id' => $order, 'paid' => true, 'total' => 12.5])
            ->zapsmtpStream('transactional')
            ->zapsmtpScheduleAt(new \DateTimeImmutable('2030-01-02 03:04:05', new \DateTimeZone('Europe/Kyiv')))
            ->zapsmtpTracking(opens: true, clicks: false)
            ->zapsmtpIdempotencyKey("receipt-$order");
        $this->metadata('source', 'shop');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ignored in template mode');
    }
}
