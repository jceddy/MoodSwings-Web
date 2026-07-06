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
