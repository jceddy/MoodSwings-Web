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
 * Issue #405 follow-up: since playMood()/pass() now both require this
 * round's own Chaos Draft offer resolved first
 * (GameService::assertChaosDraftOfferResolved()), a bot -- which never
 * had any policy for choosing one -- would otherwise get stuck forever
 * the instant it's seated in a chaos_draft game. GameService::
 * advanceBotChaosDraftOffer() (dispatched from advanceAutomatedTurns(),
 * mirroring advanceBotTeamDecision()'s own placement) is what prevents
 * that: BotPlayerService::chooseChaosDraftEffectAttachment() gives every
 * bot a simple deterministic policy (always effect_1, attached to the
 * lowest-value candidate hand card).
 */
final class ChaosDraftBotAutoResolveIntegrationTest extends TestCase
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

    /**
     * @param array<int, array{username: string, is_bot?: bool, team_id?: int}> $seats seat_order (1-indexed) => user config
     * @param array<int, int[]> $handCatalogCardIdsBySeat seat_order => catalog card ids to deal into that seat's hand
     * @return int[] game_player_id by seat_order
     */
    private function makeChaosDraftGame(string $format, array $seats, array $handCatalogCardIdsBySeat, int $firstSeat = 1): array
    {
        foreach ($seats as $seat => $config) {
            $this->pdo->prepare(
                'INSERT INTO users (username, email, password_hash, email_verified_at, is_bot) VALUES (:u, :e, :h, NOW(), :bot)'
            )->execute([
                'u' => $config['username'],
                'e' => $config['username'] . '@example.com',
                'h' => 'hash',
                'bot' => !empty($config['is_bot']) ? 1 : 0,
            ]);
            $seats[$seat]['user_id'] = (int) $this->pdo->lastInsertId();
        }

        $this->pdo->prepare(
            "INSERT INTO games (id, format, deck_type, status, created_by_user_id) VALUES (1, :format, 'chaos_draft', 'in_progress', :creator)"
        )->execute(['format' => $format, 'creator' => $seats[1]['user_id']]);

        $playerIds = [];
        foreach ($seats as $seat => $config) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO game_players (game_id, user_id, seat_order, team_id) VALUES (1, :user_id, :seat, :team_id)'
            );
            $stmt->execute(['user_id' => $config['user_id'], 'seat' => $seat, 'team_id' => $config['team_id'] ?? null]);
            $playerIds[$seat] = (int) $this->pdo->lastInsertId();

            foreach ($handCatalogCardIdsBySeat[$seat] ?? [] as $catalogCardId) {
                $this->pdo->prepare(
                    'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id) VALUES (1, :card_id, "hand", :owner)'
                )->execute(['card_id' => $catalogCardId, 'owner' => $playerIds[$seat]]);
            }
        }

        $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status)
             VALUES (1, 1, :first, :first, 1, '[null]', 'in_progress')"
        )->execute(['first' => $playerIds[$firstSeat]]);

        return $playerIds;
    }

    public function testABotsOwnOfferResolvesAutomaticallyEvenWhenItIsNotItsTurn(): void
    {
        $players = $this->makeChaosDraftGame(
            'draft',
            [1 => ['username' => 'alice'], 2 => ['username' => 'bob', 'is_bot' => true]],
            [1 => [3], 2 => [55, 8]], // human: Charity -- bot: Apathy, Dignity
            firstSeat: 1, // the HUMAN's turn, not the bot's -- proves offer resolution isn't turn-gated
        );

        $this->games->advanceAutomatedTurns(1);

        $offerRow = $this->pdo->query(
            "SELECT resolved_at FROM chaos_draft_offers WHERE game_round_id = (SELECT id FROM game_rounds WHERE game_id = 1) AND game_player_id = {$players[2]}"
        )->fetch();
        self::assertNotNull($offerRow['resolved_at'], "the bot's own offer should have resolved automatically");

        $attachedCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM game_cards WHERE owner_game_player_id = {$players[2]} AND chaos_effect_id IS NOT NULL"
        )->fetchColumn();
        self::assertSame(1, $attachedCount);
    }

    public function testABotDoesNotGetStuckOnItsOwnTurn(): void
    {
        $players = $this->makeChaosDraftGame(
            'draft',
            [1 => ['username' => 'alice'], 2 => ['username' => 'bob', 'is_bot' => true]],
            [1 => [3], 2 => [55, 8]],
            firstSeat: 2, // the BOT's own turn
        );

        $this->games->advanceAutomatedTurns(1);

        // Before the fix, the bot's own playMood()/pass() call inside
        // advanceAutomatedTurns() would throw (an unhandled
        // GameStateException) the moment it tried to act with its own
        // offer still unresolved -- proving it got past that means
        // SOMETHING happened this round beyond just the offer itself:
        // either the bot played a card out of its hand, or the round
        // advanced/passed past it.
        $offerRow = $this->pdo->query(
            "SELECT resolved_at FROM chaos_draft_offers WHERE game_round_id = (SELECT id FROM game_rounds WHERE game_id = 1) AND game_player_id = {$players[2]}"
        )->fetch();
        self::assertNotNull($offerRow['resolved_at']);

        $round = $this->pdo->query('SELECT current_turn_game_player_id FROM game_rounds WHERE game_id = 1')->fetch();
        self::assertNotSame($players[2], $round['current_turn_game_player_id'] !== null ? (int) $round['current_turn_game_player_id'] : null, "the bot's own turn should have actually advanced, not stalled");
    }

    public function testOpenTeamPlayFullyResolvesWithNoHumansOnTheTeamAtAll(): void
    {
        $players = $this->makeChaosDraftGame(
            'team',
            [
                1 => ['username' => 'bot1', 'is_bot' => true, 'team_id' => 0],
                2 => ['username' => 'human1', 'team_id' => 1],
                3 => ['username' => 'bot2', 'is_bot' => true, 'team_id' => 0],
                4 => ['username' => 'human2', 'team_id' => 1],
            ],
            [1 => [3], 2 => [55], 3 => [8], 4 => [33]],
        );
        // advanceTeamTurn() (unrelated to this test) expects round 1's
        // own team_turn_1_game_player_id already set by the normal team
        // turn-order decision flow -- the raw INSERT above doesn't run
        // that flow, so it's set directly here purely so advancing past
        // the now-resolved offer into an actual turn doesn't crash;
        // nothing about the offer-auto-resolve behavior under test here
        // depends on this.
        $this->pdo->prepare('UPDATE game_rounds SET team_turn_1_game_player_id = :player WHERE game_id = 1')
            ->execute(['player' => $players[1]]);

        $this->games->advanceAutomatedTurns(1);

        $offerRow = $this->pdo->query(
            "SELECT resolved_at FROM chaos_draft_offers WHERE game_round_id = (SELECT id FROM game_rounds WHERE game_id = 1) AND team_id = 0"
        )->fetch();
        self::assertNotNull($offerRow['resolved_at'], "team 0 (both bots) should have proposed AND confirmed automatically");

        $attachedCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM game_cards WHERE owner_game_player_id IN ({$players[1]}, {$players[3]}) AND chaos_effect_id IS NOT NULL"
        )->fetchColumn();
        self::assertSame(1, $attachedCount);
    }

    /**
     * A bot teammate paired with a real human: the bot proposes on its
     * own initiative, but stops there -- confirmChaosDraftEffect() is the
     * OTHER teammate's own call, and that teammate is human here, so the
     * offer must stay in 'confirm' phase, unresolved, rather than the bot
     * somehow completing both steps itself.
     */
    public function testOpenTeamPlayBotProposesButLeavesConfirmationToItsHumanTeammate(): void
    {
        $players = $this->makeChaosDraftGame(
            'team',
            [
                1 => ['username' => 'bot1', 'is_bot' => true, 'team_id' => 0],
                2 => ['username' => 'human1', 'team_id' => 1],
                3 => ['username' => 'human2', 'team_id' => 0],
                4 => ['username' => 'human3', 'team_id' => 1],
            ],
            [1 => [3], 2 => [55], 3 => [8], 4 => [33]],
        );

        $this->games->advanceAutomatedTurns(1);

        $offer = $this->pdo->query(
            "SELECT phase, resolved_at, proposer_game_player_id FROM chaos_draft_offers WHERE game_round_id = (SELECT id FROM game_rounds WHERE game_id = 1) AND team_id = 0"
        )->fetch();
        self::assertSame('confirm', $offer['phase']);
        self::assertNull($offer['resolved_at'], "still awaiting the human teammate's own confirmation");
        self::assertSame($players[1], (int) $offer['proposer_game_player_id']);
    }

    /**
     * A bug caught live (a user report): "if a player has no cards in
     * hand, the game should not present them with a chaos effect to
     * choose from" -- applies to a bot's own turn just as much as a
     * human's. Before chaosDraftOfferHasNothingToAttachTo()'s own guard in
     * advanceBotChaosDraftOffer(), an empty-handed bot with an unresolved
     * offer would crash inside BotPlayerService::
     * chooseChaosDraftEffectAttachment()'s own unconditional
     * candidateHandCardIds[0] indexing (an empty hand means an empty
     * candidate list). Simulated here by discarding the bot's own hand
     * card right after seeding it, so the bot ends the round with an
     * unresolved offer and literally nothing to attach it to.
     */
    public function testABotWithAnEmptyHandDoesNotCrashOnItsUnresolvedOffer(): void
    {
        $players = $this->makeChaosDraftGame(
            'draft',
            [1 => ['username' => 'alice'], 2 => ['username' => 'bob', 'is_bot' => true]],
            [1 => [3], 2 => [55]], // human: Charity -- bot: Apathy
            firstSeat: 1,
        );
        // Rolls (and creates) the bot's own offer row WHILE it still has a
        // hand card -- ensureChaosDraftOffersForRound()'s own "no cards,
        // no offer" rule would otherwise skip creating a row at all, which
        // wouldn't exercise the bug: an ALREADY-CREATED offer that only
        // loses its last attachable card afterward.
        self::assertNotNull($this->games->chaosDraftOfferFor(1, $players[2]));

        $this->pdo->prepare('UPDATE game_cards SET zone = "discard" WHERE owner_game_player_id = :player AND zone = "hand"')
            ->execute(['player' => $players[2]]);

        $this->games->advanceAutomatedTurns(1); // must not throw

        $offerRow = $this->pdo->query(
            "SELECT resolved_at FROM chaos_draft_offers WHERE game_round_id = (SELECT id FROM game_rounds WHERE game_id = 1) AND game_player_id = {$players[2]}"
        )->fetch();
        self::assertNotFalse($offerRow, 'sanity check: the offer row should still exist');
        self::assertNull($offerRow['resolved_at'], 'nothing to attach it to -- left unresolved rather than crashing');
    }
}
