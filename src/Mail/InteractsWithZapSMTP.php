<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Mail;

use Illuminate\Mail\Message;

/**
 * ZapSMTP options for a Mailable; they survive queueing.
 *
 *     class Receipt extends Mailable
 *     {
 *         use InteractsWithZapSMTP;
 *
 *         public function __construct(Order $order)
 *         {
 *             $this->zapsmtpTemplate('receipt', ['order' => $order->number])
 *                 ->zapsmtpTags(['category' => 'receipt'])
 *                 ->zapsmtpIdempotencyKey("receipt-{$order->id}");
 *         }
 *     }
 */
trait InteractsWithZapSMTP
{
    use HasZapSMTPOptions;

    /**
     * A Mailable that sends a stored template needs no view of its own.
     *
     * @return mixed
     */
    protected function buildView()
    {
        if (isset($this->zapsmtpOptions['template']) && $this->html === null && $this->view === null
            && $this->textView === null && $this->markdown === null) {
            return ['raw' => ''];
        }

        return parent::buildView();
    }

    /**
     * Adds the ZapSMTP options to the message while Laravel builds it.
     *
     * @param Message $message
     *
     * @return $this
     */
    protected function buildMetadata($message)
    {
        parent::buildMetadata($message);
        MessageOptions::attach($message->getSymfonyMessage(), $this->zapsmtpOptions);

        return $this;
    }
}
