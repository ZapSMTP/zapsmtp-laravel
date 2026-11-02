<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.clicked_unique: the first click in an email by its recipient. */
final class EmailClickedUnique extends ZapSMTPWebhookEvent
{
}
