<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

/**
 * Tracks one background bot_search_jobs row per Tactical Bot turn (issue
 * #419, migration 0236) -- see that migration's own docblock for why this
 * exists at all (GameService::advanceAutomatedTurns() runs synchronously
 * inline with an HTTP request, and a search call can legitimately take up
 * to a couple of minutes). GameService reads/writes these rows to decide
 * whether a search is already running for a seat, launch a fresh one, or
 * recognize a stale/crashed one and fall back to the ordinary heuristic
 * bot -- see GameService::advanceTacticalBotSearch()'s own docblock for
 * the full state machine this repository supports.
 */
final class BotSearchJobRepository
{
    /**
     * The most recent job for $gamePlayerId, if any -- 'running' or
     * otherwise. A seat only ever has one turn open at a time, so "most
     * recent" is unambiguous; a caller checking whether a search is
     * currently in flight should look at the returned row's own 'status'
     * itself rather than assuming a returned row means "still running".
     *
     * @return ?array{id: int, status: string, time_budget_seconds: int, started_at: string}
     */
    public function mostRecentFor(int $gamePlayerId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, status, time_budget_seconds, started_at FROM bot_search_jobs
             WHERE game_player_id = :game_player_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['game_player_id' => $gamePlayerId]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'time_budget_seconds' => (int) $row['time_budget_seconds'],
            'started_at' => $row['started_at'],
        ];
    }

    /**
     * The background process's own lookup (bin/run_bot_search.php ->
     * GameService::runTacticalBotSearchJob()) -- everything it needs to
     * actually run the search and apply the result, by the exact job id
     * launchTacticalBotSearchJob() handed it on the command line.
     *
     * @return ?array{game_id: int, game_player_id: int, time_budget_seconds: int}
     */
    public function get(int $jobId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT game_id, game_player_id, time_budget_seconds FROM bot_search_jobs WHERE id = :id'
        );
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'game_id' => (int) $row['game_id'],
            'game_player_id' => (int) $row['game_player_id'],
            'time_budget_seconds' => (int) $row['time_budget_seconds'],
        ];
    }

    /** @return int the new job's own id, for the background process to report back against */
    public function create(int $gameId, int $gamePlayerId, int $timeBudgetSeconds): int
    {
        $pdo = Connection::get();
        $pdo->prepare(
            'INSERT INTO bot_search_jobs (game_id, game_player_id, time_budget_seconds) VALUES (:game_id, :game_player_id, :time_budget_seconds)'
        )->execute(['game_id' => $gameId, 'game_player_id' => $gamePlayerId, 'time_budget_seconds' => $timeBudgetSeconds]);

        return (int) $pdo->lastInsertId();
    }

    public function markDone(int $jobId): void
    {
        Connection::get()->prepare(
            "UPDATE bot_search_jobs SET status = 'done', finished_at = NOW() WHERE id = :id"
        )->execute(['id' => $jobId]);
    }

    public function markFailed(int $jobId, string $message): void
    {
        Connection::get()->prepare(
            "UPDATE bot_search_jobs SET status = 'failed', finished_at = NOW(), error_message = :message WHERE id = :id"
        )->execute(['id' => $jobId, 'message' => $message]);
    }
}
