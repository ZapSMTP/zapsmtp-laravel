<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.unsubscribed: a recipient unsubscribed (the link or one-click). */
final class EmailUnsubscribed extends ZapSMTPWebhookEvent
{
}
