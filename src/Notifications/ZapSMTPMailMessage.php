<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;
use ZapSMTP\Laravel\Mail\HasZapSMTPOptions;
use ZapSMTP\Laravel\Mail\MessageOptions;

/**
 * A notification MailMessage with ZapSMTP options:
 *
 *     public function toMail(object $notifiable): ZapSMTPMailMessage
 *     {
 *         return (new ZapSMTPMailMessage())
 *             ->subject('Your invoice')
 *             ->line('Thanks for your order.')
 *             ->zapsmtpTags(['category' => 'invoice'])
 *             ->zapsmtpMetadata(['invoice_id' => $this->invoice->id]);
 *     }
 */
class ZapSMTPMailMessage extends MailMessage
{
    use HasZapSMTPOptions;

    public function __construct()
    {
        $this->withSymfonyMessage(function (Email $email): void {
            MessageOptions::attach($email, $this->zapsmtpOptions);
        });
    }
}
