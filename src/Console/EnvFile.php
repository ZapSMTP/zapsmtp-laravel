<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Console;

/**
 * Adds variables to a .env file without touching the ones it has.
 *
 * @internal
 */
final class EnvFile
{
    public const ADDED = 'added';
    public const PRESENT = 'present';
    public const DIFFERENT = 'different';
    public const NO_FILE = 'no file';

    public function __construct(private readonly string $path)
    {
    }

    /** The value of a variable, or null when it is not set. */
    public function get(string $name): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }
        $content = (string) file_get_contents($this->path);
        if (preg_match('/^[ \t]*(?:export[ \t]+)?' . preg_quote($name, '/') . '[ \t]*=[ \t]*(.*)$/m', $content, $m) !== 1) {
            return null;
        }

        return trim(trim($m[1]), '"\'');
    }

    /**
     * Sets a variable that is absent (or, with $fillEmpty, empty); never
     * changes a value. Returns what it found: ADDED, PRESENT (the same value),
     * DIFFERENT (another value, left alone) or NO_FILE.
     */
    public function add(string $name, string $value, bool $fillEmpty = false): string
    {
        if (!is_file($this->path)) {
            return self::NO_FILE;
        }
        $current = $this->get($name);
        if ($current !== null && !($fillEmpty && $current === '')) {
            return $current === $value ? self::PRESENT : self::DIFFERENT;
        }
        $content = (string) file_get_contents($this->path);
        $line = $name . '=' . (preg_match('/^[\w.\/:@+-]*$/', $value) === 1 ? $value : '"' . addcslashes($value, '"\\') . '"');
        if ($current !== null) {
            $content = (string) preg_replace('/^[ \t]*(?:export[ \t]+)?' . preg_quote($name, '/') . '[ \t]*=.*$/m', $line, $content, 1);
        } else {
            $content = ($content === '' || str_ends_with($content, "\n") ? $content : $content . "\n") . $line . "\n";
        }
        file_put_contents($this->path, $content);

        return self::ADDED;
    }
}
