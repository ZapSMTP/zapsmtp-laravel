<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.received: an inbound email arrived (read it with ZapSMTP::emails()->receiving->get($e->emailId())). */
final class EmailReceived extends ZapSMTPWebhookEvent
{
}
