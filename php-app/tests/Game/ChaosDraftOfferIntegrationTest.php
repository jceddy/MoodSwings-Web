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
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Chaos Draft (issue #405) stage 1's own round-start choice/attach
 * mechanic -- GameService::chaosDraftOfferFor()/chooseChaosDraftEffect()/
 * proposeChaosDraftEffect()/confirmChaosDraftEffect(). Built directly
 * against raw games/game_players/game_rounds/game_cards rows (the same
 * pattern BoardStateRepositoryChaosDraftTest.php already uses) rather
 * than through GameService::createGame(), since that method doesn't
 * accept the 'chaos_draft' deck_type yet (a separate, later stage of
 * issue #405) -- this file tests the mechanic in isolation from that
 * wiring.
 */
final class ChaosDraftOfferIntegrationTest extends TestCase
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
        $userDecklists = new UserDecklistService(
            new UserDecklistRepository(),
            new FriendshipService(new UserRepository(), new FriendshipRepository()),
        );
        $this->games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            $userDecklists,
            new ReplayStateBuilder($registry),
        );
    }

    /**
     * @param string $format 'draft' (individual), 'closed_team', or 'team'
     * @param int[] $handCatalogCardIdsByPlayer game_player seat_order (1-indexed) => catalog card ids to deal into that seat's hand
     * @return int[] game_player_id by seat_order
     */
    private function makeChaosDraftGame(string $format, array $handCatalogCardIdsByPlayer, ?array $teamIdBySeat = null): array
    {
        $this->pdo->prepare(
            "INSERT INTO users (id, username, email, password_hash, email_verified_at) VALUES
                (1, 'alice', 'alice@example.com', 'hash', NOW()),
                (2, 'bob', 'bob@example.com', 'hash', NOW()),
                (3, 'carol', 'carol@example.com', 'hash', NOW()),
                (4, 'dave', 'dave@example.com', 'hash', NOW())"
        )->execute();

        $this->pdo->prepare(
            "INSERT INTO games (id, format, deck_type, status, created_by_user_id) VALUES (1, :format, 'chaos_draft', 'in_progress', 1)"
        )->execute(['format' => $format]);

        $playerIds = [];
        foreach ($handCatalogCardIdsByPlayer as $seat => $catalogCardIds) {
            $teamId = $teamIdBySeat[$seat] ?? null;
            $stmt = $this->pdo->prepare(
                'INSERT INTO game_players (game_id, user_id, seat_order, team_id) VALUES (1, :user_id, :seat, :team_id)'
            );
            $stmt->execute(['user_id' => $seat, 'seat' => $seat, 'team_id' => $teamId]);
            $playerId = (int) $this->pdo->lastInsertId();
            $playerIds[$seat] = $playerId;

            foreach ($catalogCardIds as $catalogCardId) {
                $this->pdo->prepare(
                    'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (1, :card_id, "hand", :owner)'
                )->execute(['card_id' => $catalogCardId, 'owner' => $playerId]);
            }
        }

        $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
             VALUES (1, 1, :first, :first, 1, '[null]', 'in_progress')"
        )->execute(['first' => $playerIds[1]]);

        return $playerIds;
    }

    public function testOfferIsCreatedAndReturnedForAPlayerWithCardsInHand(): void
    {
        $players = $this->makeChaosDraftGame('draft', [1 => [3], 2 => [55]]); // hand cards irrelevant to which catalog player 1/2 map to here

        $offer = $this->games->chaosDraftOfferFor(1, $players[1]);

        self::assertNotNull($offer);
        self::assertArrayHasKey('id', $offer['effect_1']);
        self::assertArrayHasKey('id', $offer['effect_2']);
        self::assertNotSame($offer['effect_1']['id'], $offer['effect_2']['id']);
        self::assertFalse($offer['is_team_offer']);
    }

    public function testNoOfferForAPlayerWithAnEmptyHand(): void
    {
        $players = $this->makeChaosDraftGame('draft', [1 => [3], 2 => []]);

        self::assertNull($this->games->chaosDraftOfferFor(1, $players[2]));
    }

    public function testOfferIsStableAcrossRepeatedCallsTheSameRound(): void
    {
        $players = $this->makeChaosDraftGame('draft', [1 => [3], 2 => [55]]);

        $first = $this->games->chaosDraftOfferFor(1, $players[1]);
        $second = $this->games->chaosDraftOfferFor(1, $players[1]);

        self::assertSame($first['effect_1']['id'], $second['effect_1']['id']);
        self::assertSame($first['effect_2']['id'], $second['effect_2']['id']);
    }

    public function testChoosingAttachesTheEffectAndResolvesTheOffer(): void
    {
        $players = $this->makeChaosDraftGame('draft', [1 => [3], 2 => [55]]);
        $offer = $this->games->chaosDraftOfferFor(1, $players[1]);
        $handCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[1] . ' AND zone = "hand"')->fetch();
        $handCardId = (int) $handCardRow['id'];

        $this->games->chooseChaosDraftEffect(1, $players[1], $offer['effect_1']['id'], $handCardId);

        $row = $this->pdo->query("SELECT chaos_effect_id FROM game_cards WHERE id = {$handCardId}")->fetch();
        self::assertSame($offer['effect_1']['id'], (int) $row['chaos_effect_id']);

        self::assertNull($this->games->chaosDraftOfferFor(1, $players[1]));
    }

    /**
     * A bug caught live (same shape as GameServiceIntegrationTest's own
     * testClosedTeamLeaderDecisionIsLoggedAsBeingChosenNotAsAPlay()):
     * describeEvent() never got a case added for
     * 'chaos_draft_effect_attached', so it fell through to the generic
     * "{actor} played {card}" default -- misleadingly rendering as though
     * the card had just been PLAYED, when attaching a chaos effect never
     * moves it out of hand at all.
     */
    public function testChoosingAnEffectIsLoggedAsAttachingNotAsAPlay(): void
    {
        $players = $this->makeChaosDraftGame('draft', [1 => [3], 2 => [55]]);
        $offer = $this->games->chaosDraftOfferFor(1, $players[1]);
        $handCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[1] . ' AND zone = "hand"')->fetch();
        $handCardId = (int) $handCardRow['id'];

        $this->games->chooseChaosDraftEffect(1, $players[1], $offer['effect_1']['id'], $handCardId);

        $rarity = $this->pdo->query("SELECT rarity FROM chaos_effects WHERE id = {$offer['effect_1']['id']}")->fetchColumn();

        $entry = $this->games->fullEventLog(1)[0];
        self::assertSame('chaos_draft_effect_attached', $entry['event_type']);
        self::assertSame($handCardId, $entry['card_id']);
        $article = $rarity === 'uncommon' ? 'an' : 'a';
        self::assertSame("alice attached {$article} {$rarity} chaos effect to Charity", $entry['description']);
    }

    public function testChoosingAnEffectNotOfferedIsRejected(): void
    {
        $players = $this->makeChaosDraftGame('draft', [1 => [3], 2 => [55]]);
        $this->games->chaosDraftOfferFor(1, $players[1]);
        $handCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[1] . ' AND zone = "hand"')->fetch();

        $this->expectException(GameStateException::class);
        $this->games->chooseChaosDraftEffect(1, $players[1], 9999, (int) $handCardRow['id']);
    }

    public function testChoosingACardNotInHandIsRejected(): void
    {
        $players = $this->makeChaosDraftGame('draft', [1 => [3], 2 => [55]]);
        $offer = $this->games->chaosDraftOfferFor(1, $players[1]);
        $opponentHandCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[2] . ' AND zone = "hand"')->fetch();

        $this->expectException(GameStateException::class);
        $this->games->chooseChaosDraftEffect(1, $players[1], $offer['effect_1']['id'], (int) $opponentHandCardRow['id']);
    }

    public function testOpenTeamPlayRequiresProposeThenConfirm(): void
    {
        $players = $this->makeChaosDraftGame('team', [1 => [3], 2 => [55], 3 => [8], 4 => [33]], teamIdBySeat: [1 => 0, 2 => 1, 3 => 0, 4 => 1]);

        $offer = $this->games->chaosDraftOfferFor(1, $players[1]);
        self::assertNotNull($offer);
        self::assertTrue($offer['is_team_offer']);
        self::assertSame('propose', $offer['phase']);

        // Same offer visible to both teammates (seats 1 and 3, team 0).
        $teammateOffer = $this->games->chaosDraftOfferFor(1, $players[3]);
        self::assertSame($offer['effect_1']['id'], $teammateOffer['effect_1']['id']);

        $ownHandCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[1] . ' AND zone = "hand"')->fetch();
        $this->games->proposeChaosDraftEffect(1, $players[1], $offer['effect_1']['id'], (int) $ownHandCardRow['id']);

        // Not yet attached -- awaiting the other teammate's confirmation.
        self::assertNull($this->pdo->query("SELECT chaos_effect_id FROM game_cards WHERE id = {$ownHandCardRow['id']}")->fetch()['chaos_effect_id']);

        $this->games->confirmChaosDraftEffect(1, $players[3], true);

        $row = $this->pdo->query("SELECT chaos_effect_id FROM game_cards WHERE id = {$ownHandCardRow['id']}")->fetch();
        self::assertSame($offer['effect_1']['id'], (int) $row['chaos_effect_id']);
        self::assertNull($this->games->chaosDraftOfferFor(1, $players[1]));
    }

    public function testOpenTeamPlayCanAttachToEitherTeammatesHand(): void
    {
        $players = $this->makeChaosDraftGame('team', [1 => [3], 2 => [55], 3 => [8], 4 => [33]], teamIdBySeat: [1 => 0, 2 => 1, 3 => 0, 4 => 1]);
        $offer = $this->games->chaosDraftOfferFor(1, $players[1]);

        $teammateHandCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[3] . ' AND zone = "hand"')->fetch();
        $this->games->proposeChaosDraftEffect(1, $players[1], $offer['effect_1']['id'], (int) $teammateHandCardRow['id']);
        $this->games->confirmChaosDraftEffect(1, $players[3], true);

        $row = $this->pdo->query("SELECT chaos_effect_id FROM game_cards WHERE id = {$teammateHandCardRow['id']}")->fetch();
        self::assertSame($offer['effect_1']['id'], (int) $row['chaos_effect_id']);
    }

    public function testTheProposerCannotAlsoConfirm(): void
    {
        $players = $this->makeChaosDraftGame('team', [1 => [3], 2 => [55], 3 => [8], 4 => [33]], teamIdBySeat: [1 => 0, 2 => 1, 3 => 0, 4 => 1]);
        $offer = $this->games->chaosDraftOfferFor(1, $players[1]);
        $ownHandCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[1] . ' AND zone = "hand"')->fetch();
        $this->games->proposeChaosDraftEffect(1, $players[1], $offer['effect_1']['id'], (int) $ownHandCardRow['id']);

        $this->expectException(GameStateException::class);
        $this->games->confirmChaosDraftEffect(1, $players[1], true);
    }

    public function testRejectingATeamProposalReturnsItToPropose(): void
    {
        $players = $this->makeChaosDraftGame('team', [1 => [3], 2 => [55], 3 => [8], 4 => [33]], teamIdBySeat: [1 => 0, 2 => 1, 3 => 0, 4 => 1]);
        $offer = $this->games->chaosDraftOfferFor(1, $players[1]);
        $ownHandCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[1] . ' AND zone = "hand"')->fetch();
        $this->games->proposeChaosDraftEffect(1, $players[1], $offer['effect_1']['id'], (int) $ownHandCardRow['id']);

        $this->games->confirmChaosDraftEffect(1, $players[3], false);

        $reopened = $this->games->chaosDraftOfferFor(1, $players[1]);
        self::assertSame('propose', $reopened['phase']);
        self::assertNull($this->pdo->query("SELECT chaos_effect_id FROM game_cards WHERE id = {$ownHandCardRow['id']}")->fetch()['chaos_effect_id']);
    }

    public function testNonTeamPlayerCannotUseTheTeamProposeAction(): void
    {
        $players = $this->makeChaosDraftGame('draft', [1 => [3], 2 => [55]]);
        $offer = $this->games->chaosDraftOfferFor(1, $players[1]);
        $handCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[1] . ' AND zone = "hand"')->fetch();

        $this->expectException(GameStateException::class);
        $this->games->proposeChaosDraftEffect(1, $players[1], $offer['effect_1']['id'], (int) $handCardRow['id']);
    }

    /**
     * Bug caught live (a user report, not a review finding): an attached
     * chaos effect that reads a PlayerChoices value (chosen number/mood/
     * player/etc.) never had anything to expose that field to the
     * frontend with -- unlike a base card's own choice_fields (driven by
     * CardChoiceSchema), nothing computed the equivalent for an attached
     * chaos effect, so GameService::serializeCard() never surfaced it and
     * the play-card panel never rendered a prompt for it at all. The
     * effect wasn't broken -- $choices->int('value') was always null
     * because nothing could ever populate it. See ChaosCardChoiceSchema's
     * own docblock for the fix (a synthetic 'chaos'-keyed nested field,
     * reusing the same nested-field machinery Duplicity's repeat offer
     * already exercises).
     */
    public function testAttachedChaosEffectRequiringAChoiceExposesANestedChaosField(): void
    {
        $players = $this->makeChaosDraftGame('draft', [1 => [55], 2 => [8]]); // Apathy, Dignity
        $handCardRow = $this->pdo->query('SELECT id FROM game_cards WHERE owner_game_player_id = ' . $players[1] . ' AND zone = "hand"')->fetch();
        $chaos035Id = (int) $this->pdo->query("SELECT id FROM chaos_effects WHERE effect_key = 'chaos_035'")->fetchColumn();
        $this->pdo->prepare('UPDATE game_cards SET chaos_effect_id = :chaos_effect_id WHERE id = :id')
            ->execute(['chaos_effect_id' => $chaos035Id, 'id' => $handCardRow['id']]);

        $state = $this->games->getState(1, 1); // alice, user_id 1
        $apathy = null;
        foreach ($state['you']['hand'] as $card) {
            if ($card['card_id'] === (int) $handCardRow['id']) {
                $apathy = $card;
            }
        }
        self::assertNotNull($apathy, 'Apathy should still be in hand with the chaos effect attached');

        $chaosField = null;
        foreach ($apathy['choice_fields'] as $field) {
            if ($field['key'] === 'chaos') {
                $chaosField = $field;
            }
        }
        self::assertNotNull($chaosField, 'choice_fields should carry a nested chaos field for the attached chaos_035 effect');
        self::assertSame('nested', $chaosField['type']);
        self::assertCount(1, $chaosField['fields']);
        self::assertSame('value', $chaosField['fields'][0]['key']);
        self::assertSame('value', $chaosField['fields'][0]['type']);
    }
}
