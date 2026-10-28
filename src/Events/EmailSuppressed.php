<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.suppressed: not sent, the recipient is on the suppression list. */
final class EmailSuppressed extends ZapSMTPWebhookEvent
{
}
