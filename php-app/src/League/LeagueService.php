<?php

declare(strict_types=1);

namespace MoodSwings\League;

use MoodSwings\Database\Connection;
use MoodSwings\Game\CardCatalog;
use MoodSwings\Game\DuelDeckRules;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\LeagueEventRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use PDO;

/**
 * League play (issue #171) -- a manually started, open-ended event. The first
 * time a player joins an event they are granted one randomized Structure deck,
 * added to their permanent collection (user_collection_cards) and saved as a
 * decklist they can play or edit. Joining also enters them in the event's
 * FIFO auto-pairing queue (modelled on WeeklySealedPoolQueueService): paired
 * players play a best-of-three 2-player Duel ('custom_duel') whose decks may
 * only use cards -- and as many copies of each -- as their own collection
 * holds, with at most MAX_MYTHICS mythics and MAX_RARES rares and at least
 * MIN_DECK_SIZE cards. Decks are not chosen when queueing: the game is created
 * with no decks seeded, and each player builds theirs from their collection
 * inside the game (before game 1, and again before each later game of the
 * match, as for any custom_duel match). Nobody plays the same opponent twice in
 * one event, and practice bots can't take part.
 *
 * Match results are never pushed in from GameService: league_matches rows
 * point at the best-of-three wrapper (game_matches), and syncMatches() reads
 * its status whenever the League is looked at, so results are picked up
 * however the match ended (play, resignation, timeout) and from whichever
 * process ended it.
 */
final class LeagueService
{
    public const MIN_DECK_SIZE = 12;
    public const MAX_MYTHICS = 2;
    public const MAX_RARES = 6;
    public const CONCURRENT_MATCH_CAP = 2;

    private const RECENT_MATCH_LIMIT = 20;

    /** The card-list order every other collection/pool view uses. */
    private const COLOR_ORDER = ['white', 'blue', 'black', 'red', 'green'];
    private const RARITY_ORDER = ['common', 'uncommon', 'rare', 'mythic'];

    public function __construct(
        private readonly GameService $games,
        private readonly LeagueEventRepository $events,
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
        private readonly UserDecklistRepository $decklists,
    ) {
    }

    public static function deckRules(): DuelDeckRules
    {
        return new DuelDeckRules(self::MIN_DECK_SIZE, ['mythic' => self::MAX_MYTHICS, 'rare' => self::MAX_RARES]);
    }

    /**
     * Joins the active event (granting the structure deck the first time) and
     * enters the queue, pairing straight away if an eligible player is already
     * waiting.
     *
     * @return array{status: 'waiting'}|array{status: 'paired', game_id: int, opponent_username: string}
     */
    public function joinAndQueue(int $userId): array
    {
        $event = $this->events->active();
        if ($event === null) {
            throw new GameStateException('There is no League event running right now.');
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new GameStateException('Unknown user.');
        }
        if ((bool) ($user['is_bot'] ?? false)) {
            throw new GameStateException('Practice bots cannot play in the League.');
        }
        if (!(bool) $user['matchmaking_discoverable']) {
            throw new GameStateException('League play pairs you with strangers -- turn on Settings > "Discoverable for open games" first.');
        }

        return $this->withQueueLock(function () use ($event, $userId): array {
            $eventId = $event['id'];

            if ($this->isQueued($userId)) {
                throw new GameStateException('You are already in the League queue.');
            }

            $this->syncMatches($eventId);
            $inProgress = $this->inProgressCount($eventId, $userId);
            if ($inProgress >= self::CONCURRENT_MATCH_CAP) {
                throw new GameStateException('You already have ' . self::CONCURRENT_MATCH_CAP . ' League matches in progress -- finish one before queueing again.');
            }

            $this->grantIfFirstJoin($event, $userId);

            foreach ($this->queuedCandidates($eventId) as $candidate) {
                $candidateUserId = (int) $candidate['user_id'];
                if ($candidateUserId === $userId
                    || $this->haveAlreadyPlayed($eventId, $userId, $candidateUserId)
                    || $this->areBlocked($userId, $candidateUserId)
                ) {
                    continue;
                }

                Connection::get()->prepare('DELETE FROM league_queue WHERE id = :id')->execute(['id' => $candidate['id']]);

                $gameId = $this->startMatch($eventId, $candidateUserId, $userId);
                $this->games->notifyLeagueDeckNeeded($gameId, $candidateUserId);

                return ['status' => 'paired', 'game_id' => $gameId, 'opponent_username' => (string) $candidate['username']];
            }

            Connection::get()->prepare('INSERT INTO league_queue (event_id, user_id) VALUES (:event_id, :user_id)')
                ->execute(['event_id' => $eventId, 'user_id' => $userId]);

            return ['status' => 'waiting'];
        });
    }

    /** Idempotent -- leaving a queue you aren't in is a no-op. */
    public function leaveQueue(int $userId): void
    {
        $this->withQueueLock(function () use ($userId): void {
            Connection::get()->prepare('DELETE FROM league_queue WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        });
    }

    /**
     * @return array{
     *     event: array{id: int, name: string, set_code: ?string, started_at: string}|null,
     *     joined: bool, queued: bool, in_progress_count: int, concurrent_match_cap: int,
     *     wins: int, losses: int,
     *     matches: array<int, array{id: int, game_id: ?int, opponent_username: string, status: string, won: ?bool}>,
     *     collection: array{unique_cards: int, total_cards: int},
     *     deck_rules: array{min_cards: int, max_mythics: int, max_rares: int}
     * }
     */
    public function statusFor(int $userId): array
    {
        $rules = ['min_cards' => self::MIN_DECK_SIZE, 'max_mythics' => self::MAX_MYTHICS, 'max_rares' => self::MAX_RARES];
        $copies = $this->collectionCopies($userId);
        $collection = ['unique_cards' => count($copies), 'total_cards' => array_sum($copies)];

        $event = $this->events->active();
        if ($event === null) {
            return [
                'event' => null, 'joined' => false, 'queued' => false, 'in_progress_count' => 0,
                'concurrent_match_cap' => self::CONCURRENT_MATCH_CAP, 'wins' => 0, 'losses' => 0,
                'matches' => [], 'collection' => $collection, 'deck_rules' => $rules,
            ];
        }

        $eventId = $event['id'];
        $this->syncMatches($eventId);

        $participantStmt = Connection::get()->prepare('SELECT 1 FROM league_participants WHERE event_id = :event_id AND user_id = :user_id');
        $participantStmt->execute(['event_id' => $eventId, 'user_id' => $userId]);

        $recordStmt = Connection::get()->prepare(
            "SELECT
                SUM(winner_user_id = :user_id_a) AS wins,
                SUM(winner_user_id IS NOT NULL AND winner_user_id != :user_id_b) AS losses
             FROM league_matches
             WHERE event_id = :event_id AND status = 'completed' AND (user1_id = :user_id_c OR user2_id = :user_id_d)"
        );
        $recordStmt->execute(['user_id_a' => $userId, 'user_id_b' => $userId, 'user_id_c' => $userId, 'user_id_d' => $userId, 'event_id' => $eventId]);
        $record = $recordStmt->fetch();

        return [
            'event' => [
                'id' => $eventId,
                'name' => $event['name'],
                'set_code' => $event['set_code'],
                'started_at' => $event['started_at'],
            ],
            'joined' => $participantStmt->fetchColumn() !== false,
            'queued' => $this->isQueued($userId),
            'in_progress_count' => $this->inProgressCount($eventId, $userId),
            'concurrent_match_cap' => self::CONCURRENT_MATCH_CAP,
            'wins' => (int) ($record['wins'] ?? 0),
            'losses' => (int) ($record['losses'] ?? 0),
            'matches' => $this->recentMatches($eventId, $userId),
            'collection' => $collection,
            'deck_rules' => $rules,
        ];
    }

    /**
     * The player's permanent collection as full catalog cards (the shape the
     * web client's card thumbnails/detail popup render) with the number owned,
     * ordered color, then rarity, then name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectionFor(int $userId): array
    {
        $copies = $this->collectionCopies($userId);

        $cards = [];
        foreach (CardCatalog::serialize(array_keys($copies)) as $card) {
            $card['copies'] = $copies[$card['card_id']];
            $cards[] = $card;
        }

        $colorRank = array_flip(self::COLOR_ORDER);
        $rarityRank = array_flip(self::RARITY_ORDER);
        usort($cards, static fn (array $a, array $b): int => ($colorRank[$a['color']] ?? PHP_INT_MAX) <=> ($colorRank[$b['color']] ?? PHP_INT_MAX)
            ?: ($rarityRank[$a['rarity']] ?? PHP_INT_MAX) <=> ($rarityRank[$b['rarity']] ?? PHP_INT_MAX)
            ?: strcmp($a['name'], $b['name']));

        return $cards;
    }

    /** @return array<int, int> catalog card id => copies owned */
    public function collectionCopies(int $userId): array
    {
        $stmt = Connection::get()->prepare('SELECT card_id, copies FROM user_collection_cards WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        $copies = [];
        foreach ($stmt->fetchAll() as $row) {
            $copies[(int) $row['card_id']] = (int) $row['copies'];
        }

        return $copies;
    }

    /**
     * The first join of an event: a random Structure deck goes into the
     * collection and is saved as a private decklist, in one transaction with
     * the participant row that makes it happen only once.
     *
     * @param array{id: int, name: string} $event
     * @return int|null the saved decklist the grant was saved as, null if this was not the first join
     */
    private function grantIfFirstJoin(array $event, int $userId): ?int
    {
        $pdo = Connection::get();

        $existing = $pdo->prepare('SELECT 1 FROM league_participants WHERE event_id = :event_id AND user_id = :user_id');
        $existing->execute(['event_id' => $event['id'], 'user_id' => $userId]);
        if ($existing->fetchColumn() !== false) {
            return null;
        }

        $cardIds = $this->games->randomStructureDeckCardIds();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO league_participants (event_id, user_id, granted_card_ids) VALUES (:event_id, :user_id, :granted)')
                ->execute(['event_id' => $event['id'], 'user_id' => $userId, 'granted' => json_encode($cardIds, JSON_THROW_ON_ERROR)]);

            $add = $pdo->prepare(
                'INSERT INTO user_collection_cards (user_id, card_id, copies) VALUES (:user_id, :card_id, 1)
                 ON DUPLICATE KEY UPDATE copies = copies + 1'
            );
            foreach ($cardIds as $cardId) {
                $add->execute(['user_id' => $userId, 'card_id' => $cardId]);
            }

            $decklistId = $this->decklists->create($userId, mb_substr('League deck - ' . $event['name'], 0, 120), $cardIds, null, 'private');

            $pdo->commit();

            return $decklistId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Creates the best-of-three Duel for a pairing, with each seat restricted
     * to its own collection and NO deck seeded -- both players build theirs
     * inside the game. $first is seated first; $second is whoever just joined.
     */
    private function startMatch(int $eventId, int $firstUserId, int $secondUserId): int
    {
        $gameId = $this->games->createGame(
            createdByUserId: $secondUserId,
            userIds: [$firstUserId, $secondUserId],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: [
                'preset' => 'user_defined',
                'min_cards' => self::MIN_DECK_SIZE,
                'rarity_limits' => ['mythic' => self::MAX_MYTHICS, 'rare' => self::MAX_RARES],
            ],
            bestOfThree: true,
            perSeatAllowedCardIds: [
                $firstUserId => $this->expandCopies($this->collectionCopies($firstUserId)),
                $secondUserId => $this->expandCopies($this->collectionCopies($secondUserId)),
            ],
        );

        $gameMatchId = $this->games->gameMatchWrapperIds($gameId)['game_match_id'];
        if ($gameMatchId === null) {
            throw new \LogicException('A best-of-three League game has no game_matches wrapper');
        }

        Connection::get()->prepare('INSERT INTO league_matches (event_id, user1_id, user2_id, game_match_id) VALUES (:event_id, :user1_id, :user2_id, :game_match_id)')
            ->execute(['event_id' => $eventId, 'user1_id' => $firstUserId, 'user2_id' => $secondUserId, 'game_match_id' => $gameMatchId]);

        return $gameId;
    }

    /**
     * @param array<int, int> $copies
     * @return int[]
     */
    private function expandCopies(array $copies): array
    {
        $cardIds = [];
        foreach ($copies as $cardId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $cardIds[] = $cardId;
            }
        }

        return $cardIds;
    }

    /** @return array<int, array<string, mixed>> */
    private function queuedCandidates(int $eventId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT q.id, q.user_id, u.username
             FROM league_queue q JOIN users u ON u.id = q.user_id
             WHERE q.event_id = :event_id
             ORDER BY q.queued_at ASC, q.id ASC'
        );
        $stmt->execute(['event_id' => $eventId]);

        return $stmt->fetchAll();
    }

    private function isQueued(int $userId): bool
    {
        $stmt = Connection::get()->prepare('SELECT 1 FROM league_queue WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Any match between the pair in this event, in progress or finished -- an abandoned one (its game was cleaned up) doesn't count. */
    private function haveAlreadyPlayed(int $eventId, int $userA, int $userB): bool
    {
        $stmt = Connection::get()->prepare(
            "SELECT 1 FROM league_matches
             WHERE event_id = :event_id AND status != 'abandoned'
               AND ((user1_id = :a1 AND user2_id = :b1) OR (user1_id = :b2 AND user2_id = :a2))
             LIMIT 1"
        );
        $stmt->execute(['event_id' => $eventId, 'a1' => $userA, 'b1' => $userB, 'a2' => $userA, 'b2' => $userB]);

        return $stmt->fetchColumn() !== false;
    }

    private function areBlocked(int $userA, int $userB): bool
    {
        $friendship = $this->friendships->findByPair($userA, $userB);

        return $friendship !== null && $friendship['status'] === 'blocked';
    }

    private function inProgressCount(int $eventId, int $userId): int
    {
        $stmt = Connection::get()->prepare(
            "SELECT COUNT(*) FROM league_matches
             WHERE event_id = :event_id AND status = 'in_progress' AND (user1_id = :a OR user2_id = :b)"
        );
        $stmt->execute(['event_id' => $eventId, 'a' => $userId, 'b' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Copies results from the best-of-three wrappers onto still-open
     * league_matches rows: a completed match records its winner, one whose
     * wrapper no longer exists (cleaned up) is marked abandoned.
     */
    private function syncMatches(int $eventId): void
    {
        $pdo = Connection::get();
        $open = $pdo->prepare("SELECT id, game_match_id FROM league_matches WHERE event_id = :event_id AND status = 'in_progress'");
        $open->execute(['event_id' => $eventId]);

        $wrapper = $pdo->prepare('SELECT status, winner_user_id FROM game_matches WHERE id = :id');
        $complete = $pdo->prepare("UPDATE league_matches SET status = 'completed', winner_user_id = :winner, completed_at = NOW() WHERE id = :id");
        $abandon = $pdo->prepare("UPDATE league_matches SET status = 'abandoned', completed_at = NOW() WHERE id = :id");

        foreach ($open->fetchAll() as $row) {
            $wrapper->execute(['id' => $row['game_match_id']]);
            $match = $wrapper->fetch();

            if ($match === false) {
                $abandon->execute(['id' => $row['id']]);
            } elseif ($match['status'] === 'completed' && $match['winner_user_id'] !== null) {
                $complete->execute(['winner' => $match['winner_user_id'], 'id' => $row['id']]);
            }
        }
    }

    /** @return array<int, array{id: int, game_id: ?int, opponent_username: string, status: string, won: ?bool}> */
    private function recentMatches(int $eventId, int $userId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            "SELECT m.id, m.game_match_id, m.status, m.winner_user_id, u.username AS opponent_username
             FROM league_matches m
             JOIN users u ON u.id = IF(m.user1_id = :a, m.user2_id, m.user1_id)
             WHERE m.event_id = :event_id AND m.status != 'abandoned' AND (m.user1_id = :b OR m.user2_id = :c)
             ORDER BY m.id DESC
             LIMIT " . self::RECENT_MATCH_LIMIT
        );
        $stmt->execute(['a' => $userId, 'event_id' => $eventId, 'b' => $userId, 'c' => $userId]);

        $latestGame = $pdo->prepare('SELECT id FROM games WHERE game_match_id = :game_match_id ORDER BY match_game_number DESC, id DESC LIMIT 1');

        $matches = [];
        foreach ($stmt->fetchAll() as $row) {
            $latestGame->execute(['game_match_id' => $row['game_match_id']]);
            $gameId = $latestGame->fetchColumn();

            $matches[] = [
                'id' => (int) $row['id'],
                'game_id' => $gameId !== false ? (int) $gameId : null,
                'opponent_username' => (string) $row['opponent_username'],
                'status' => (string) $row['status'],
                'won' => $row['winner_user_id'] !== null ? (int) $row['winner_user_id'] === $userId : null,
            ];
        }

        return $matches;
    }

    /**
     * Same advisory-lock pattern as WeeklySealedPoolQueueService, under its
     * own lock name: "read who's queued, maybe pair, maybe insert" stays atomic
     * against two players joining at once.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withQueueLock(callable $fn): mixed
    {
        $pdo = Connection::get();
        $lockName = 'moodswings_league_queue';

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$lockName, 10]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new GameStateException('The League queue is busy -- try again');
        }

        try {
            return $fn();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }
}
