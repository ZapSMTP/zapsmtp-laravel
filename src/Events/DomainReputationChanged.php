<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** domain.reputation_changed: a sending domain was listed on or removed from a blocklist. */
final class DomainReputationChanged extends ZapSMTPWebhookEvent
{
}
