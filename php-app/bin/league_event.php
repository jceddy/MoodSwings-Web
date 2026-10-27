#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MoodSwings\Repository\LeagueEventRepository;

// Starts and ends League events by hand (issue #171) -- see LeagueService's
// own docblock for what an event is.
//
//   php bin/league_event.php status
//   php bin/league_event.php start "<event name>" [set code]
//   php bin/league_event.php end
//
// Only one event can be active at a time. Ending one empties its queue; matches
// already under way run to completion.

$events = new LeagueEventRepository();
$command = $argv[1] ?? 'status';

try {
    switch ($command) {
        case 'start':
            if (!isset($argv[2])) {
                fwrite(STDERR, "Usage: league_event.php start \"<event name>\" [set code]\n");
                exit(1);
            }
            $id = $events->start($argv[2], $argv[3] ?? null);
            echo "Started League event #{$id}: {$argv[2]}\n";
            break;

        case 'end':
            $id = $events->endActive();
            echo "Ended League event #{$id}\n";
            break;

        case 'status':
            $active = $events->active();
            echo $active === null
                ? "No League event is running.\n"
                : "Active: #{$active['id']} {$active['name']}" . ($active['set_code'] !== null ? " ({$active['set_code']})" : '') . " -- started {$active['started_at']}\n";
            foreach ($events->all() as $event) {
                echo "  #{$event['id']} [{$event['status']}] {$event['name']}\n";
            }
            break;

        default:
            fwrite(STDERR, "Unknown command \"{$command}\" -- use status, start or end.\n");
            exit(1);
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
