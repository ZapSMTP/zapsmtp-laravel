<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.opened: the tracking pixel loaded. */
final class EmailOpened extends ZapSMTPWebhookEvent
{
    /** Whether Apple Mail Privacy Protection (a machine) loaded the pixel. */
    public function isMachineOpen(): bool
    {
        return ($this->map('open')['machine'] ?? false) === true;
    }
}
