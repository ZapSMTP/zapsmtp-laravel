<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.forward_failed: a received email could not be forwarded. */
final class EmailForwardFailed extends ZapSMTPWebhookEvent
{
}
