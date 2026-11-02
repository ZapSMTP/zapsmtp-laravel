<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.scheduled: an email was scheduled (or rescheduled) for later. */
final class EmailScheduled extends ZapSMTPWebhookEvent
{
}
