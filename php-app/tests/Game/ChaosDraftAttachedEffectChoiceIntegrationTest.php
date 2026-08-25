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

        $this->games->playMood(1, $player1, $apathyCardId, ['chaos' => ['value' => 4]]);

        $complacencyRow = $this->pdo->query('SELECT zone, owner_game_player_id FROM game_cards WHERE card_id = 5')->fetch();
        self::assertSame('hand', $complacencyRow['zone'], "chaos_035's chosen-value effect should have returned Complacency to hand");
        self::assertSame($player2, (int) $complacencyRow['owner_game_player_id']);
    }
}
