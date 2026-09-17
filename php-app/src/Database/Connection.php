<?php

declare(strict_types=1);

namespace MoodSwings\Database;

use MoodSwings\Config;
use PDO;

final class Connection
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        // Pin PHP's own default timezone to UTC unconditionally, regardless
        // of the host's date.timezone ini setting. This is the single
        // earliest chokepoint nearly every entry point (web requests and
        // cron scripts alike) passes through before doing any date/time
        // work, so asserting it here keeps date()/time()-based code correct
        // even where it isn't (or can't be) switched to gmdate()/gmtime().
        date_default_timezone_set('UTC');

        if (self::$pdo === null) {
            $host = Config::get('DB_HOST', '127.0.0.1');
            $port = Config::get('DB_PORT', '3306');
            $name = Config::get('DB_NAME', 'moodswings');
            $user = Config::get('DB_USER', 'root');
            $password = Config::get('DB_PASSWORD', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            self::$pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // MySQL's TIMESTAMP columns convert to/from storage using the
            // session's own time_zone on every read and write, independent
            // of PHP's date.timezone. The server default is 'SYSTEM', which
            // silently follows the host OS's local zone -- pin it to UTC so
            // NOW()/CURRENT_TIMESTAMP and TIMESTAMP round-trips are always
            // true UTC regardless of how the underlying host is configured.
            self::$pdo->exec("SET time_zone = '+00:00'");
        }

        return self::$pdo;
    }
}
