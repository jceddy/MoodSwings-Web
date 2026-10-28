<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;
use RuntimeException;

/**
 * League events (issue #171, migration 0482): started and ended by hand --
 * see bin/league_event.php. At most one event is 'active' at a time.
 */
final class LeagueEventRepository
{
    /** @return array{id: int, name: string, set_code: ?string, status: string, started_at: string, ended_at: ?string}|null */
    public function active(): ?array
    {
        $row = Connection::get()->query("SELECT * FROM league_events WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** The most recently ended event -- the "previous event" a leaderboard can still show once nothing is active. */
    public function latestEnded(): ?array
    {
        $row = Connection::get()->query("SELECT * FROM league_events WHERE status = 'ended' ORDER BY ended_at DESC, id DESC LIMIT 1")->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @throws RuntimeException if an event is already active */
    public function start(string $name, ?string $setCode): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new RuntimeException('An event name of 1-100 characters is required.');
        }
        if ($setCode !== null && ($setCode === '' || mb_strlen($setCode) > 16)) {
            throw new RuntimeException('A set code, if given, must be 1-16 characters.');
        }
        if ($this->active() !== null) {
            throw new RuntimeException('A League event is already active -- end it first.');
        }

        $stmt = Connection::get()->prepare('INSERT INTO league_events (name, set_code) VALUES (:name, :set_code)');
        $stmt->execute(['name' => $name, 'set_code' => $setCode]);

        return (int) Connection::get()->lastInsertId();
    }

    /**
     * Ends the active event and empties its queue. Matches already under way
     * are left to finish; nobody new is paired.
     *
     * @throws RuntimeException if no event is active
     */
    public function endActive(): int
    {
        $event = $this->active();
        if ($event === null) {
            throw new RuntimeException('There is no active League event.');
        }

        $pdo = Connection::get();
        $pdo->prepare('DELETE FROM league_queue WHERE event_id = :event_id')->execute(['event_id' => $event['id']]);
        $pdo->prepare("UPDATE league_events SET status = 'ended', ended_at = NOW() WHERE id = :id")->execute(['id' => $event['id']]);

        return $event['id'];
    }

    /** @return array<int, array{id: int, name: string, set_code: ?string, status: string, started_at: string, ended_at: ?string}> newest first */
    public function all(): array
    {
        return array_map($this->hydrate(...), Connection::get()->query('SELECT * FROM league_events ORDER BY id DESC')->fetchAll());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'set_code' => $row['set_code'] !== null ? (string) $row['set_code'] : null,
            'status' => (string) $row['status'],
            'started_at' => (string) $row['started_at'],
            'ended_at' => $row['ended_at'] !== null ? (string) $row['ended_at'] : null,
        ];
    }
}
