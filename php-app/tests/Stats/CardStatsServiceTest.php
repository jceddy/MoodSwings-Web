<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Stats;

use MoodSwings\Stats\CardStatsService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level tests for CardStatsService, driven directly (not through a
 * real played game/draft match) -- games/game_players/game_cards/
 * game_events rows are inserted by hand so each aggregation rule (deck
 * membership, played-vs-not, duplicate-catalog-id dedup, win/loss
 * crediting, each draft format's own pick-position math) can be checked
 * in isolation. GameServiceIntegrationTest.php's own card-stats tests
 * cover the other half: that the real game-completion/pick-submission
 * code paths actually call these methods.
 */
final class CardStatsServiceTest extends TestCase
{
    private PDO $pdo;
    private CardStatsService $stats;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $name = getenv('TEST_DB_NAME') ?: 'moodswings_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('No test MySQL database available: ' . $e->getMessage());
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE game_events');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE card_stats');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;
        $this->stats = new CardStatsService();
    }

    private function insertUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at)
             VALUES (:username, :email, 'hash', NOW())"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com"]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGame(int $createdByUserId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('standard', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $createdByUserId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGamePlayer(int $gameId, int $userId, int $seatOrder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_players (game_id, user_id, seat_order) VALUES (:game_id, :user_id, :seat_order)'
        );
        $stmt->execute(['game_id' => $gameId, 'user_id' => $userId, 'seat_order' => $seatOrder]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGameCard(int $gameId, int $catalogCardId, ?int $ownerGamePlayerId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id)
             VALUES (:game_id, :card_id, 'hand', :owner)"
        );
        $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId, 'owner' => $ownerGamePlayerId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertMoodPlayedEvent(int $gameId, int $actingGamePlayerId, int $gameCardId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, card_id)
             VALUES (:game_id, :actor, 'mood_played', :card_id)"
        );
        $stmt->execute(['game_id' => $gameId, 'actor' => $actingGamePlayerId, 'card_id' => $gameCardId]);
    }

    private function insertPendingDecisionCreatedEvent(int $gameId, int $actingGamePlayerId, int $gameCardId, ?array $details = null): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_events (game_id, acting_game_player_id, event_type, card_id, details)
             VALUES (:game_id, :actor, 'pending_decision_created', :card_id, :details)"
        );
        $stmt->execute([
            'game_id' => $gameId,
            'actor' => $actingGamePlayerId,
            'card_id' => $gameCardId,
            'details' => $details === null ? null : json_encode($details),
        ]);
    }

    private function fetchCardStats(int $catalogCardId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM card_stats WHERE catalog_card_id = :id');
        $stmt->execute(['id' => $catalogCardId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function testRecordGameCompletionCreditsWinnerAndLoserDeckMembershipSeparately(): void
    {
        $winner = $this->insertUser('cardstats-winner');
        $loser = $this->insertUser('cardstats-loser');
        $gameId = $this->insertGame($winner);
        $winnerPlayerId = $this->insertGamePlayer($gameId, $winner, 0);
        $loserPlayerId = $this->insertGamePlayer($gameId, $loser, 1);

        $this->insertGameCard($gameId, 1, $winnerPlayerId);
        $this->insertGameCard($gameId, 2, $loserPlayerId);

        $this->stats->recordGameCompletion($gameId, [$winner], [$loser]);

        $winnerCard = $this->fetchCardStats(1);
        self::assertSame(1, (int) $winnerCard['times_in_deck']);
        self::assertSame(1, (int) $winnerCard['times_in_won_deck']);

        $loserCard = $this->fetchCardStats(2);
        self::assertSame(1, (int) $loserCard['times_in_deck']);
        self::assertSame(0, (int) $loserCard['times_in_won_deck'], 'the losing player\'s own deck card is counted but never credited as a win');
    }

    public function testRecordGameCompletionDedupsTwoCopiesOfTheSameCatalogCardInOneDeck(): void
    {
        $winner = $this->insertUser('cardstats-dupdeck-winner');
        $loser = $this->insertUser('cardstats-dupdeck-loser');
        $gameId = $this->insertGame($winner);
        $winnerPlayerId = $this->insertGamePlayer($gameId, $winner, 0);
        $this->insertGamePlayer($gameId, $loser, 1);

        // Two physically distinct game_cards rows, same catalog card (a
        // legal "2 Charity" custom deck) -- should still only count once
        // toward "how many decks this card ended up in".
        $this->insertGameCard($gameId, 3, $winnerPlayerId);
        $this->insertGameCard($gameId, 3, $winnerPlayerId);

        $this->stats->recordGameCompletion($gameId, [$winner], [$loser]);

        $card = $this->fetchCardStats(3);
        self::assertSame(1, (int) $card['times_in_deck']);
        self::assertSame(1, (int) $card['times_in_won_deck']);
    }

    public function testRecordGameCompletionCountsPlayedCardsIndependentlyOfDeckMembership(): void
    {
        $winner = $this->insertUser('cardstats-played-winner');
        $loser = $this->insertUser('cardstats-played-loser');
        $gameId = $this->insertGame($winner);
        $winnerPlayerId = $this->insertGamePlayer($gameId, $winner, 0);
        $this->insertGamePlayer($gameId, $loser, 1);

        $playedCardId = $this->insertGameCard($gameId, 4, $winnerPlayerId);
        $this->insertGameCard($gameId, 5, $winnerPlayerId); // never played
        $this->insertMoodPlayedEvent($gameId, $winnerPlayerId, $playedCardId);

        $this->stats->recordGameCompletion($gameId, [$winner], [$loser]);

        $played = $this->fetchCardStats(4);
        self::assertSame(1, (int) $played['times_in_deck']);
        self::assertSame(1, (int) $played['times_played']);
        self::assertSame(1, (int) $played['times_played_in_won_game']);

        $unplayed = $this->fetchCardStats(5);
        self::assertSame(1, (int) $unplayed['times_in_deck'], 'still in the deck...');
        self::assertSame(0, (int) $unplayed['times_played'], '...but never played');
    }

    public function testRecordGameCompletionDedupsACardPlayedTwiceInOneGame(): void
    {
        $winner = $this->insertUser('cardstats-replayed-winner');
        $loser = $this->insertUser('cardstats-replayed-loser');
        $gameId = $this->insertGame($winner);
        $winnerPlayerId = $this->insertGamePlayer($gameId, $winner, 0);
        $this->insertGamePlayer($gameId, $loser, 1);

        // Duplicity-style repeat: the same physical card logs two
        // separate 'mood_played' events in one game.
        $cardId = $this->insertGameCard($gameId, 6, $winnerPlayerId);
        $this->insertMoodPlayedEvent($gameId, $winnerPlayerId, $cardId);
        $this->insertMoodPlayedEvent($gameId, $winnerPlayerId, $cardId);

        $this->stats->recordGameCompletion($gameId, [$winner], [$loser]);

        $card = $this->fetchCardStats(6);
        self::assertSame(1, (int) $card['times_played'], 'a repeat play still only counts once toward "was this card played in this game"');
    }

    /**
     * A bug caught live: a card whose effect implements
     * RequiresOpponentDecision (Compulsion, Fury, Instability, etc.) logs
     * 'pending_decision_created' instead of 'mood_played' whenever its
     * play actually pauses for an opponent's response -- see
     * GameService::playMood()'s own "isPending" branch. For a card like
     * Fury, whose decision fires on nearly every real play, that meant
     * "times played" stayed at 0 forever despite being played constantly.
     */
    public function testRecordGameCompletionCountsAPlayThatPausedForAnOpponentDecision(): void
    {
        $winner = $this->insertUser('cardstats-pending-winner');
        $loser = $this->insertUser('cardstats-pending-loser');
        $gameId = $this->insertGame($winner);
        $winnerPlayerId = $this->insertGamePlayer($gameId, $winner, 0);
        $this->insertGamePlayer($gameId, $loser, 1);

        // Fury-style play: paused for an opponent decision, so only
        // 'pending_decision_created' was ever logged -- no 'mood_played'.
        $furyCardId = $this->insertGameCard($gameId, 20, $winnerPlayerId);
        $this->insertPendingDecisionCreatedEvent($gameId, $winnerPlayerId, $furyCardId);

        $this->stats->recordGameCompletion($gameId, [$winner], [$loser]);

        $card = $this->fetchCardStats(20);
        self::assertSame(1, (int) $card['times_played']);
        self::assertSame(1, (int) $card['times_played_in_won_game']);
    }

    /**
     * The same event_type is also logged for a scoring-time decision
     * re-triggering on a card played earlier in the round (Enthusiasm's/
     * Passion's own bonus decision, tagged 'scoring_trigger' -- see
     * scoreRoundAndAdvance()) and an after-scoring order choice between
     * two already-resolved afterScoring cards (tagged
     * 'after_scoring_order_trigger' -- see finishScoringAndAdvance()).
     * Neither is a card actually being played right then, so neither
     * should bump times_played on its own.
     */
    public function testRecordGameCompletionExcludesScoringAndOrderTriggerPendingDecisions(): void
    {
        $winner = $this->insertUser('cardstats-trigger-winner');
        $loser = $this->insertUser('cardstats-trigger-loser');
        $gameId = $this->insertGame($winner);
        $winnerPlayerId = $this->insertGamePlayer($gameId, $winner, 0);
        $this->insertGamePlayer($gameId, $loser, 1);

        $scoringCardId = $this->insertGameCard($gameId, 21, $winnerPlayerId);
        $this->insertPendingDecisionCreatedEvent($gameId, $winnerPlayerId, $scoringCardId, ['scoring_trigger' => true]);

        $orderCardId = $this->insertGameCard($gameId, 22, $winnerPlayerId);
        $this->insertPendingDecisionCreatedEvent($gameId, $winnerPlayerId, $orderCardId, ['after_scoring_order_trigger' => true]);

        $this->stats->recordGameCompletion($gameId, [$winner], [$loser]);

        // Both cards still get a card_stats row (they're in the deck --
        // times_in_deck), just no times_played bump from either trigger.
        self::assertSame(0, (int) $this->fetchCardStats(21)['times_played'], 'a scoring-trigger decision is not the card being played');
        self::assertSame(0, (int) $this->fetchCardStats(22)['times_played'], 'an after-scoring-order decision is not the card being played');
    }

    /**
     * A multi-step pending-decision chain (e.g. a Duplicity repeat still
     * needing its own opponent decision) logs 'pending_decision_created'
     * more than once for the very same play -- still only one "played"
     * toward this game, same DISTINCT dedup 'mood_played' repeats already get.
     */
    public function testRecordGameCompletionDedupsMultiplePendingDecisionCreatedEventsForOnePlay(): void
    {
        $winner = $this->insertUser('cardstats-pending-chain-winner');
        $loser = $this->insertUser('cardstats-pending-chain-loser');
        $gameId = $this->insertGame($winner);
        $winnerPlayerId = $this->insertGamePlayer($gameId, $winner, 0);
        $this->insertGamePlayer($gameId, $loser, 1);

        $cardId = $this->insertGameCard($gameId, 23, $winnerPlayerId);
        $this->insertPendingDecisionCreatedEvent($gameId, $winnerPlayerId, $cardId);
        $this->insertPendingDecisionCreatedEvent($gameId, $winnerPlayerId, $cardId);

        $this->stats->recordGameCompletion($gameId, [$winner], [$loser]);

        $card = $this->fetchCardStats(23);
        self::assertSame(1, (int) $card['times_played']);
    }

    public function testRecordGameCompletionIgnoresUndrawnSharedPoolCardsWithNoOwner(): void
    {
        $winner = $this->insertUser('cardstats-undrawn-winner');
        $loser = $this->insertUser('cardstats-undrawn-loser');
        $gameId = $this->insertGame($winner);
        $this->insertGamePlayer($gameId, $winner, 0);
        $this->insertGamePlayer($gameId, $loser, 1);

        // Still sitting in the shared deck, never drawn by anyone.
        $this->insertGameCard($gameId, 7, null);

        $this->stats->recordGameCompletion($gameId, [$winner], [$loser]);

        self::assertNull($this->fetchCardStats(7), 'a card nobody ever drew is not part of anyone\'s realized deck');
    }

    public function testRecordGameCompletionAccumulatesAcrossMultipleGames(): void
    {
        $winner = $this->insertUser('cardstats-accum-winner');
        $loser = $this->insertUser('cardstats-accum-loser');

        $gameId1 = $this->insertGame($winner);
        $p1 = $this->insertGamePlayer($gameId1, $winner, 0);
        $this->insertGamePlayer($gameId1, $loser, 1);
        $this->insertGameCard($gameId1, 8, $p1);
        $this->stats->recordGameCompletion($gameId1, [$winner], [$loser]);

        $gameId2 = $this->insertGame($winner);
        $p2 = $this->insertGamePlayer($gameId2, $loser, 0); // this time the same card is in the loser's deck
        $this->insertGamePlayer($gameId2, $winner, 1);
        $this->insertGameCard($gameId2, 8, $p2);
        $this->stats->recordGameCompletion($gameId2, [$winner], [$loser]);

        $card = $this->fetchCardStats(8);
        self::assertSame(2, (int) $card['times_in_deck']);
        self::assertSame(1, (int) $card['times_in_won_deck'], 'only the first game\'s copy was on the winning side');
    }

    public function testRecordQuickDraftPickComputesPositionFromRoundStageAndPlayerCount(): void
    {
        $this->stats->recordQuickDraftPick([10], roundNumber: 2, stageNumber: 3, playerCount: 4);

        $card = $this->fetchCardStats(10);
        self::assertSame(7, (int) $card['quick_draft_pick_position_sum'], '(2-1)*4 + 3 = 7');
        self::assertSame(1, (int) $card['quick_draft_pick_position_count']);

        $this->stats->recordQuickDraftPick([10], roundNumber: 1, stageNumber: 1, playerCount: 4);

        $card = $this->fetchCardStats(10);
        self::assertSame(8, (int) $card['quick_draft_pick_position_sum'], '7 + 1');
        self::assertSame(2, (int) $card['quick_draft_pick_position_count']);
    }

    public function testRecordWinstonDraftPickUsesPileSizeForEveryCardInThePile(): void
    {
        $this->stats->recordWinstonDraftPick([11, 12], pileSize: 3);

        foreach ([11, 12] as $catalogCardId) {
            $card = $this->fetchCardStats($catalogCardId);
            self::assertSame(3, (int) $card['winston_draft_pick_pile_size_sum']);
            self::assertSame(1, (int) $card['winston_draft_pick_pile_size_count']);
        }
    }

    public function testRecordGridDraftPickUsesTheRoundNumber(): void
    {
        $this->stats->recordGridDraftPick([13], round: 4);

        $card = $this->fetchCardStats(13);
        self::assertSame(4, (int) $card['grid_draft_pick_round_sum']);
        self::assertSame(1, (int) $card['grid_draft_pick_round_count']);
    }

    public function testRecordRotisserieDraftPickUsesTheGlobalPickPosition(): void
    {
        $this->stats->recordRotisserieDraftPick([14], position: 5);

        $card = $this->fetchCardStats(14);
        self::assertSame(5, (int) $card['rotisserie_draft_pick_position_sum']);
        self::assertSame(1, (int) $card['rotisserie_draft_pick_position_count']);

        $this->stats->recordRotisserieDraftPick([14], position: 3);

        $card = $this->fetchCardStats(14);
        self::assertSame(8, (int) $card['rotisserie_draft_pick_position_sum'], '5 + 3');
        self::assertSame(2, (int) $card['rotisserie_draft_pick_position_count']);
    }

    public function testRecordPickMethodsAreNoOpsForAnEmptyCardList(): void
    {
        $this->stats->recordQuickDraftPick([], roundNumber: 1, stageNumber: 1, playerCount: 2);
        $this->stats->recordWinstonDraftPick([], pileSize: 1);
        $this->stats->recordGridDraftPick([], round: 1);
        $this->stats->recordRotisserieDraftPick([], position: 1);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM card_stats');
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testAllCardStatsIncludesEveryCatalogCardWithZeroDefaultsForUnrecordedOnes(): void
    {
        // is_token = 0 excludes Chaos Draft's own 5 conjured-token cards
        // (migration 0183, issue #405) -- allCardStats() reads from
        // CardCatalog::load(), which now excludes them too (a bug caught
        // live: a token isn't a real draftable/collectible catalog card,
        // so it has no business getting its own row on the public stats
        // page).
        $totalCards = (int) $this->pdo->query('SELECT COUNT(*) FROM cards WHERE is_token = 0')->fetchColumn();

        $winner = $this->insertUser('cardstats-allstats-winner');
        $loser = $this->insertUser('cardstats-allstats-loser');
        $gameId = $this->insertGame($winner);
        $winnerPlayerId = $this->insertGamePlayer($gameId, $winner, 0);
        $this->insertGamePlayer($gameId, $loser, 1);
        $playedCardId = $this->insertGameCard($gameId, 1, $winnerPlayerId);
        $this->insertMoodPlayedEvent($gameId, $winnerPlayerId, $playedCardId);
        $this->stats->recordGameCompletion($gameId, [$winner], [$loser]);
        $this->stats->recordQuickDraftPick([1], roundNumber: 1, stageNumber: 1, playerCount: 2);

        $rows = $this->stats->allCardStats();
        self::assertCount($totalCards, $rows, 'every catalog card appears, not just ones with recorded stats');

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['catalog_card_id']] = $row;
        }

        $recorded = $byId[1];
        self::assertSame('Altruism', $recorded['name']);
        self::assertSame('MSW', $recorded['set_code']);
        self::assertIsInt($recorded['collector_number']);
        self::assertSame(1, $recorded['times_in_deck']);
        self::assertSame(1.0, $recorded['deck_win_rate']);
        self::assertSame(1, $recorded['times_played']);
        self::assertSame(1.0, $recorded['play_win_rate']);
        self::assertSame(['average' => 1.0, 'count' => 1], $recorded['quick_draft']);
        self::assertSame(['average' => null, 'count' => 0], $recorded['winston_draft']);
        self::assertSame(['average' => null, 'count' => 0], $recorded['grid_draft']);
        self::assertSame(['average' => null, 'count' => 0], $recorded['rotisserie_draft']);

        $untouched = $byId[2];
        self::assertSame(0, $untouched['times_in_deck']);
        self::assertNull($untouched['deck_win_rate']);
        self::assertSame(0, $untouched['times_played']);
        self::assertNull($untouched['play_win_rate']);
        self::assertSame(['average' => null, 'count' => 0], $untouched['quick_draft']);
        self::assertSame(['average' => null, 'count' => 0], $untouched['rotisserie_draft']);
    }
}
