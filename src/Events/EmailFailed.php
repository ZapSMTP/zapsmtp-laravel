<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.failed: rejected before sending. */
final class EmailFailed extends ZapSMTPWebhookEvent
{
}
