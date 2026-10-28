<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use ZapSMTP\Client;
use ZapSMTP\Laravel\Exceptions\InvalidConfigurationException;
use ZapSMTP\Webhook;

/**
 * The zapsmtp config, validated: every getter throws
 * InvalidConfigurationException naming the key when its value is wrong.
 * Read fresh from the config repository each time (safe under Octane).
 */
final class Config
{
    /** @param array<array-key, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function from(Repository $config): self
    {
        $values = $config->get('zapsmtp', []);

        return new self(\is_array($values) ? $values : []);
    }

    /** A client for these settings; $apiKey overrides the configured key. */
    public function client(?string $apiKey = null): Client
    {
        $options = ['timeout' => $this->number('timeout', 60.0), 'max_retries' => $this->integer('max_retries', 2, 0)];
        $baseUrl = $this->baseUrl();
        if ($baseUrl !== null) {
            $options['base_url'] = $baseUrl;
        }

        return new Client($apiKey ?? $this->apiKey(), $options);
    }

    /** The API key; null when unset (the client then reads ZAP_API_KEY). */
    public function apiKey(): ?string
    {
        return $this->optionalString('api_key');
    }

    public function baseUrl(): ?string
    {
        $url = $this->optionalString('base_url');
        if ($url !== null && (filter_var($url, \FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1)) {
            throw InvalidConfigurationException::invalid('base_url', 'an http(s) URL', $url);
        }

        return $url;
    }

    /** The webhook signing secret (whsec_...). */
    public function webhookSecret(): string
    {
        $secret = $this->optionalString('webhook.secret');
        if ($secret === null) {
            throw new InvalidConfigurationException('Set ZAPSMTP_WEBHOOK_SECRET (config zapsmtp.webhook.secret) to receive ZapSMTP webhooks');
        }

        return $secret;
    }

    public function webhookTolerance(): int
    {
        return $this->integer('webhook.tolerance', Webhook::DEFAULT_TOLERANCE, 1);
    }

    public function dedupeEnabled(): bool
    {
        return $this->boolean('webhook.dedupe.enabled', true);
    }

    public function dedupeStore(): ?string
    {
        return $this->optionalString('webhook.dedupe.store');
    }

    /** Seconds an event id is remembered. */
    public function dedupeTtl(): int
    {
        return $this->integer('webhook.dedupe.ttl', 86400, 1);
    }

    public function queueEnabled(): bool
    {
        return $this->boolean('webhook.queue.enabled', false);
    }

    public function queueConnection(): ?string
    {
        return $this->optionalString('webhook.queue.connection');
    }

    public function queueName(): ?string
    {
        return $this->optionalString('webhook.queue.queue');
    }

    public function suppressionEnabled(): bool
    {
        return $this->boolean('suppression.enabled', false);
    }

    /** @return class-string<Model> */
    public function suppressionModel(): string
    {
        $model = $this->get('suppression.model');
        if (!\is_string($model) || !is_subclass_of($model, Model::class)) {
            throw InvalidConfigurationException::invalid('suppression.model', 'an Eloquent model class', $model);
        }

        return $model;
    }

    public function suppressionEmailColumn(): string
    {
        return $this->requiredString('suppression.email_column', 'email');
    }

    public function suppressionColumn(): string
    {
        return $this->requiredString('suppression.column', 'email_suppressed_at');
    }

    /** @return list<string> the webhook event types that mark a recipient */
    public function suppressionEvents(): array
    {
        $events = $this->get('suppression.events', ['email.bounced', 'email.complained', 'email.suppressed']);
        if (!\is_array($events) || array_filter($events, static fn ($e) => !\is_string($e)) !== []) {
            throw InvalidConfigurationException::invalid('suppression.events', 'a list of event types', $events);
        }

        /** @var list<string> */
        return array_values($events);
    }

    private function get(string $key, mixed $default = null): mixed
    {
        $v = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!\is_array($v) || !\array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }

        return $v ?? $default;
    }

    private function optionalString(string $key): ?string
    {
        $v = $this->get($key);
        if ($v !== null && !\is_string($v)) {
            throw InvalidConfigurationException::invalid($key, 'a string', $v);
        }

        return $v === '' ? null : $v;
    }

    private function requiredString(string $key, string $default): string
    {
        return $this->optionalString($key) ?? $default;
    }

    private function number(string $key, float $default): float
    {
        $v = $this->get($key, $default);
        if (!is_numeric($v) || (float) $v <= 0) {
            throw InvalidConfigurationException::invalid($key, 'a number above 0', $v);
        }

        return (float) $v;
    }

    private function integer(string $key, int $default, int $min): int
    {
        $v = $this->get($key, $default);
        if (\is_string($v) && preg_match('/^-?\d+$/', $v) === 1) {
            $v = (int) $v;
        }
        if (!\is_int($v) || $v < $min) {
            throw InvalidConfigurationException::invalid($key, "an integer of at least $min", $v);
        }

        return $v;
    }

    private function boolean(string $key, bool $default): bool
    {
        $v = $this->get($key, $default);
        $b = \is_bool($v) ? $v : filter_var($v, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
        if ($b === null) {
            throw InvalidConfigurationException::invalid($key, 'true or false', $v);
        }

        return $b;
    }
}
