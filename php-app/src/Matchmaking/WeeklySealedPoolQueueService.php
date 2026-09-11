<?php

declare(strict_types=1);

namespace MoodSwings\Matchmaking;

use MoodSwings\Database\Connection;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;

/**
 * Issue #520's own "Weekly Sealed Pool" queue: a lightweight, continuous
 * FIFO auto-pairing ladder, deliberately NOT built on top of
 * MatchmakingService's own open-lobby machinery (OpenGameListingRepository/
 * postOpenGame()/joinOpenGame()) -- that system is browse-and-pick (a
 * listing sits open until someone chooses to join it); this is closer to
 * a ranked-queue model where a player just clicks "Join Queue" and is
 * automatically matched against the next eligible person with no
 * browsing at all. Reuses MatchmakingService::withListingLock()'s own
 * GET_LOCK() advisory-lock precedent (keeping "look for an eligible
 * opponent, maybe pair" atomic against two people joining at the same
 * instant) under its own fixed lock name, since there's exactly one
 * queue here rather than one lock per listing.
 */
final class WeeklySealedPoolQueueService
{
    /**
     * The maintainer's own suggested number (issue #520): a player can
     * only join the queue while they have fewer than this many of this
     * week's own Weekly Sealed Pool matches still in progress, so one
     * player can't accumulate a long tail of simultaneous opponents while
     * everyone else waits on them. Enforced at join-queue time only (the
     * issue's own "and/or" alternative -- proactively pulling a player
     * back out of the queue the moment a separate match pushes them over
     * the cap -- is deliberately not implemented, since nothing else can
     * add to a player's own in-progress count while they're sitting in
     * this queue in the first place).
     */
    private const CONCURRENT_MATCH_CAP = 2;

    public function __construct(private readonly GameService $games)
    {
    }

    /**
     * @return array{status: 'waiting'}|array{status: 'paired', game_id: int, opponent_username: string}
     */
    public function joinQueue(int $userId): array
    {
        $periodicSealedPoolId = $this->games->currentWeeklySealedPoolId();

        $inProgressCount = $this->games->countInProgressWeeklySealedPoolMatchesForUser($periodicSealedPoolId, $userId);
        if ($inProgressCount >= self::CONCURRENT_MATCH_CAP) {
            throw new GameStateException(
                'You already have ' . self::CONCURRENT_MATCH_CAP . ' Weekly Sealed Pool matches in progress -- finish one before joining the queue again.'
            );
        }

        return $this->withQueueLock(function () use ($userId, $periodicSealedPoolId): array {
            if ($this->isQueued($userId)) {
                throw new GameStateException('You are already in the Weekly Sealed Pool queue.');
            }

            // queued_at alone (a TIMESTAMP, second-granularity) can't
            // break a tie between two joins landing in the same second --
            // q.id (auto-increment, so it already reflects insertion
            // order exactly) is the tiebreaker, keeping "earliest" a
            // well-defined, deterministic order regardless of how close
            // together two joins happen.
            $stmt = Connection::get()->prepare(
                'SELECT q.user_id, u.username FROM weekly_sealed_pool_queue q
                 JOIN users u ON u.id = q.user_id
                 ORDER BY q.queued_at ASC, q.id ASC'
            );
            $stmt->execute();

            foreach ($stmt->fetchAll() as $candidate) {
                $candidateUserId = (int) $candidate['user_id'];
                if ($this->games->haveWeeklySealedPoolOpponentsAlreadyPlayed($periodicSealedPoolId, $userId, $candidateUserId)) {
                    continue;
                }

                $this->removeFromQueue($candidateUserId);

                $gameId = $this->games->createGame(
                    createdByUserId: $userId,
                    userIds: [$candidateUserId, $userId],
                    format: 'draft',
                    deckType: 'weekly_sealed_pool',
                );

                return ['status' => 'paired', 'game_id' => $gameId, 'opponent_username' => $candidate['username']];
            }

            Connection::get()->prepare('INSERT INTO weekly_sealed_pool_queue (user_id) VALUES (:user_id)')
                ->execute(['user_id' => $userId]);

            return ['status' => 'waiting'];
        });
    }

    /** Idempotent -- backing out of a queue you were never (or no longer) in is simply a no-op, not an error. */
    public function leaveQueue(int $userId): void
    {
        $this->withQueueLock(function () use ($userId): void {
            $this->removeFromQueue($userId);
        });
    }

    /** @return array{queued: bool, in_progress_count: int, concurrent_match_cap: int} */
    public function queueStatusFor(int $userId): array
    {
        $periodicSealedPoolId = $this->games->currentWeeklySealedPoolId();

        return [
            'queued' => $this->isQueued($userId),
            'in_progress_count' => $this->games->countInProgressWeeklySealedPoolMatchesForUser($periodicSealedPoolId, $userId),
            'concurrent_match_cap' => self::CONCURRENT_MATCH_CAP,
        ];
    }

    private function isQueued(int $userId): bool
    {
        $stmt = Connection::get()->prepare('SELECT 1 FROM weekly_sealed_pool_queue WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    private function removeFromQueue(int $userId): void
    {
        Connection::get()->prepare('DELETE FROM weekly_sealed_pool_queue WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    /**
     * Mirrors MatchmakingService::withListingLock()'s own advisory-lock
     * pattern, under one single fixed lock name -- there's exactly one
     * Weekly Sealed Pool queue (unlike one lock per open-lobby listing),
     * so every join/leave serializes against every other one, keeping
     * "read who's queued, maybe pair, maybe insert" atomic against two
     * players joining at the same instant.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withQueueLock(callable $fn): mixed
    {
        $pdo = Connection::get();
        $lockName = 'moodswings_weekly_sealed_pool_queue';

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$lockName, 10]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new GameStateException('The Weekly Sealed Pool queue is busy -- try again');
        }

        try {
            return $fn();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }
}
