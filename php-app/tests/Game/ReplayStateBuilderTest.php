<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Issue #240 "watch game replay": ReplayStateBuilder reconstructs a
 * completed game's board at any point in its history by forward-replaying
 * recorded facts from a reverse-derived genesis -- see the class's own
 * docblock. These tests exercise both directions (genesis() and
 * stateAsOf()) against real played-out games, the same integration-test
 * style GameServiceIntegrationTest.php already uses.
 */
final class ReplayStateBuilderTest extends TestCase
{
    private PDO $pdo;
    private GameService $games;
    private ReplayStateBuilder $replay;

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
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException $e) {
            self::markTestSkipped("No reachable test database: {$e->getMessage()}");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE game_events');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_rounds');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;

        $registry = DefaultEffectRegistry::build();
        $this->replay = new ReplayStateBuilder($registry);
        $this->games = new GameService(
            new BoardStateRepository($registry),
            new \MoodSwings\Rules\MoodPlayService($registry),
            new \MoodSwings\Rules\RoundScorer(),
            new UserDecklistService(
                new UserDecklistRepository(),
                new FriendshipService(new UserRepository(), new FriendshipRepository()),
            ),
            $this->replay,
        );
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

    private function insertGamePlayer(int $gameId, int $userId, int $seatOrder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_players (game_id, user_id, seat_order) VALUES (:game_id, :user_id, :seat_order)'
        );
        $stmt->execute(['game_id' => $gameId, 'user_id' => $userId, 'seat_order' => $seatOrder]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGameCard(int $gameId, int $cardId, string $zone, ?int $owner = null, ?int $deckPosition = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, deck_position)
             VALUES (:game_id, :card_id, :zone, :owner, :deck_position)'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'card_id' => $cardId,
            'zone' => $zone,
            'owner' => $owner,
            'deck_position' => $deckPosition,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGameRound(int $gameId, int $roundNumber, int $firstPlayerId, int $currentTurnPlayerId, int $playsRemaining): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, status)
             VALUES (:game_id, :round_number, :first, :current, :plays, 'in_progress')"
        );
        $stmt->execute([
            'game_id' => $gameId,
            'round_number' => $roundNumber,
            'first' => $firstPlayerId,
            'current' => $currentTurnPlayerId,
            'plays' => $playsRemaining,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGame(string $format, string $status, int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES (:format, :status, :created_by, 3)"
        );
        $stmt->execute(['format' => $format, 'status' => $status, 'created_by' => $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    private function markCompleted(int $gameId): void
    {
        $this->pdo->prepare("UPDATE games SET status = 'completed', completed_at = NOW() WHERE id = :id")
            ->execute(['id' => $gameId]);
    }

    /**
     * Zeal (drawCard()-driven) exercises genesis + forward replay + the
     * "drawn card present from its draw event onward, absent before" case
     * in one pass: genesis has both originally-dealt hand cards and the
     * undrawn deck card; after the one play, the drawn card has moved into
     * the hand and Zeal itself sits in play.
     */
    public function testGenesisAndStateAsOfAroundASingleZealPlay(): void
    {
        $u1 = $this->insertUser('replaygenesis1');
        $u2 = $this->insertUser('replaygenesis2');
        $gameId = $this->insertGame('standard', 'in_progress', $u1);

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $zealId = $this->insertGameCard($gameId, 106, 'hand', $p1); // Zeal
        $benevolenceId = $this->insertGameCard($gameId, 2, 'hand', $p1); // bottomed as Zeal's own cost
        $disciplineId = $this->insertGameCard($gameId, 9, 'deck', null, 0); // shared deck -- what p1 draws
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $zealId, ['hand_card_id' => $benevolenceId]);
        $this->markCompleted($gameId);

        $eventId = $this->games->fullEventLog($gameId)[0]['id'];

        $genesis = $this->replay->genesis($gameId);
        self::assertEqualsCanonicalizing([$zealId, $benevolenceId], $genesis->hand($p1), 'genesis restores exactly what was dealt');
        self::assertSame([$disciplineId], $genesis->deck(), 'genesis restores the undealt remainder of the deck');
        self::assertSame([], $genesis->discardPile());
        self::assertSame([], $genesis->moodsInPlay(), 'nothing can be in play before the game\'s first event');
        self::assertFalse($genesis->isInHand($p1, $disciplineId), 'the drawn card is not yet in hand at genesis');

        $afterZeal = $this->replay->stateAsOf($gameId, $eventId);
        self::assertSame([$disciplineId], $afterZeal->hand($p1), 'the drawn card replaces the two spent cards in hand');
        self::assertSame([$benevolenceId], $afterZeal->deck(), 'Benevolence was bottomed onto the deck as Zeal\'s own cost, replacing the card that got drawn');
        self::assertSame([], $afterZeal->discardPile());
        self::assertSame([$zealId], array_keys($afterZeal->moodsInPlay()), 'Zeal itself stays in play -- nothing discards it');
    }

    /**
     * Self-Loathing's own to-play cost (moveInPlayToDiscard() on an
     * already-in-play mood, chosen freely) is a clean single-card
     * play->discard transition -- exercises card_moves reversal/replay for
     * a mood that entered play in one event and left play (via a
     * DIFFERENT mood's own cost) in a later one.
     */
    public function testStateAsOfReflectsAMoodLeavingPlayToDiscardAsAnotherCardsCost(): void
    {
        $u1 = $this->insertUser('replaydiscard1');
        $u2 = $this->insertUser('replaydiscard2');
        $gameId = $this->insertGame('standard', 'in_progress', $u1);

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $charityId = $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- grants the extra play needed for both plays
        $selfLoathingId = $this->insertGameCard($gameId, 75, 'hand', $p1); // Self-Loathing
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $charityId, []);
        $this->games->playMood($gameId, $p1, $selfLoathingId, ['discard_mood_ids' => [$charityId]]);
        $this->markCompleted($gameId);

        $events = $this->games->fullEventLog($gameId);
        self::assertCount(2, $events);
        [$charityEventId, $selfLoathingEventId] = [$events[0]['id'], $events[1]['id']];

        $afterCharity = $this->replay->stateAsOf($gameId, $charityEventId);
        self::assertSame([$charityId], array_keys($afterCharity->moodsInPlay()));
        self::assertSame([], $afterCharity->discardPile());
        self::assertSame([$selfLoathingId], $afterCharity->hand($p1));

        $afterSelfLoathing = $this->replay->stateAsOf($gameId, $selfLoathingEventId);
        self::assertSame([$selfLoathingId], array_keys($afterSelfLoathing->moodsInPlay()), 'Charity left play; Self-Loathing entered it');
        self::assertSame([$charityId], $afterSelfLoathing->discardPile());
        self::assertSame([], $afterSelfLoathing->hand($p1));
    }

    /** genesis needs no hasSeparateDecks special-casing -- each player's own deck comes back distinct, per BoardStateRepository::load()'s own identical split. */
    public function testGenesisSplitsSeparateDecksInADuelGameWithNoEventsAtAll(): void
    {
        $u1 = $this->insertUser('replayduel1');
        $u2 = $this->insertUser('replayduel2');
        $gameId = $this->insertGame('duel', 'in_progress', $u1);

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $p1Hand = $this->insertGameCard($gameId, 106, 'hand', $p1);
        $p1Deck = $this->insertGameCard($gameId, 9, 'deck', $p1, 0);
        $p2Hand = $this->insertGameCard($gameId, 3, 'hand', $p2);
        $p2Deck = $this->insertGameCard($gameId, 56, 'deck', $p2, 0);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);
        $this->markCompleted($gameId);

        $genesis = $this->replay->genesis($gameId);

        self::assertSame([$p1Hand], $genesis->hand($p1));
        self::assertSame([$p1Deck], $genesis->deck($p1));
        self::assertSame([$p2Hand], $genesis->hand($p2));
        self::assertSame([$p2Deck], $genesis->deck($p2));
    }

    /**
     * closed_team's own blind initial card pass (submitInitialCardPass()/
     * transferHandCards()) bypasses BoardState/game_events entirely and
     * completes strictly before any event is ever logged -- so whatever
     * hand ownership game_cards shows by the time the first event exists
     * is already what genesis should reflect, with zero special-casing.
     * Simulated directly here (rather than actually calling
     * submitInitialCardPass(), which needs a full team setup) by simply
     * dealing the "already swapped" hands and confirming genesis doesn't
     * try to undo an ownership change that was never logged as one.
     */
    public function testGenesisReflectsWhateverHandOwnershipExistsBeforeTheFirstEventRegardlessOfFormat(): void
    {
        $u1 = $this->insertUser('replayteam1');
        $u2 = $this->insertUser('replayteam2');
        $gameId = $this->insertGame('closed_team', 'in_progress', $u1);

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        // "Post-pass" hands -- whichever card ended up with whichever
        // player, from genesis's point of view this is simply what was
        // dealt, exactly like any other format.
        $p1Hand = $this->insertGameCard($gameId, 106, 'hand', $p1);
        $p2Hand = $this->insertGameCard($gameId, 3, 'hand', $p2);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);
        $this->markCompleted($gameId);

        $genesis = $this->replay->genesis($gameId);

        self::assertSame([$p1Hand], $genesis->hand($p1));
        self::assertSame([$p2Hand], $genesis->hand($p2));
    }

    /**
     * Issue #240: event id 0 is stateAsOf()'s own sentinel for genesis --
     * the frontend's "Step 1" (round-1's dealt hands, before any real
     * event exists), letting the same replayEvents/getReplayGameState()
     * plumbing show it without a separate code path.
     */
    public function testStateAsOfWithEventIdZeroReturnsGenesis(): void
    {
        $u1 = $this->insertUser('replaysentinel1');
        $u2 = $this->insertUser('replaysentinel2');
        $gameId = $this->insertGame('standard', 'in_progress', $u1);

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);

        $dignityId = $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->games->playMood($gameId, $p1, $dignityId, []);
        $this->markCompleted($gameId);

        $viaSentinel = $this->replay->stateAsOf($gameId, 0);
        $viaGenesis = $this->replay->genesis($gameId);

        self::assertSame([$dignityId], $viaSentinel->hand($p1), 'event id 0 shows the original dealt hand, not the post-play one');
        self::assertSame($viaGenesis->hand($p1), $viaSentinel->hand($p1));
        self::assertSame($viaGenesis->deck(), $viaSentinel->deck());
        self::assertSame([], $viaSentinel->moodsInPlay());
    }

    public function testStateAsOfRejectsANonCompletedGame(): void
    {
        $u1 = $this->insertUser('replayreject1');
        $u2 = $this->insertUser('replayreject2');
        $gameId = $this->insertGame('standard', 'in_progress', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);
        $this->insertGameCard($gameId, 106, 'hand', $p1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $this->expectException(GameStateException::class);
        $this->replay->stateAsOf($gameId, 1);
    }

    public function testStateAsOfRejectsAnEventIdFromADifferentGame(): void
    {
        $u1 = $this->insertUser('replayreject3');
        $u2 = $this->insertUser('replayreject4');

        $gameOneId = $this->insertGame('standard', 'in_progress', $u1);
        $p1 = $this->insertGamePlayer($gameOneId, $u1, 0);
        $this->insertGamePlayer($gameOneId, $u2, 1);
        $charityId = $this->insertGameCard($gameOneId, 3, 'hand', $p1);
        $this->insertGameRound($gameOneId, 1, $p1, $p1, 1);
        $this->games->playMood($gameOneId, $p1, $charityId, []);
        $this->markCompleted($gameOneId);
        $eventFromGameOne = $this->games->fullEventLog($gameOneId)[0]['id'];

        $gameTwoId = $this->insertGame('standard', 'in_progress', $u1);
        $p1Two = $this->insertGamePlayer($gameTwoId, $u1, 0);
        $this->insertGamePlayer($gameTwoId, $u2, 1);
        $this->insertGameCard($gameTwoId, 106, 'hand', $p1Two);
        $this->insertGameRound($gameTwoId, 1, $p1Two, $p1Two, 1);
        $this->markCompleted($gameTwoId);

        $this->expectException(GameStateException::class);
        $this->replay->stateAsOf($gameTwoId, $eventFromGameOne);
    }
}
