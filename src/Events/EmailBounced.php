<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.bounced: permanently rejected, or retries ran out. */
final class EmailBounced extends ZapSMTPWebhookEvent
{
    /** The bounce type, e.g. Permanent. */
    public function bounceType(): ?string
    {
        return $this->string('type', 'bounce');
    }

    /** The receiving server's message. */
    public function bounceMessage(): ?string
    {
        return $this->string('message', 'bounce');
    }

    /** Whether ZapSMTP put the address on the suppression list. */
    public function isSuppressed(): bool
    {
        return ($this->map('bounce')['suppressed'] ?? false) === true;
    }
}
