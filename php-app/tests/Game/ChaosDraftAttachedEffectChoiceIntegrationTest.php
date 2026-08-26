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
 * End-to-end replay of the exact bug reported for issue #405: "I added
 * 'Rare Chaos effect: After playing this mood -- You may choose a number.
 * If you do, put all other moods with the chosen value into their
 * players' hands.' to a card, but was never prompted to choose a
 * number." (chaos_035). Root cause was GameService::serializeCard() never
 * exposing an attached chaos effect's own choice fields -- see
 * ChaosCardChoiceSchema's docblock. This needs the REAL chaos registry
 * (unlike ChaosDraftOfferIntegrationTest.php, which only exercises the
 * offer/attach mechanic and can get away with an empty one) so
 * chaos_035's actual implementation is reachable via playMood().
 */
final class ChaosDraftAttachedEffectChoiceIntegrationTest extends TestCase
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
        );
    }

    public function testPlayingACardWithAnAttachedChaosEffectAppliesTheChosenChoice(): void
    {
        $this->pdo->prepare(
            "INSERT INTO users (id, username, email, password_hash, email_verified_at) VALUES
                (1, 'alice', 'alice@example.com', 'hash', NOW()),
                (2, 'bob', 'bob@example.com', 'hash', NOW())"
        )->execute();
        $this->pdo->prepare(
            "INSERT INTO games (id, format, deck_type, status, created_by_user_id) VALUES (1, 'draft', 'chaos_draft', 'in_progress', 1)"
        )->execute();
        $this->pdo->prepare('INSERT INTO game_players (game_id, user_id, seat_order) VALUES (1, 1, 1)')->execute();
        $player1 = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO game_players (game_id, user_id, seat_order) VALUES (1, 2, 2)')->execute();
        $player2 = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
             VALUES (1, 1, :first, :first, 1, '[null]', 'in_progress')"
        )->execute(['first' => $player1]);

        // Apathy (catalog id 55, base value 4, no printed ability) in
        // player 1's hand, with chaos_035 attached.
        $this->pdo->prepare('INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (1, 55, "hand", :owner)')
            ->execute(['owner' => $player1]);
        $apathyCardId = (int) $this->pdo->lastInsertId();
        $chaos035Id = (int) $this->pdo->query("SELECT id FROM chaos_effects WHERE effect_key = 'chaos_035'")->fetchColumn();
        self::assertGreaterThan(0, $chaos035Id, 'migration 0183 should have seeded chaos_035');
        $this->pdo->prepare('UPDATE game_cards SET chaos_effect_id = :chaos_effect_id WHERE id = :id')
            ->execute(['chaos_effect_id' => $chaos035Id, 'id' => $apathyCardId]);

        // Complacency (catalog id 5): another flat-4, no-ability mood,
        // already in play and owned by player 2 -- exactly what
        // chaos_035's "moods with the chosen value" should catch once
        // player 1 plays Apathy and chooses value 4.
        $this->pdo->prepare('INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (1, 5, "in_play", :owner)')
            ->execute(['owner' => $player2]);

        // Sanity check (the bug this test guards against): before the
        // fix, choice_fields carried no 'chaos' entry at all, so a
        // frontend submission could never have included this key.
        $state = $this->games->getState(1, 1);
        $apathyField = null;
        foreach ($state['you']['hand'] as $card) {
            if ($card['card_id'] === $apathyCardId) {
                $apathyField = $card;
            }
        }
        self::assertNotNull($apathyField);
        $chaosField = null;
        foreach ($apathyField['choice_fields'] as $field) {
            if ($field['key'] === 'chaos') {
                $chaosField = $field;
            }
        }
        self::assertNotNull($chaosField, 'serializeCard() should expose a chaos choice field for the attached chaos_035 effect');

        // Issue #405 follow-up: playMood() now also requires this round's
        // OWN chaos_draft_offers row resolved (see
        // GameService::assertChaosDraftOfferResolved()) -- a completely
        // separate mechanic from chaos_035 itself being attached above.
        // Rolled and marked resolved directly (not via chooseChaosDraftEffect(),
        // which would attach a second, unrelated effect on top of the
        // one already manually attached for this test) so it doesn't
        // interfere with the scenario under test.
        $this->games->chaosDraftOfferFor(1, $player1);
        $this->pdo->prepare(
            'UPDATE chaos_draft_offers SET resolved_at = NOW() WHERE game_round_id = (SELECT id FROM game_rounds WHERE game_id = 1) AND game_player_id = :player'
        )->execute(['player' => $player1]);

        $this->games->playMood(1, $player1, $apathyCardId, ['chaos' => ['value' => 4]]);

        $complacencyRow = $this->pdo->query('SELECT zone, owner_game_player_id FROM game_cards WHERE card_id = 5')->fetch();
        self::assertSame('hand', $complacencyRow['zone'], "chaos_035's chosen-value effect should have returned Complacency to hand");
        self::assertSame($player2, (int) $complacencyRow['owner_game_player_id']);
    }

    /**
     * A bug caught live, reported for chaos_102: "At the start of each of
     * your turns, if another player has more moods than you, you may play
     * an additional mood this turn" was incorrectly granting the extra
     * play the instant the carrying mood was played. The qualifying
     * condition (player 2 already has more moods than player 1) is
     * deliberately true from the very start, so the same-turn grant would
     * still fire if the bug regressed. (The OTHER half -- that the grant
     * DOES apply once the owner's own next turn genuinely starts -- is
     * unaffected by this fix, since computeFreshGrants() is a separate,
     * untouched call site; see this engine's own "exactly one turn per
     * player per round, never wrapping" rule in advanceTurn(), which would
     * make proving that half here mean crossing into a whole new round.
     * ChaosDraftReactiveEffectsTest's own BoardState-level test covers
     * this same no-immediate-grant assertion more directly; this is the
     * fuller-pipeline sanity check.)
     */
    public function testChaos102DoesNotGrantAnExtraPlayTheSameTurnItIsPlayed(): void
    {
        $this->pdo->prepare(
            "INSERT INTO users (id, username, email, password_hash, email_verified_at) VALUES
                (1, 'alice', 'alice@example.com', 'hash', NOW()),
                (2, 'bob', 'bob@example.com', 'hash', NOW())"
        )->execute();
        $this->pdo->prepare(
            "INSERT INTO games (id, format, deck_type, status, created_by_user_id) VALUES (1, 'draft', 'chaos_draft', 'in_progress', 1)"
        )->execute();
        $this->pdo->prepare('INSERT INTO game_players (game_id, user_id, seat_order) VALUES (1, 1, 1)')->execute();
        $player1 = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO game_players (game_id, user_id, seat_order) VALUES (1, 2, 2)')->execute();
        $player2 = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
             VALUES (1, 1, :first, :first, 1, '[null]', 'in_progress')"
        )->execute(['first' => $player1]);

        // Indifference (catalog id 44, base value 4, no printed ability) in
        // player 1's hand, with chaos_102 attached.
        $this->pdo->prepare('INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (1, 44, "hand", :owner)')
            ->execute(['owner' => $player1]);
        $indifferenceCardId = (int) $this->pdo->lastInsertId();
        $chaos102Id = (int) $this->pdo->query("SELECT id FROM chaos_effects WHERE effect_key = 'chaos_102'")->fetchColumn();
        self::assertGreaterThan(0, $chaos102Id, 'migration 0183 should have seeded chaos_102');
        $this->pdo->prepare('UPDATE game_cards SET chaos_effect_id = :chaos_effect_id WHERE id = :id')
            ->execute(['chaos_effect_id' => $chaos102Id, 'id' => $indifferenceCardId]);

        // Player 2 already has 2 moods in play (Complacency, Apathy) --
        // chaos_102's own "another player has more moods than you"
        // condition is true from the very start, with an empty hand of
        // their own so they need no chaos offer of their own to resolve.
        $this->pdo->prepare('INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (1, 5, "in_play", :owner)')
            ->execute(['owner' => $player2]);
        $this->pdo->prepare('INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (1, 55, "in_play", :owner)')
            ->execute(['owner' => $player2]);

        $this->games->chaosDraftOfferFor(1, $player1);
        $this->pdo->prepare(
            'UPDATE chaos_draft_offers SET resolved_at = NOW() WHERE game_round_id = (SELECT id FROM game_rounds WHERE game_id = 1) AND game_player_id = :player'
        )->execute(['player' => $player1]);

        $this->games->playMood(1, $player1, $indifferenceCardId, []);

        // Player 1's only play this turn is now spent -- the turn should
        // already have auto-advanced to player 2, NOT still be sitting on
        // an extra play chaos_102 wrongly granted immediately.
        $round = $this->pdo->query('SELECT current_turn_game_player_id, plays_remaining FROM game_rounds WHERE game_id = 1')->fetch();
        self::assertSame($player2, (int) $round['current_turn_game_player_id'], 'chaos_102 should not have granted an extra play this same turn');
        self::assertSame(1, (int) $round['plays_remaining'], "player 2's own normal turn, unaffected by player 1's chaos_102");
    }
}
