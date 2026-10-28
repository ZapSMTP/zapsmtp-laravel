<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.delivered: the receiving server accepted the message. */
final class EmailDelivered extends ZapSMTPWebhookEvent
{
}
