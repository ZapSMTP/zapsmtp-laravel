<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Mail;

use Illuminate\Contracts\Queue\Job;

/**
 * The queued job being processed, so mail it sends gets an Idempotency-Key
 * that stays the same when the job is retried: a retry after a timeout or a
 * failure never sends an email twice.
 *
 * The key is derived from the job's uuid (kept across retries), the send's
 * position in the job, its recipients and subject. Set by the queue events
 * (JobProcessing / JobProcessed / JobFailed / JobExceptionOccurred); one per
 * application, reset after each job.
 *
 * @internal
 */
final class QueuedSendContext
{
    private ?string $jobUuid = null;

    private int $sends = 0;

    public function start(Job $job): void
    {
        $uuid = $job->uuid();
        $this->jobUuid = $uuid === null || $uuid === '' ? null : $uuid;
        $this->sends = 0;
    }

    public function stop(): void
    {
        $this->jobUuid = null;
        $this->sends = 0;
    }

    /**
     * The key of the next send in the current job; null outside a job.
     *
     * @param list<string> $recipients
     */
    public function nextKey(array $recipients, string $subject): ?string
    {
        if ($this->jobUuid === null) {
            return null;
        }
        sort($recipients);

        return 'laravel-' . hash('sha256', implode("\n", [$this->jobUuid, (string) $this->sends++, implode(',', $recipients), $subject]));
    }
}
