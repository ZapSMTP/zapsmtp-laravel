<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.complained: the recipient marked it as spam. */
final class EmailComplained extends ZapSMTPWebhookEvent
{
}
