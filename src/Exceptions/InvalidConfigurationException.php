<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Exceptions;

/** A zapsmtp config value (config/zapsmtp.php, or the mailer's) is missing or invalid. */
final class InvalidConfigurationException extends \InvalidArgumentException
{
    public static function invalid(string $key, string $expected, mixed $value): self
    {
        return new self(sprintf('config zapsmtp.%s must be %s, %s given', $key, $expected, get_debug_type($value)));
    }
}
