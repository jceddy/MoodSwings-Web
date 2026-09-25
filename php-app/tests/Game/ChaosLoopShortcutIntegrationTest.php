<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\ChaosDefaultEffectRegistry;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #192 follow-up: some Chaos Draft effects (chaos_026/037/040/089/
 * 011/024/016/120 -- see ChaosLoopShortcut::REGISTERED_EFFECT_KEYS) can
 * reactively spawn a token/draw a card/boost a mood's value on every
 * cycle of an otherwise-perpetual same-turn loop (Thrill<->Fear and
 * friends -- see BoardState::turnStateSignature()'s own docblock), which
 * makes the EXACT board signature different every cycle and so defeats
 * the ordinary warn-at-3/auto-pass-at-4 safety net entirely. This needs
 * the REAL chaos registry (unlike GameServiceIntegrationTest's own empty
 * default), so chaos_026's/chaos_037's actual implementations are
 * reachable via playMood() -- see ChaosDraftAttachedEffectChoiceIntegrationTest's
 * own docblock for the same reasoning.
 */
final class ChaosLoopShortcutIntegrationTest extends TestCase
{
    private PDO $pdo;
    private GameService $games;

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
        $pdo->exec('TRUNCATE TABLE chaos_draft_offers');
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
        $chaosRegistry = ChaosDefaultEffectRegistry::build();
        $userDecklists = new UserDecklistService(
            new UserDecklistRepository(),
            new FriendshipService(new UserRepository(), new FriendshipRepository()),
        );
        $this->games = new GameService(
            new BoardStateRepository($registry, $chaosRegistry),
            new MoodPlayService($registry, $chaosRegistry),
            new RoundScorer(),
            $userDecklists,
            new ReplayStateBuilder($registry),
            chaosRegistry: $chaosRegistry,
            spawnAutomatedTurnRecheckProcesses: false,
        );
    }

    private function insertUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at) VALUES (:username, :email, 'hash', NOW())"
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
            'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, deck_position) VALUES (:game_id, :card_id, :zone, :owner, :deck_position)'
        );
        $stmt->execute(['game_id' => $gameId, 'card_id' => $cardId, 'zone' => $zone, 'owner' => $owner, 'deck_position' => $deckPosition]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGameRound(int $gameId, int $roundNumber, int $firstPlayerId, int $currentTurnPlayerId, int $playsRemaining): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, status)
             VALUES (:game_id, :round_number, :first_player, :current_turn, :plays_remaining, 'in_progress')"
        );
        $stmt->execute([
            'game_id' => $gameId,
            'round_number' => $roundNumber,
            'first_player' => $firstPlayerId,
            'current_turn' => $currentTurnPlayerId,
            'plays_remaining' => $playsRemaining,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function fetchRound(int $gameId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM game_rounds WHERE game_id = :game_id AND status = 'in_progress' ORDER BY round_number DESC LIMIT 1"
        );
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetch();
    }

    private function fetchGameEvents(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_events WHERE game_id = :game_id ORDER BY id ASC');
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetchAll();
    }

    private function countInPlay(int $gameId, int $cardId, int $ownerId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM game_cards WHERE game_id = :game_id AND card_id = :card_id AND zone = 'in_play' AND owner_game_player_id = :owner");
        $stmt->execute(['game_id' => $gameId, 'card_id' => $cardId, 'owner' => $ownerId]);

        return (int) $stmt->fetchColumn();
    }

    private function chaosEffectId(string $effectKey): int
    {
        $id = (int) $this->pdo->query("SELECT id FROM chaos_effects WHERE effect_key = '{$effectKey}'")->fetchColumn();
        self::assertGreaterThan(0, $id, "migration 0183 should have seeded {$effectKey}");

        return $id;
    }

    private function attachChaosEffect(int $gameCardId, string $effectKey): void
    {
        $this->pdo->prepare('UPDATE game_cards SET chaos_effect_id = :chaos_effect_id WHERE id = :id')
            ->execute(['chaos_effect_id' => $this->chaosEffectId($effectKey), 'id' => $gameCardId]);
    }

    /**
     * Thrill (103, red, value 1) <-> Fear (38, blue, value 0), the same
     * base loop GameServiceIntegrationTest's own buildThrillFearLoopFixture()
     * uses -- plus a third, inert in-play mood (Apathy, 55, base value
     * 4, no printed ability) owned by the same player, carrying
     * $effectKey. Apathy itself never qualifies for chaos_026's own 0-
     * or-1 condition, so it just sits there as the effect's own carrier.
     *
     * @return array{gameId: int, p1: int, p2: int, thrillId: int, fearId: int}
     */
    private function buildComboLoopFixture(string $effectKey): array
    {
        $u1 = $this->insertUser('chaos-loop-p1');
        $u2 = $this->insertUser('chaos-loop-p2');

        // deck_type stays 'standard', not 'chaos_draft' -- attaching a
        // chaos_effects row to a game_cards.chaos_effect_id works at the
        // engine level regardless of deck_type (dispatchChaosReactiveHooks()
        // dispatches purely off whether a card carries one), and
        // 'chaos_draft' would additionally require resolving this round's
        // own chaos_draft_offers row before anyone could play at all --
        // an unrelated mechanic this fixture has no reason to set up.
        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed) VALUES ('standard', 'structure', 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['created_by' => $u1]);
        $gameId = (int) $this->pdo->lastInsertId();

        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $p2 = $this->insertGamePlayer($gameId, $u2, 1);

        $thrillId = $this->insertGameCard($gameId, 103, 'hand', $p1);
        $fearId = $this->insertGameCard($gameId, 38, 'in_play', $p1);
        $apathyId = $this->insertGameCard($gameId, 55, 'in_play', $p1);
        $this->attachChaosEffect($apathyId, $effectKey);

        // A generous filler deck -- chaos_037's own "draw a card" fires
        // every single cycle, so the fixture needs enough real cards to
        // draw through both the natural firings AND whatever count the
        // shortcut itself later applies. format='standard' means a
        // SHARED deck (BoardStateRepository's own $hasSeparateDecks is
        // only true for 'duel'/'draft'), so these rows need owner_game_player_id
        // NULL, not $p1 -- an owned 'deck' row here would silently be
        // invisible to BoardState::deck()/drawCard() for a shared-deck
        // game, since load() only buckets NULL-owner deck rows into the
        // shared pool.
        for ($i = 0; $i < 20; $i++) {
            $this->insertGameCard($gameId, 74, 'deck', null, $i); // Sadness, inert filler
        }

        $this->insertGameCard($gameId, 74, 'hand', $p2); // inert placeholder so p2's own eventual turn does nothing surprising

        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        return ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'thrillId' => $thrillId, 'fearId' => $fearId];
    }

    public function testTokenEffectComboOffersAShortcutTheExactSignatureNeverWouldAndApplyingItEndsTheTurn(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'thrillId' => $thrillId, 'fearId' => $fearId] = $this->buildComboLoopFixture('chaos_026');

        // Thrill (value 1) and Fear (value 0) both qualify for chaos_026's
        // own "0 or 1 in the top right corner" condition, so EVERY single
        // cycle below spawns a fresh Smugness token -- meaning the exact
        // signature is provably different every time (BoardState::
        // turnStateSignature() includes every in-play mood, tokens
        // included) and loop_warning must never fire, no matter how many
        // cycles run.
        $this->games->playMood($gameId, $p1, $thrillId, ['hand_mood_ids' => [$fearId]]); // Thrill-shape occ1, fire=1
        $this->games->playMood($gameId, $p1, $fearId, ['hand_mood_id' => $thrillId]);    // Fear-shape occ1, fire=2
        $this->games->playMood($gameId, $p1, $thrillId, ['hand_mood_ids' => [$fearId]]); // Thrill-shape occ2, fire=3
        self::assertNull($this->games->getState($gameId, $p1)['game']['loop_warning'], 'the exact signature never repeats -- new tokens keep entering play');
        self::assertNull($this->games->getState($gameId, $p1)['game']['chaos_loop_shortcut'], 'coarse count for the Thrill-shape is only 2 so far');

        $this->games->playMood($gameId, $p1, $fearId, ['hand_mood_id' => $thrillId]);    // Fear-shape occ2, fire=4
        $this->games->playMood($gameId, $p1, $thrillId, ['hand_mood_ids' => [$fearId]]); // Thrill-shape occ3 (coarse=3) AND fire=5 -- both thresholds now cross

        self::assertNull($this->games->getState($gameId, $p1)['game']['loop_warning'], 'still never an exact repeat');
        $shortcut = $this->games->getState($gameId, $p1)['game']['chaos_loop_shortcut'];
        self::assertNotNull($shortcut, 'the coarse signature (tokens normalized out) DID repeat a 3rd time, combined with chaos_026 having fired 3+ times');
        self::assertSame($p1, $shortcut['game_player_id']);
        self::assertSame('token', $shortcut['kind']);
        self::assertSame(16, $shortcut['cap']);
        self::assertStringContainsString('Smugness', $shortcut['label']);

        $tokensBefore = $this->countInPlay($gameId, 134, $p1);
        $this->games->applyChaosLoopShortcut($gameId, $p1, 5);

        self::assertSame($tokensBefore + 5, $this->countInPlay($gameId, 134, $p1), 'the shortcut spawns exactly the chosen count, once, regardless of any per-firing scaling');
        self::assertSame($p2, (int) $this->fetchRound($gameId)['current_turn_game_player_id'], 'a token shortcut ends the turn immediately once applied');
        self::assertNull($this->games->getState($gameId, $p2)['game']['chaos_loop_shortcut'], 'the fresh turn resets the standing offer');

        $events = array_values(array_filter($this->fetchGameEvents($gameId), static fn (array $e): bool => $e['event_type'] === 'chaos_loop_shortcut_applied'));
        self::assertCount(1, $events);
        $details = json_decode((string) $events[0]['details'], true);
        self::assertSame('chaos_026', $details['effect_key']);
        self::assertSame('token', $details['kind']);
        self::assertSame(5, $details['count']);
    }

    public function testDrawEffectComboLeavesTheTurnOpenThenAutoPassesOnASecondDetectionAfterTheShortcut(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2, 'thrillId' => $thrillId, 'fearId' => $fearId] = $this->buildComboLoopFixture('chaos_037');

        // chaos_037 ("each time you play another mood, draw a card") has
        // no value condition at all, so this fires on every single cycle
        // regardless of which of Thrill/Fear is played.
        $this->games->playMood($gameId, $p1, $thrillId, ['hand_mood_ids' => [$fearId]]); // Thrill-shape occ1, fire=1
        $this->games->playMood($gameId, $p1, $fearId, ['hand_mood_id' => $thrillId]);    // Fear-shape occ1, fire=2
        $this->games->playMood($gameId, $p1, $thrillId, ['hand_mood_ids' => [$fearId]]); // Thrill-shape occ2, fire=3
        $this->games->playMood($gameId, $p1, $fearId, ['hand_mood_id' => $thrillId]);    // Fear-shape occ2, fire=4
        $this->games->playMood($gameId, $p1, $thrillId, ['hand_mood_ids' => [$fearId]]); // Thrill-shape occ3 (coarse=3), fire=5 -- offer made

        $shortcut = $this->games->getState($gameId, $p1)['game']['chaos_loop_shortcut'];
        self::assertNotNull($shortcut);
        self::assertSame('draw', $shortcut['kind']);
        self::assertSame(15, $shortcut['cap'], '20-card deck minus the 5 cards chaos_037 already drew');

        $handBefore = count($this->games->getState($gameId, $p1)['you']['hand']);
        $this->games->applyChaosLoopShortcut($gameId, $p1, 3);

        $stateAfterShortcut = $this->games->getState($gameId, $p1);
        self::assertSame($handBefore + 3, count($stateAfterShortcut['you']['hand']), 'the draw shortcut adds cards to hand');
        self::assertSame($p1, (int) $this->fetchRound($gameId)['current_turn_game_player_id'], 'a draw shortcut, unlike token/value_boost, does NOT end the turn');
        self::assertNull($stateAfterShortcut['game']['chaos_loop_shortcut'], 'the standing offer is cleared once applied');

        // The player kept looping anyway despite already having been
        // offered (and used) a shortcut this turn -- this must NOT offer
        // a second one; it auto-passes instead, the same "warn once, then
        // don't trust them to stop" posture the ordinary exact-signature
        // auto-pass already has.
        $this->games->playMood($gameId, $p1, $fearId, ['hand_mood_id' => $thrillId]); // Fear-shape occ3 (coarse=3 again) -- already offered once this turn

        $round = $this->fetchRound($gameId);
        self::assertSame($p2, (int) $round['current_turn_game_player_id'], 'looping again after already being offered a shortcut this turn ends the turn');

        $turnPassedEvents = array_values(array_filter($this->fetchGameEvents($gameId), static fn (array $e): bool => $e['event_type'] === 'turn_passed'));
        self::assertCount(1, $turnPassedEvents);
        $details = json_decode((string) $turnPassedEvents[0]['details'], true);
        self::assertTrue($details['automated']);
        self::assertSame('chaos_loop_detected', $details['reason']);
    }

    public function testBotAutoAppliesTheMaximumOfferedCountImmediately(): void
    {
        ['gameId' => $gameId, 'p1' => $p1, 'p2' => $p2] = $this->buildComboLoopFixture('chaos_026');
        $this->pdo->prepare('UPDATE users SET is_bot = 1 WHERE id = (SELECT user_id FROM game_players WHERE id = :p1)')->execute(['p1' => $p1]);

        // Seed an already-resolved standing offer directly, the same
        // "skip the natural trigger sequence, test the apply/bot-resolution
        // path in isolation" approach GameServiceIntegrationTest's own
        // testABotAvoidsWastingItsTurnOnAFourthThrillFearOccurrenceItCouldStillUse()
        // already uses for the exact-signature case.
        $this->pdo->prepare('UPDATE game_rounds SET chaos_loop_state = :state WHERE game_id = :game_id')->execute([
            'state' => json_encode([
                'coarseCounts' => [],
                'drawnCardIds' => [],
                'chaosEffectFireCounts' => ['chaos_026' => 3],
                'offeredThisTurn' => true,
                'pendingOffer' => [
                    'effectKey' => 'chaos_026',
                    'gamePlayerId' => $p1,
                    'kind' => 'token',
                    'cap' => 16,
                    'label' => 'Create up to 16 Smugness tokens, then end your turn.',
                    'tokenCatalogCardId' => 134,
                    'forPlayerId' => $p1,
                    'valueBoostTargetCardId' => null,
                ],
            ]),
            'game_id' => $gameId,
        ]);

        $tokensBefore = $this->countInPlay($gameId, 134, $p1);
        $this->games->advanceAutomatedTurns($gameId);

        self::assertSame($tokensBefore + 16, $this->countInPlay($gameId, 134, $p1), 'a bot always takes the maximum allowed count -- no downside within this shortcut\'s own bounded action');
        self::assertSame($p2, (int) $this->fetchRound($gameId)['current_turn_game_player_id']);
    }
}
