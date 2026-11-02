<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.opened_unique: the first open of an email by its recipient. */
final class EmailOpenedUnique extends ZapSMTPWebhookEvent
{
}
