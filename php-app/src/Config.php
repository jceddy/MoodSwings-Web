<?php

declare(strict_types=1);

namespace MoodSwings;

final class Config
{
    private static ?array $values = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$values === null) {
            self::$values = self::load();
        }

        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $envValue = getenv($key);

        return $envValue !== false ? $envValue : $default;
    }

    /**
     * A feature-flag-style boolean config value -- e.g. a repository
     * variable a maintainer sets from GitHub's own Settings -> Secrets
     * and variables -> Actions -> Variables (see .github/workflows/deploy*.yml's
     * own write_env_var() calls), rather than a code change, so it can
     * be flipped on a later deploy with nothing to redeploy but the
     * variable itself. Unset (the common case -- nothing written to
     * .env at all) or any value other than the truthy ones below reads
     * as $default.
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function load(): array
    {
        $path = dirname(__DIR__) . '/.env';

        if (!is_file($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value);
        }

        return $values;
    }
}
