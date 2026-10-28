<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Testing;

use PHPUnit\Framework\Assert;
use ZapSMTP\Client;

/**
 * ZapSMTP::fake(): the client (and the zapsmtp mailer) answer without the
 * network and remember what was sent, for assertions in the app's tests:
 *
 *     ZapSMTP::fake();
 *     $this->post('/orders', [...]);
 *     ZapSMTP::assertSent(fn (array $email) => $email['to'] === ['jane@example.org']);
 *     ZapSMTP::assertSentTemplate('receipt', fn (array $vars) => $vars['order'] === 'A-1');
 *
 * An email is the emails->send parameters (or one item of a batch), as the
 * API would get them: to is a list when the mailer sent it.
 */
final class ZapSMTPFake
{
    private readonly FakeHttpClient $http;

    private readonly Client $client;

    public function __construct()
    {
        $this->http = new FakeHttpClient();
        $this->client = new Client('zap_test_fake', ['http_client' => $this->http, 'base_url' => 'https://api.zapsmtp.test/v1', 'max_retries' => 0]);
    }

    /** The fake client the container gives out while faking. */
    public function client(): Client
    {
        return $this->client;
    }

    /**
     * Every API request made: method, path, query and decoded body.
     *
     * @return list<array{method: string, path: string, query: string, body: mixed}>
     */
    public function requests(): array
    {
        return $this->http->requests;
    }

    /**
     * Every email sent (emails->send and each item of emails->sendBatch).
     *
     * @return list<array<string, mixed>>
     */
    public function sent(): array
    {
        $emails = [];
        foreach ($this->http->requests as $r) {
            if ($r['method'] !== 'POST' || !\is_array($r['body'])) {
                continue;
            }
            if ($r['path'] === '/emails') {
                $emails[] = $r['body'];
            } elseif ($r['path'] === '/emails/batch') {
                foreach ($r['body'] as $email) {
                    $emails[] = \is_array($email) ? $email : [];
                }
            }
        }

        /** @var list<array<string, mixed>> */
        return $emails;
    }

    /**
     * Asserts an email was sent: any, or one $filter accepts (it gets the parameters).
     *
     * @param (callable(array<string, mixed>): bool)|null $filter
     */
    public function assertSent(?callable $filter = null, int $times = 0): void
    {
        $matches = array_filter($this->sent(), static fn (array $e) => $filter === null || $filter($e));
        if ($times > 0) {
            Assert::assertCount($times, $matches, "Expected $times matching ZapSMTP email(s), got " . \count($matches) . '.');

            return;
        }
        Assert::assertNotEmpty($matches, $filter === null ? 'No ZapSMTP email was sent.' : 'No ZapSMTP email matched.');
    }

    /** Asserts an email was sent to an address (To, Cc or Bcc). */
    public function assertSentTo(string $address): void
    {
        $address = strtolower($address);
        $this->assertSent(static function (array $e) use ($address): bool {
            foreach (['to', 'cc', 'bcc'] as $field) {
                foreach ((array) ($e[$field] ?? []) as $a) {
                    if (\is_string($a) && (strtolower($a) === $address || str_ends_with(strtolower($a), "<$address>"))) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    /**
     * Asserts an email was sent with a stored template; $filter gets its variables.
     *
     * @param (callable(array<string, mixed>, array<string, mixed>): bool)|null $filter gets the variables and the email
     */
    public function assertSentTemplate(string $id, ?callable $filter = null): void
    {
        $matches = array_filter($this->sent(), static function (array $e) use ($id, $filter): bool {
            $template = $e['template'] ?? null;
            if (!\is_array($template) || ($template['id'] ?? null) !== $id) {
                return false;
            }
            /** @var array<string, mixed> $vars */
            $vars = \is_array($template['variables'] ?? null) ? $template['variables'] : [];

            return $filter === null || $filter($vars, $e);
        });
        Assert::assertNotEmpty($matches, "No ZapSMTP email was sent with the template \"$id\"" . ($filter === null ? '.' : ' that matched.'));
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent(), \count($this->sent()) . ' ZapSMTP email(s) were sent.');
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent(), "Expected $count ZapSMTP email(s), got " . \count($this->sent()) . '.');
    }
}
