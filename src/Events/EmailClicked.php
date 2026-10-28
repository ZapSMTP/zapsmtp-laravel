<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Events;

/** email.clicked: a tracked link was clicked. */
final class EmailClicked extends ZapSMTPWebhookEvent
{
    /** The link that was clicked. */
    public function link(): ?string
    {
        return $this->string('link', 'click');
    }

    /** The click action of the link (a link marked with one), else null. */
    public function action(): ?string
    {
        return $this->string('action', 'click');
    }

    /** Whether this is the recipient's first click of this action in this email. */
    public function isUnique(): bool
    {
        return ($this->map('click')['unique'] ?? false) === true;
    }
}
