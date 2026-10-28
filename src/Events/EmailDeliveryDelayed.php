<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.delivery_delayed: a temporary failure; ZapSMTP keeps retrying. */
final class EmailDeliveryDelayed extends ZapSMTPWebhookEvent
{
}
