<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.replied: someone answered an email sent with reply tracking. */
final class EmailReplied extends ZapSMTPWebhookEvent
{
    /**
     * The reply: email_id, from, subject, text, type (human, out_of_office, ...), forward, ...
     *
     * @return array<string, mixed>
     */
    public function reply(): array
    {
        return $this->map('reply');
    }

    /** The reply's type: human, unsubscribe, out_of_office, auto_reply, auto_generated, bounce or bulk. */
    public function replyType(): ?string
    {
        return $this->string('type', 'reply');
    }
}
