<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests\Fixtures;

use Illuminate\Notifications\Notification;
use ZapSMTP\Laravel\Notifications\ZapSMTPMailMessage;

final class InvoicePaid extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): ZapSMTPMailMessage
    {
        return (new ZapSMTPMailMessage())
            ->subject('Invoice paid')
            ->line('Thanks!')
            ->zapsmtpTags(['category' => 'invoice'])
            ->zapsmtpMetadata(['invoice_id' => 'INV-7'])
            ->zapsmtpStream('broadcast');
    }
}
