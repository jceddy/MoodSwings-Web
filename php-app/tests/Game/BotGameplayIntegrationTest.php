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
 * Issue #140: practice bots. Exercises GameService::advanceAutomatedTurns() (and
 * createGame()'s own bot-seating validation) end to end, against a real
 * database -- MoodSwings\Tests\Bot\BotChoiceResolverTest/
 * BotPlayerServiceTest already cover the choice-picking policy itself in
 * isolation, so these focus on the request-lifecycle wiring: does a bot's
 * turn actually get driven, does it stop at a real player, does a
 * pending decision targeting a bot get auto-answered, are bot games kept
 * out of lifetime/card stats.
 */
final class BotGameplayIntegrationTest extends TestCase
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
        $pdo->exec('TRUNCATE TABLE game_events');
        $pdo->exec('TRUNCATE TABLE game_initial_card_passes');
        $pdo->exec('TRUNCATE TABLE game_team_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decision_batches');
        $pdo->exec('TRUNCATE TABLE game_round_scores');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_rounds');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE user_lifetime_stats');
        $pdo->exec('TRUNCATE TABLE card_stats');
        $pdo->exec('TRUNCATE TABLE user_decklists');
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

    private function insertUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at)
             VALUES (:username, :email, 'hash', NOW())"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com"]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBotUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, email_verified_at, is_bot)
             VALUES (:username, :email, 'hash', NOW(), 1)"
        );
        $stmt->execute(['username' => $username, 'email' => "{$username}@example.com"]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param int[] $cardIds */
    private function insertSavedDecklist(int $userId, string $name, array $cardIds): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_decklists (user_id, name, card_ids, visibility) VALUES (:user_id, :name, :card_ids, 'private')"
        );
        $stmt->execute(['user_id' => $userId, 'name' => $name, 'card_ids' => json_encode($cardIds)]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return int game_players.id */
    private function insertGamePlayer(int $gameId, int $userId, int $seatOrder, ?int $teamId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_players (game_id, user_id, seat_order, team_id) VALUES (:game_id, :user_id, :seat_order, :team_id)'
        );
        $stmt->execute(['game_id' => $gameId, 'user_id' => $userId, 'seat_order' => $seatOrder, 'team_id' => $teamId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** $deckPosition only matters for zone='deck' -- BoardStateRepository::load() orders each deck by it (0 = top), and two rows sharing the same position (including the column's own NULL default) silently collide, so any test dealing out more than one deck card must give each of them its own distinct position -- see GameServiceIntegrationTest's own identical helper. */
    private function insertGameCard(int $gameId, int $cardId, string $zone, ?int $owner = null, ?int $deckPosition = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, deck_position) VALUES (:game_id, :card_id, :zone, :owner, :deck_position)'
        );
        $stmt->execute(['game_id' => $gameId, 'card_id' => $cardId, 'zone' => $zone, 'owner' => $owner, 'deck_position' => $deckPosition]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertGameRound(int $gameId, int $roundNumber, int $firstPlayerId, int $currentTurnPlayerId, int $playsRemaining): void
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
    }

    private function insertGame(string $format, string $deckType, int $createdByUserId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO games (format, deck_type, status, created_by_user_id, wins_needed)
             VALUES (:format, :deck_type, 'in_progress', :created_by, 3)"
        );
        $stmt->execute(['format' => $format, 'deck_type' => $deckType, 'created_by' => $createdByUserId]);

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

    private function fetchGame(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM games WHERE id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetch();
    }

    // -- Team Play (issue #360) frozen-round fixtures ---------------------

    /**
     * A round frozen the way startGame() itself leaves one for Team Play's
     * own turn-order decision or Closed Team Play's pregame card pass --
     * current_turn_game_player_id NULL. Unlike insertGameRound() above
     * (always a real current player), this is its own helper rather than
     * an overload, since "frozen" is a genuinely different round shape,
     * not just a null argument.
     *
     * @return int game_rounds.id
     */
    private function insertFrozenTeamRound(int $gameId, int $roundNumber, int $firstPlayerId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, status)
             VALUES (:game_id, :round_number, :first_player, NULL, 0, 'in_progress')"
        );
        $stmt->execute(['game_id' => $gameId, 'round_number' => $roundNumber, 'first_player' => $firstPlayerId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A round that has already scored (status 'scored', winner_team_id
     * set) with NO next round created yet -- exactly the real state a
     * losing team's own 'draw_recipient' decision opens in (see
     * GameService::finishTeamScoringAndAdvance()), unlike
     * insertFrozenTeamRound() above (used for 'turn_order', which always
     * opens on an already-'in_progress' round). Deliberately a SEPARATE
     * helper rather than a parameter on insertFrozenTeamRound() -- a bug
     * caught live: advanceAutomatedTurns() used to unconditionally call
     * currentRound() (status = 'in_progress' only) before ever trying to
     * resolve a team decision at all, so every 'draw_recipient' test that
     * (like insertFrozenTeamRound() always has) inserted its round as
     * 'in_progress' was accidentally passing for a reason that could never
     * happen in a real game, masking the actual deadlock.
     *
     * @return int game_rounds.id
     */
    private function insertScoredTeamRound(int $gameId, int $roundNumber, int $firstPlayerId, int $winnerTeamId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, status, winner_team_id, scored_at)
             VALUES (:game_id, :round_number, :first_player, NULL, 0, 'scored', :winner_team_id, NOW())"
        );
        $stmt->execute([
            'game_id' => $gameId,
            'round_number' => $roundNumber,
            'first_player' => $firstPlayerId,
            'winner_team_id' => $winnerTeamId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param int[] $candidateGamePlayerIds
     * @return int game_team_decisions.id
     */
    private function insertTeamDecision(int $gameId, int $roundId, int $teamId, string $decisionType, array $candidateGamePlayerIds): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_team_decisions (game_id, game_round_id, team_id, decision_type, candidate_game_player_ids)
             VALUES (:game_id, :round_id, :team_id, :decision_type, :candidates)'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'round_id' => $roundId,
            'team_id' => $teamId,
            'decision_type' => $decisionType,
            'candidates' => json_encode($candidateGamePlayerIds),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Seeds an already-resolved 'propose' phase, mirroring proposeTeamDecision()'s own UPDATE. Returns the same id insertTeamDecision() would. */
    private function insertProposedTeamDecision(int $gameId, int $roundId, int $teamId, string $decisionType, array $candidateGamePlayerIds, int $proposerGamePlayerId, int $proposedGamePlayerId): int
    {
        $decisionId = $this->insertTeamDecision($gameId, $roundId, $teamId, $decisionType, $candidateGamePlayerIds);
        $this->pdo->prepare(
            "UPDATE game_team_decisions SET phase = 'confirm', proposer_game_player_id = :proposer, proposed_game_player_id = :proposed
             WHERE id = :id"
        )->execute(['proposer' => $proposerGamePlayerId, 'proposed' => $proposedGamePlayerId, 'id' => $decisionId]);

        return $decisionId;
    }

    private function fetchOpenTeamDecision(int $gameId): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_team_decisions WHERE game_id = :game_id AND resolved_at IS NULL LIMIT 1');
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetch();
    }

    private function fetchTeamDecisionById(int $decisionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_team_decisions WHERE id = :id');
        $stmt->execute(['id' => $decisionId]);

        return $stmt->fetch();
    }

    private function insertInitialCardPass(int $gameId, int $gamePlayerId, array $cardIds): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_initial_card_passes (game_id, game_player_id, card_ids) VALUES (:game_id, :player_id, :card_ids)'
        )->execute(['game_id' => $gameId, 'player_id' => $gamePlayerId, 'card_ids' => json_encode($cardIds)]);
    }

    /** @return int[] */
    private function submittedInitialCardPassGamePlayerIds(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT game_player_id FROM game_initial_card_passes WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // -- createGame() validation --------------------------------------

    /**
     * Issue #360: Team Play is no longer bot-exclusive. A bot may be the
     * creator's own partner too, not just seated on the opposing team --
     * see botsSupportedFor()'s own docblock for why (advanceAutomatedTurns()
     * now drives Team Play's turn-order decision either way).
     */
    public function testCreateGameAcceptsABotAsAPartnerInOpenTeamPlay(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');
        $u2 = $this->insertUser('human2');
        $u3 = $this->insertUser('human3');

        $gameId = $this->games->createGame($human, [$human, $bot, $u2, $u3], format: 'team', partnerUserId: $bot);

        self::assertGreaterThan(0, $gameId);
    }

    public function testCreateGameAcceptsABotAsAnOpponentInClosedTeamPlay(): void
    {
        $human = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        $bot = $this->insertBotUser('bot1');
        $u3 = $this->insertUser('human3');

        $gameId = $this->games->createGame($human, [$human, $u2, $bot, $u3], format: 'closed_team', partnerUserId: $u2);

        self::assertGreaterThan(0, $gameId);
    }

    /**
     * Team Play's own bot support is bot-count-agnostic the same way
     * Traditional's already is (botGamePlayerIds() is plural) -- all 3
     * non-creator seats may be bots at once.
     */
    public function testCreateGameAcceptsAHumanAgainstThreeBotsInOpenTeamPlay(): void
    {
        $human = $this->insertUser('human1');
        $bot1 = $this->insertBotUser('bot1');
        $bot2 = $this->insertBotUser('bot2');
        $bot3 = $this->insertBotUser('bot3');

        $gameId = $this->games->createGame($human, [$human, $bot1, $bot2, $bot3], format: 'team', partnerUserId: $bot1);

        self::assertGreaterThan(0, $gameId);
    }

    /**
     * Issue #359: 'draft' format + every draft deck_type is now
     * bot-supported (BotPlayerService/advanceAutomatedTurns() both learned
     * how to drive a bot's own draft picks -- see
     * BotDraftGameplayIntegrationTest for full-draft coverage) -- this
     * used to be rejected outright (testCreateGameStillRejectsABotInDraftFormat,
     * pre-issue-#359); now it's simply accepted, same as every other
     * bot-supported format/deck_type combination.
     */
    public function testCreateGameNowAllowsABotInDraftFormat(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');
        $u2 = $this->insertUser('human2');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot, $u2],
            format: 'draft',
            deckType: 'quick_draft',
            quickDraftPoolSource: 'random_48',
        );

        self::assertGreaterThan(0, $gameId);
    }

    /**
     * 'custom' is a single table-wide shared deck (unlike 'custom_duel'),
     * fully built at createGame() time from the human creator's own
     * decklistText -- a bot needs nothing extra to "have" one, same as
     * 'structure'/'power'/etc. Still validated the same way it always
     * is: an empty decklist is rejected regardless of whether a bot is
     * seated.
     */
    public function testCreateGameRejectsAnEmptyCustomDeckTypeDecklistEvenWithABotSeated(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $this->expectException(GameStateException::class);
        $this->games->createGame($human, [$human, $bot], deckType: 'custom', decklistText: '');
    }

    public function testCreateGameAcceptsABotInStandardFormatWithACustomDeck(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'standard',
            deckType: 'custom',
            // 15 distinct cards -- the 2-player minimum (15 * (playerCount - 1)).
            decklistText: "1 Altruism\n1 Benevolence\n1 Charity\n1 Chivalry\n1 Complacency\n1 Conviction\n1 Courage\n"
                . "1 Dignity\n1 Discipline\n1 Disillusionment\n1 Encouragement\n1 Faith\n1 Friendliness\n1 Guilt\n1 Honor",
        );

        self::assertGreaterThan(0, $gameId);
    }

    /**
     * End to end: the shared decklist a bot's own seat draws from is
     * exactly the same one the human creator supplied at creation time --
     * no bot-specific handling needed anywhere in startGame()'s own
     * dealing, unlike 'custom_duel' (see
     * testACustomDuelBotGameStartsOnceTheHumanAlsoSubmitsADeck() above).
     */
    public function testACustomDeckTypeBotGameStartsImmediately(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'standard',
            deckType: 'custom',
            decklistText: "1 Altruism\n1 Benevolence\n1 Charity\n1 Chivalry\n1 Complacency\n1 Conviction\n1 Courage\n"
                . "1 Dignity\n1 Discipline\n1 Disillusionment\n1 Encouragement\n1 Faith\n1 Friendliness\n1 Guilt\n1 Honor",
        );

        $this->games->startGame($gameId);

        self::assertSame('in_progress', $this->fetchGame($gameId)['status']);
    }

    /**
     * Also usable via a saved decklist (issue #92) instead of pasted
     * text -- same $savedDecklistId path a human-only game already uses,
     * unaffected by a bot being one of the seats.
     */
    public function testCreateGameAcceptsABotInStandardFormatWithASavedCustomDeck(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');
        $decklistId = $this->insertSavedDecklist($human, "Human's deck", range(1, 15));

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'standard',
            deckType: 'custom',
            savedDecklistId: $decklistId,
        );

        self::assertGreaterThan(0, $gameId);
    }

    public function testCreateGameAcceptsABotInStandardFormatWithAStructureDeck(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame($human, [$human, $bot], format: 'standard', deckType: 'structure');

        self::assertGreaterThan(0, $gameId);
    }

    public function testCreateGameRejectsABotInCustomDuelWithoutABotDecklist(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $this->expectException(GameStateException::class);
        $this->games->createGame($human, [$human, $bot], format: 'duel', deckType: 'custom_duel');
    }

    public function testCreateGameWritesTheBotsOwnDecklistFromPlainText(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: ['preset' => 'user_defined', 'min_cards' => 7],
            botDecklistText: "1 Charity\n1 Chivalry\n1 Complacency\n1 Benevolence\n1 Conviction\n1 Encouragement\n1 Faith",
        );

        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);
        $stmt = $this->pdo->prepare('SELECT custom_deck_card_ids FROM game_players WHERE id = :id');
        $stmt->execute(['id' => $botPlayerId]);
        $cardIds = json_decode((string) $stmt->fetchColumn(), true);

        self::assertCount(7, $cardIds);
    }

    public function testCreateGameWritesTheBotsOwnDecklistFromASavedDecklistAuthorizedAgainstTheCreator(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');
        // The saved decklist belongs to the CREATOR, not the bot -- a bot
        // has no saved decklists (or friendships) of its own, so this
        // only works if createGame() authorizes the lookup against
        // $human rather than $bot's own user id.
        $decklistId = $this->insertSavedDecklist($human, "Human's deck", [3, 4, 5, 2, 6, 11, 12]);

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: ['preset' => 'user_defined', 'min_cards' => 7],
            botSavedDecklistId: $decklistId,
        );

        $botPlayerId = $this->games->gamePlayerIdFor($gameId, $bot);
        $stmt = $this->pdo->prepare('SELECT custom_deck_card_ids FROM game_players WHERE id = :id');
        $stmt->execute(['id' => $botPlayerId]);
        $cardIds = json_decode((string) $stmt->fetchColumn(), true);

        self::assertEqualsCanonicalizing([3, 4, 5, 2, 6, 11, 12], $cardIds);
    }

    public function testCreateGameRejectsAnInvalidBotDecklist(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $this->expectException(GameStateException::class);
        $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: ['preset' => 'user_defined', 'min_cards' => 10], // only 2 cards given below
            botDecklistText: "1 Charity\n1 Dignity",
        );
    }

    /**
     * End to end: the bot's own decklist is supplied at creation time,
     * the human still submits their own separately (exactly like a real
     * custom_duel game between two humans), and the game starts normally
     * once both are in -- startGame()'s own "both seats submitted" gate
     * doesn't need to know or care that one of those two submissions
     * happened at createGame() time instead of via POST /games/decklist.
     */
    public function testACustomDuelBotGameStartsOnceTheHumanAlsoSubmitsADeck(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'duel',
            deckType: 'custom_duel',
            duelDeckRules: ['preset' => 'user_defined', 'min_cards' => 7],
            botDecklistText: "1 Charity\n1 Chivalry\n1 Complacency\n1 Benevolence\n1 Conviction\n1 Encouragement\n1 Faith",
        );
        $humanPlayerId = $this->games->gamePlayerIdFor($gameId, $human);

        self::assertSame('waiting', $this->fetchGame($gameId)['status']); // still waiting on the human's own deck

        $this->games->submitCustomDuelDeck($gameId, $humanPlayerId, "1 Courage\n1 Discipline\n1 Guilt\n1 Honor\n1 Kindness\n1 Meekness\n1 Pacifism");
        $this->games->startGame($gameId);

        self::assertSame('in_progress', $this->fetchGame($gameId)['status']);
    }

    // -- advanceAutomatedTurns() ----------------------------------------------

    public function testAdvanceBotTurnsReturnsNullWhenNoBotsAreSeated(): void
    {
        $u1 = $this->insertUser('human1');
        $u2 = $this->insertUser('human2');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $u2, 1);
        // A non-empty hand -- insertUser() opts every user into auto-pass
        // on empty hand by default (see AutoPassOnEmptyHandIntegrationTest),
        // so an empty hand here would auto-pass rather than genuinely
        // returning null the way this test means to check.
        $this->insertGameCard($gameId, 8, 'hand', $p1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        self::assertNull($this->games->advanceAutomatedTurns($gameId));
    }

    public function testBotPlaysItsHighestValuePlayableCardOnItsOwnTurn(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        // Dignity (id 8, value 3, has an optional field) and Apathy (id
        // 55, value 4, no ability at all) -- the bot should play Apathy,
        // the higher-value card, with no choices needed.
        $this->insertGameCard($gameId, 8, 'hand', $botPlayerId);
        $this->insertGameCard($gameId, 55, 'hand', $botPlayerId);
        // A non-empty hand for the human too -- otherwise auto-pass on
        // empty hand (on by default -- see
        // AutoPassOnEmptyHandIntegrationTest) would keep the loop going
        // straight through the human's own turn as well, rather than
        // stopping there the way this test means to check.
        $this->insertGameCard($gameId, 3, 'hand', $p1);
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        self::assertTrue($this->cardIsInPlay($gameId, 55));
        self::assertTrue($this->cardIsInHand($gameId, 8)); // left alone -- lower value

        // Its one play spent, the turn should now be back with the human.
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);
    }

    /**
     * EARLY_PRIORITY_EFFECT_KEYS' own flat priority bonus (see
     * BotPlayerServiceTest for the policy itself in isolation), proven
     * end to end through the FULL advanceAutomatedTurns() ->
     * chooseAction() -> playMood() -> CharityEffect::afterPlaying()
     * request lifecycle: Charity (id 3, value 1, grants an extra play)
     * gets played BEFORE Apathy (id 55, value 4, no ability of its own)
     * despite its own much lower printed value, and because Charity's
     * own extra play keeps the SAME turn going, Apathy gets played too
     * in this one advanceAutomatedTurns() call -- the turn only passes
     * back to the human once both are in play.
     */
    public function testBotPlaysAnExtraPlayCardBeforeAHigherValueCardAndUsesTheExtraPlay(): void
    {
        $u1 = $this->insertUser('human8');
        $botUserId = $this->insertBotUser('bot8');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 3, 'hand', $botPlayerId); // Charity, value 1 -- grants an extra play
        $this->insertGameCard($gameId, 55, 'hand', $botPlayerId); // Apathy, value 4 -- no ability
        $this->insertGameCard($gameId, 8, 'hand', $p1); // human needs a non-empty hand too
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        self::assertTrue($this->cardIsInPlay($gameId, 3), 'Charity should have been played');
        self::assertTrue($this->cardIsInPlay($gameId, 55), "Apathy should also have been played, using Charity's own extra play");

        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id'], 'the turn should only pass back to the human once both plays are spent');
    }

    /**
     * End-to-end coverage of BotPlayerService::shouldAttemptValueBoostDiscard()
     * (see BotPlayerServiceTest for the policy itself in isolation) through
     * the FULL advanceAutomatedTurns() -> playMood() request lifecycle --
     * this is the level that originally caught a real bug during
     * development: BotChoiceResolver::resolve() had its own independent
     * required-or-forced gate that silently discarded BotPlayerService's
     * own "yes, fill it" decision for any field outside
     * ALWAYS_FILLED_OPTIONAL_FIELDS, so shouldAttemptValueBoostDiscard()
     * alone returning true wasn't sufficient proof the choice actually
     * made it into the real play. 4 spare cards (only 1 of which,
     * Charity, actually qualifies as a legal discard) makes the "always
     * discard" branch deterministic, and no mood is in play for anyone
     * (deliberately non-decisive -- see
     * testChooseActionAlwaysDiscardsToDelightWithFourOrMoreSpareCards()),
     * so this is purely the hand-size branch, not the "would win" one.
     */
    public function testBotDiscardsToDelightWithFourOrMoreSpareCards(): void
    {
        $u1 = $this->insertUser('human2');
        $botUserId = $this->insertBotUser('bot2');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        // Every other card is deliberately LOWER value than Delight's own
        // 3, and none of them are in EARLY_PRIORITY_EFFECT_KEYS (unlike
        // Charity/Benevolence, which would otherwise now outrank Delight
        // outright as top-level plays of their own regardless of their
        // own printed value), so chooseAction()'s own highest-printed-
        // value ordering picks Delight first.
        $this->insertGameCard($gameId, 111, 'hand', $botPlayerId); // Delight, base value 3
        $this->insertGameCard($gameId, 20, 'hand', $botPlayerId); // Pacifism, value 1 -- the only eligible discard
        $this->insertGameCard($gameId, 23, 'hand', $botPlayerId); // Repentance, value 2 -- not eligible
        $this->insertGameCard($gameId, 28, 'hand', $botPlayerId); // Anxiety, value 2 -- not eligible
        $this->insertGameCard($gameId, 36, 'hand', $botPlayerId); // Doubt, value 2 -- not eligible
        $this->insertGameCard($gameId, 8, 'hand', $p1); // human needs a non-empty hand too, see testBotPlaysItsHighestValuePlayableCardOnItsOwnTurn()
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        self::assertTrue($this->cardIsInPlay($gameId, 111));
        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $delight = null;
        foreach ($inPlay as $mood) {
            if ($mood['catalog_card_id'] === 111) {
                $delight = $mood;
            }
        }
        self::assertNotNull($delight, 'Delight should be in play');
        self::assertSame(5, $delight['value'], "Delight's value should be boosted to 5");

        self::assertFalse($this->cardIsInHand($gameId, 20), 'Pacifism should have been discarded, not left in hand');
        $stmt = $this->pdo->prepare("SELECT 1 FROM game_cards WHERE game_id = :game_id AND card_id = 20 AND zone = 'discard'");
        $stmt->execute(['game_id' => $gameId]);
        self::assertNotFalse($stmt->fetchColumn(), 'Pacifism should be in the discard pile');
    }

    public function testBotPassesWhenItHasNothingPlayable(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        // The bot's own hand is empty -- nothing at all playable, so the
        // bot must pass. The human's own hand is deliberately non-empty
        // (unlike the bot's) so auto-pass on empty hand (on by default --
        // see AutoPassOnEmptyHandIntegrationTest) doesn't also carry the
        // loop straight through the human's own turn once it comes back
        // around, which would otherwise ping-pong the two passes back and
        // forth rather than landing on the human's turn the way this
        // test means to check.
        $this->insertGameCard($gameId, 8, 'hand', $p1);
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);
    }

    /**
     * Same distinction testLogsTheAutoPassDifferentlyFromAManualPass()
     * (AutoPassOnEmptyHandIntegrationTest) checks for an opted-in human's
     * own auto-pass -- a bot's pass is automated the exact same way, and
     * shares the exact same log phrasing (see describeEvent()'s own
     * 'turn_passed' branch).
     */
    public function testLogsABotsOwnPassAsAutomatedToo(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 8, 'hand', $p1);
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        $entry = $this->games->fullEventLog($gameId)[0];
        self::assertSame('turn_passed', $entry['event_type']);
        self::assertTrue($entry['details']['automated']);
        self::assertSame('bot1 passed automatically (no legal play)', $entry['description']);
    }

    public function testAdvanceBotTurnsStopsAtARealPlayersTurn(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 8, 'hand', $p1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1); // it's the HUMAN's turn

        self::assertNull($this->games->advanceAutomatedTurns($gameId));
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']); // untouched
    }

    public function testBotAutomaticallyAnswersAPendingDecisionTargetingIt(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        // Compulsion (id 86): "choose a player -- they must give up a
        // card from their hand." The bot needs at least one hand card
        // for a decision to even be created (CompulsionEffect declines
        // to ask at all if the target has nothing to give).
        $compulsionId = $this->insertGameCard($gameId, 86, 'hand', $p1);
        $this->insertGameCard($gameId, 8, 'hand', $botPlayerId);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $result = $this->games->playMood($gameId, $p1, $compulsionId, ['target_player_id' => $botPlayerId]);
        self::assertTrue($result['pending_decision'] ?? false);

        // The route-handler-level wrapper this mirrors (see index.php)
        // calls this right after -- the bot should immediately answer
        // its own pending decision without anyone else acting.
        $botResult = $this->games->advanceAutomatedTurns($gameId);
        self::assertNotNull($botResult);

        // Its only hand card is now the human's (moved over as Compulsion's
        // own effect), and nothing is left pending.
        self::assertTrue($this->cardIsInHand($gameId, 8, ownerUserId: $u1));
    }

    // -- Team Play (issue #360) --------------------------------------------

    /**
     * chooseTeamDecisionProposal() always proposes candidateIds[0]
     * regardless of which candidate is actually acting -- here the bot is
     * deliberately the SECOND candidate, so this also proves the bot
     * isn't just always proposing itself.
     */
    public function testAdvanceBotTurnsProposesATeamDecisionThenStopsForAHumanToConfirm(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('team', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0, teamId: 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1, teamId: 0);
        $this->insertGamePlayer($gameId, $this->insertUser('human2'), 2, teamId: 1);
        $this->insertGamePlayer($gameId, $this->insertUser('human3'), 3, teamId: 1);

        $roundId = $this->insertFrozenTeamRound($gameId, 1, $p1);
        $this->insertTeamDecision($gameId, $roundId, 0, 'turn_order', [$p1, $botPlayerId]);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        $decision = $this->fetchOpenTeamDecision($gameId);
        self::assertSame('confirm', $decision['phase']);
        self::assertSame($botPlayerId, (int) $decision['proposer_game_player_id']);
        self::assertSame($p1, (int) $decision['proposed_game_player_id']); // candidateIds[0], not "itself"

        // The round stays frozen -- confirming is the HUMAN's own move
        // (confirmTeamDecision() itself rejects the proposer confirming
        // their own proposal), so a second call has nothing left to do.
        self::assertNull($this->games->advanceAutomatedTurns($gameId));
        $round = $this->fetchRound($gameId);
        self::assertNull($round['current_turn_game_player_id']);
    }

    public function testAdvanceBotTurnsConfirmsATeamDecisionProposedByAHuman(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('team', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0, teamId: 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1, teamId: 0);
        $p3 = $this->insertGamePlayer($gameId, $this->insertUser('human2'), 2, teamId: 1);
        $p4 = $this->insertGamePlayer($gameId, $this->insertUser('human3'), 3, teamId: 1);

        $roundId = $this->insertFrozenTeamRound($gameId, 1, $p1);
        // The human already proposed themselves; only the bot's own
        // confirm is left.
        $decisionId = $this->insertProposedTeamDecision($gameId, $roundId, 0, 'turn_order', [$p1, $botPlayerId], proposerGamePlayerId: $p1, proposedGamePlayerId: $p1);
        // A non-empty hand for p1 -- confirming hands them turn 1 (see
        // below), and auto-pass on empty hand (on by default) would
        // otherwise carry the loop straight through p1's own turn too,
        // rather than stopping there the way this test means to check.
        $this->insertGameCard($gameId, 8, 'hand', $p1);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        self::assertNotNull($this->fetchTeamDecisionById($decisionId)['resolved_at'], 'resolved -- always approves, never rejects');
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['team_turn_1_game_player_id']);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);

        // applyTurnOrderDecision() immediately opens team 1's OWN
        // turn_order decision too (there's no single player to wait on a
        // play/pass from in between) -- neither of ITS candidates is a
        // bot here, so it's correctly left open rather than also
        // auto-resolved.
        $nextDecision = $this->fetchOpenTeamDecision($gameId);
        self::assertNotFalse($nextDecision);
        self::assertSame(1, (int) $nextDecision['team_id']);
        self::assertEqualsCanonicalizing([$p3, $p4], array_map(intval(...), json_decode((string) $nextDecision['candidate_game_player_ids'], true)));
    }

    /**
     * Both of one team's own seats are bots -- issue #360's own "up to 3
     * bots" scope. A single advanceAutomatedTurns() call should propose
     * AND confirm within the same loop, fully resolving the decision
     * without any human ever needing to act.
     */
    public function testAdvanceBotTurnsResolvesATeamDecisionBetweenTwoBotTeammates(): void
    {
        $u1 = $this->insertUser('human1');
        $bot1UserId = $this->insertBotUser('bot1');
        $bot2UserId = $this->insertBotUser('bot2');
        $gameId = $this->insertGame('team', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0, teamId: 0);
        $this->insertGamePlayer($gameId, $this->insertUser('human2'), 1, teamId: 0);
        $bot1PlayerId = $this->insertGamePlayer($gameId, $bot1UserId, 2, teamId: 1);
        $bot2PlayerId = $this->insertGamePlayer($gameId, $bot2UserId, 3, teamId: 1);

        $roundId = $this->insertFrozenTeamRound($gameId, 1, $bot1PlayerId);
        $decisionId = $this->insertTeamDecision($gameId, $roundId, 1, 'turn_order', [$bot1PlayerId, $bot2PlayerId]);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        self::assertNotNull($this->fetchTeamDecisionById($decisionId)['resolved_at']);
        $round = $this->fetchRound($gameId);
        self::assertSame($bot1PlayerId, (int) $round['team_turn_1_game_player_id']);

        // Team 0's own turn_order decision opens automatically too --
        // both its candidates are real humans, so it's correctly left
        // open rather than also auto-resolved.
        $nextDecision = $this->fetchOpenTeamDecision($gameId);
        self::assertNotFalse($nextDecision);
        self::assertSame(0, (int) $nextDecision['team_id']);
    }

    /**
     * A bug caught live: the losing team's own 'draw_recipient' decision
     * (see finishTeamScoringAndAdvance()) opens right after its round's
     * own status flips to 'scored' -- the NEXT round doesn't get created
     * until the decision itself resolves (applyDrawRecipientDecision()),
     * so there's a real window where this game has NO round with status
     * 'in_progress' at all. advanceAutomatedTurns() used to call
     * currentRound() (status = 'in_progress' only) before ever trying to
     * resolve any team decision, so it threw, was caught, and the whole
     * loop gave up immediately -- deadlocking forever when, like here,
     * both draw_recipient candidates are bots (no human anywhere in the
     * game had any further action left to trigger this method again
     * either). insertScoredTeamRound() reproduces the real timing (unlike
     * insertFrozenTeamRound(), always 'in_progress', used by every OTHER
     * team-decision test above).
     */
    public function testAdvanceBotTurnsResolvesADrawRecipientDecisionBetweenTwoBotTeammatesAfterTheirRoundHasAlreadyScored(): void
    {
        $u1 = $this->insertUser('human1');
        $bot1UserId = $this->insertBotUser('bot1');
        $bot2UserId = $this->insertBotUser('bot2');
        $gameId = $this->insertGame('closed_team', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0, teamId: 0);
        $this->insertGamePlayer($gameId, $this->insertUser('human2'), 1, teamId: 0);
        $bot1PlayerId = $this->insertGamePlayer($gameId, $bot1UserId, 2, teamId: 1);
        $bot2PlayerId = $this->insertGamePlayer($gameId, $bot2UserId, 3, teamId: 1);

        // Team 0 already won round 1; team 1 (both bots) has an open,
        // unresolved draw_recipient decision, and round 1 is already
        // 'scored' -- no round 2 exists yet.
        $roundId = $this->insertScoredTeamRound($gameId, 1, $p1, winnerTeamId: 0);
        $this->insertGameCard($gameId, 1, 'deck'); // shared deck -- the recipient's own draw
        $decisionId = $this->insertTeamDecision($gameId, $roundId, 1, 'draw_recipient', [$bot1PlayerId, $bot2PlayerId]);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        self::assertNotNull($this->fetchTeamDecisionById($decisionId)['resolved_at']);

        // applyDrawRecipientDecision() creates round 2 (in_progress) and
        // team 0's own turn_order decision (both real humans -- correctly
        // left open rather than also auto-resolved) the instant the draw
        // recipient decision resolves.
        $round = $this->fetchRound($gameId);
        self::assertNotFalse($round);
        self::assertSame(2, (int) $round['round_number']);
        $nextDecision = $this->fetchOpenTeamDecision($gameId);
        self::assertNotFalse($nextDecision);
        self::assertSame('turn_order', $nextDecision['decision_type']);
        self::assertSame(0, (int) $nextDecision['team_id']);
    }

    public function testAdvanceBotTurnsSubmitsClosedTeamInitialCardPassForABot(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('closed_team', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0, teamId: 0);
        $p2 = $this->insertGamePlayer($gameId, $this->insertUser('human2'), 1, teamId: 1);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 2, teamId: 0);
        $p4 = $this->insertGamePlayer($gameId, $this->insertUser('human3'), 3, teamId: 1);

        $this->insertFrozenTeamRound($gameId, 1, $p1);
        // Every real player's own pregame pass references REAL game_cards
        // rows in their own hand -- insertInitialCardPass() takes the same
        // game_cards.id values submitInitialCardPass() itself would
        // (never the catalog card_id), since transferHandCards() below
        // matches on exactly that id.
        $p1CardA = $this->insertGameCard($gameId, 1, 'hand', $p1); // Altruism
        $p1CardB = $this->insertGameCard($gameId, 7, 'hand', $p1); // Courage
        $this->insertInitialCardPass($gameId, $p1, [$p1CardA, $p1CardB]);
        $p2CardA = $this->insertGameCard($gameId, 6, 'hand', $p2); // Conviction
        $p2CardB = $this->insertGameCard($gameId, 8, 'hand', $p2); // Dignity
        $this->insertInitialCardPass($gameId, $p2, [$p2CardA, $p2CardB]);
        $p4CardA = $this->insertGameCard($gameId, 9, 'hand', $p4); // Discipline
        $p4CardB = $this->insertGameCard($gameId, 10, 'hand', $p4); // Disillusionment
        $this->insertInitialCardPass($gameId, $p4, [$p4CardA, $p4CardB]);
        // Only the bot's own pass is outstanding. Charity (1), Benevolence
        // (2), Chivalry (3), Complacency (4) -- chooseInitialCardPass()
        // should give up the 2 lowest (Charity/Benevolence), keeping the
        // other 2.
        $this->insertGameCard($gameId, 5, 'hand', $botPlayerId);
        $this->insertGameCard($gameId, 4, 'hand', $botPlayerId);
        $this->insertGameCard($gameId, 3, 'hand', $botPlayerId);
        $this->insertGameCard($gameId, 2, 'hand', $botPlayerId);

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        self::assertEqualsCanonicalizing([$p1, $p2, $botPlayerId, $p4], $this->submittedInitialCardPassGamePlayerIds($gameId));
        // All 4 submitted -- the round unfreezes to its own first player
        // (p1 now holds 2 real cards -- the bot's own 2 lowest -- so it's
        // not empty-handed and doesn't itself get auto-passed).
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);
        // The bot's own teammate (p1 -- both team 0) received the bot's
        // 2 lowest-value cards; the bot itself kept the other 2.
        self::assertTrue($this->cardIsInHand($gameId, 3, ownerUserId: $u1));
        self::assertTrue($this->cardIsInHand($gameId, 2, ownerUserId: $u1));
        self::assertTrue($this->cardIsInHand($gameId, 5, ownerUserId: $botUserId));
        self::assertTrue($this->cardIsInHand($gameId, 4, ownerUserId: $botUserId));
    }

    /**
     * Two bots on the same team (issue #360's own "up to 3 bots" scope) --
     * a single advanceAutomatedTurns() call should submit BOTH of their
     * passes in the same loop.
     */
    public function testAdvanceBotTurnsSubmitsMultipleBotsOwnInitialCardPassesInOneCall(): void
    {
        $u1 = $this->insertUser('human1');
        $bot1UserId = $this->insertBotUser('bot1');
        $bot2UserId = $this->insertBotUser('bot2');
        $gameId = $this->insertGame('closed_team', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0, teamId: 0);
        $p2 = $this->insertGamePlayer($gameId, $this->insertUser('human2'), 1, teamId: 1);
        $bot1PlayerId = $this->insertGamePlayer($gameId, $bot1UserId, 2, teamId: 0);
        $bot2PlayerId = $this->insertGamePlayer($gameId, $bot2UserId, 3, teamId: 1);

        $this->insertFrozenTeamRound($gameId, 1, $p1);
        // p1/p2 each keep 2 hand cards of their own (4 total dealt, only
        // 2 passed) so they aren't left empty-handed once their bot
        // teammate's own 2 cards arrive on top -- avoids auto-pass on
        // empty hand carrying the loop past the point this test means to
        // check.
        $this->insertGameCard($gameId, 1, 'hand', $p1);
        $this->insertGameCard($gameId, 7, 'hand', $p1);
        $p1PassA = $this->insertGameCard($gameId, 9, 'hand', $p1);
        $p1PassB = $this->insertGameCard($gameId, 10, 'hand', $p1);
        $this->insertInitialCardPass($gameId, $p1, [$p1PassA, $p1PassB]);
        $this->insertGameCard($gameId, 6, 'hand', $p2);
        $this->insertGameCard($gameId, 8, 'hand', $p2);
        $p2PassA = $this->insertGameCard($gameId, 11, 'hand', $p2);
        $p2PassB = $this->insertGameCard($gameId, 12, 'hand', $p2);
        $this->insertInitialCardPass($gameId, $p2, [$p2PassA, $p2PassB]);
        foreach ([$bot1PlayerId, $bot2PlayerId] as $botPlayerId) {
            $this->insertGameCard($gameId, 5, 'hand', $botPlayerId);
            $this->insertGameCard($gameId, 6, 'hand', $botPlayerId);
        }

        $result = $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($result);
        self::assertEqualsCanonicalizing(
            [$p1, $p2, $bot1PlayerId, $bot2PlayerId],
            $this->submittedInitialCardPassGamePlayerIds($gameId),
        );
        $round = $this->fetchRound($gameId);
        self::assertSame($p1, (int) $round['current_turn_game_player_id']);
        // Each still holds the 2 they kept, plus 2 from their own bot
        // teammate (bot1 -> p1, bot2 -> p2).
        self::assertTrue($this->cardIsInHand($gameId, 1, ownerUserId: $u1));
        self::assertTrue($this->cardIsInHand($gameId, 7, ownerUserId: $u1));
    }

    public function testAdvanceBotTurnsLeavesTheInitialCardPassFrozenWaitingOnRealPlayers(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('closed_team', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0, teamId: 0);
        $this->insertGamePlayer($gameId, $this->insertUser('human2'), 1, teamId: 1);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 2, teamId: 0);
        $this->insertGamePlayer($gameId, $this->insertUser('human3'), 3, teamId: 1);

        $this->insertFrozenTeamRound($gameId, 1, $p1);
        // The bot has already submitted -- nobody else has.
        $botCardA = $this->insertGameCard($gameId, 5, 'hand', $botPlayerId);
        $botCardB = $this->insertGameCard($gameId, 4, 'hand', $botPlayerId);
        $this->insertInitialCardPass($gameId, $botPlayerId, [$botCardA, $botCardB]);

        self::assertNull($this->games->advanceAutomatedTurns($gameId));
        $round = $this->fetchRound($gameId);
        self::assertNull($round['current_turn_game_player_id']); // still frozen
    }

    // -- stats exclusion --------------------------------------------------

    public function testCompletingAGameAgainstABotDoesNotBumpLifetimeStats(): void
    {
        $u1 = $this->insertUser('human1');
        $botUserId = $this->insertBotUser('bot1');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $this->insertGamePlayer($gameId, $botUserId, 1);
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        // A 2-player game's own resignation completes the whole game
        // immediately (see GameService::resignGame()), crediting the bot
        // as the winner -- the simplest deterministic way to reach
        // recordGameCompletionStats() without playing a full round out.
        $this->games->resignGame($gameId, $p1);

        $stats = $this->games->lifetimeStatsFor($u1);
        self::assertSame(0, $stats['game_wins']);
        self::assertSame(0, $stats['game_losses']);
    }

    /**
     * End-to-end coverage of BotPlayerService::rationalizationChoices()
     * (see BotPlayerServiceTest for the policy itself in isolation)
     * through the FULL advanceAutomatedTurns() -> playMood() ->
     * RationalizationEffect request lifecycle -- Rationalization's own
     * two fields (`mode`/`direction`) are both optional and
     * interdependent, exactly the shape most likely to slip through
     * BotChoiceResolver's own generic per-field machinery with a
     * validation mismatch even though BotPlayerServiceTest's own
     * isolated checks pass, the same class of bug
     * testBotDiscardsToDelightWithFourOrMoreSpareCards() above already
     * caught once for a different card. Hate/Fickleness (both base value
     * 0) make the bot's own remaining hand unambiguously "low value", so
     * 'refresh' is the only legal outcome here.
     */
    public function testBotRefreshesItsHandWithRationalizationWhenItsRemainingHandIsWeak(): void
    {
        $u1 = $this->insertUser('human3');
        $botUserId = $this->insertBotUser('bot3');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 49, 'hand', $botPlayerId); // Rationalization, base value 3
        // Hate/Fickleness (not Fear -- see EARLY_PRIORITY_EFFECT_KEYS,
        // which would otherwise outrank Rationalization here regardless
        // of its own weak-hand trigger, defeating the point of this test)
        $this->insertGameCard($gameId, 66, 'hand', $botPlayerId); // Hate, value 0
        $this->insertGameCard($gameId, 39, 'hand', $botPlayerId); // Fickleness, value 0
        // A real shared deck to actually draw the refreshed cards from --
        // refreshHand() bottoms the old hand then draws that many, so
        // without at least 2 cards here the draw would come up short.
        $this->insertGameCard($gameId, 5, 'deck', deckPosition: 0);
        $this->insertGameCard($gameId, 6, 'deck', deckPosition: 1);
        $this->insertGameCard($gameId, 8, 'hand', $p1); // human needs a non-empty hand too
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        self::assertTrue($this->cardIsInPlay($gameId, 49));
        self::assertFalse($this->cardIsInHand($gameId, 66, $botUserId), 'Hate should have been bottomed, not kept');
        self::assertFalse($this->cardIsInHand($gameId, 39, $botUserId), 'Fickleness should have been bottomed, not kept');
        self::assertTrue($this->cardIsInHand($gameId, 5, $botUserId), 'the bot should have drawn a fresh card off the deck');
        self::assertTrue($this->cardIsInHand($gameId, 6, $botUserId), 'the bot should have drawn a fresh card off the deck');
    }

    /**
     * The 'rotate' half of the same coverage -- 3 seated players (bot at
     * seat 0, so seat 1 sits at its own LEFT and seat 2 at its own
     * RIGHT, per BoardState::activeNeighbor()'s "left is index+1" rule),
     * with the bot's own remaining hand (Chivalry, value 3) deliberately
     * NOT weak, so 'refresh' isn't the outcome -- only the left
     * neighbor's own 5-card hand (3 more than the bot's own 2) makes
     * 'rotate' worth it. Confirms both the DIRECTION resolves correctly
     * end to end (a neighbor at the bot's own left is reached via
     * direction 'right' -- see rationalizationStealDirection()'s own
     * docblock) and that the bot's own hand actually ends up holding
     * that neighbor's original cards once RationalizationEffect
     * resolves for real.
     */
    public function testBotRotatesHandsWithRationalizationTowardAnOverstuffedNeighbor(): void
    {
        $u1 = $this->insertUser('human4');
        $u2 = $this->insertUser('human5');
        $botUserId = $this->insertBotUser('bot4');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 0);
        $p1 = $this->insertGamePlayer($gameId, $u1, 1);
        $p2 = $this->insertGamePlayer($gameId, $u2, 2);

        $this->insertGameCard($gameId, 49, 'hand', $botPlayerId); // Rationalization, base value 3
        $this->insertGameCard($gameId, 4, 'hand', $botPlayerId); // Chivalry, value 3 -- keeps the remaining hand from reading as "low value"
        // Seat 1 (the bot's own left neighbor) holds 5 cards against the
        // bot's own 2 -- exactly RATIONALIZATION_STEAL_HAND_SIZE_ADVANTAGE
        // (3) worth of edge.
        $overstuffedHand = [38, 39, 20, 7, 3];
        foreach ($overstuffedHand as $cardId) {
            $this->insertGameCard($gameId, $cardId, 'hand', $p1);
        }
        $this->insertGameCard($gameId, 8, 'hand', $p2);
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        self::assertTrue($this->cardIsInPlay($gameId, 49));
        foreach ($overstuffedHand as $cardId) {
            self::assertTrue($this->cardIsInHand($gameId, $cardId, $botUserId), "card {$cardId} from the overstuffed neighbor's own hand should have rotated onto the bot");
        }
        self::assertFalse($this->cardIsInHand($gameId, 4, $botUserId), "Chivalry should have rotated away from the bot's own hand");
    }

    /**
     * End-to-end coverage of BotPlayerService::intimidationTargetPlayerId()
     * (see BotPlayerServiceTest for the policy itself in isolation)
     * through the FULL advanceAutomatedTurns() -> playMood() ->
     * IntimidationEffect::pendingDecisionsFor() request lifecycle -- the
     * human opponent has a card in hand, so the bot's own optional
     * target_player_id field gets volunteered for and correctly reaches
     * IntimidationEffect itself, which pauses on a real pending decision
     * asking the human to reveal a card (never auto-answered here, since
     * it targets the human, not the bot).
     */
    public function testBotTargetsTheHumanOpponentWhenPlayingIntimidation(): void
    {
        $u1 = $this->insertUser('human12');
        $botUserId = $this->insertBotUser('bot10');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 67, 'hand', $botPlayerId); // Intimidation
        $this->insertGameCard($gameId, 8, 'hand', $p1); // human's own card, the reveal target
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        self::assertTrue($this->cardIsInPlay($gameId, 67));

        $pending = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertSame('intimidation_reveal_card', $pending['decision_type']);
        self::assertSame($p1, $pending['target_game_player_id']);
        self::assertTrue($pending['is_you']);
    }

    /**
     * End-to-end coverage of BotPlayerService::paranoiaTargetPlayerId()
     * (see BotPlayerServiceTest for the policy itself in isolation)
     * through the FULL advanceAutomatedTurns() -> playMood() ->
     * ParanoiaEffect::afterPlaying() request lifecycle -- unlike
     * Intimidation, Paranoia resolves entirely within afterPlaying()
     * itself (no pending decision), and throws outright against an
     * untargetable player, so this also proves paranoiaTargetPlayerId()
     * never hands it a bad candidate. The human keeps TWO cards
     * (Dignity/Charity) rather than one, deliberately -- with only one,
     * Paranoia's own random reveal would bottom their ENTIRE hand,
     * triggering the (real, separate) auto-pass-on-empty-hand feature
     * and handing the bot a second, legitimate play with its own
     * freshly-drawn card, which would make this test about that
     * interaction instead of Paranoia's own targeting. Which of the two
     * cards gets revealed is genuinely random, so this only asserts on
     * what's true either way: exactly one left the human's hand for the
     * bottom of the deck, one remains, and the bot drew its own card.
     */
    public function testBotTargetsTheHumanOpponentWhenPlayingParanoia(): void
    {
        $u1 = $this->insertUser('human13');
        $botUserId = $this->insertBotUser('bot11');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 71, 'hand', $botPlayerId); // Paranoia
        $this->insertGameCard($gameId, 8, 'hand', $p1); // Dignity
        $this->insertGameCard($gameId, 3, 'hand', $p1); // Charity -- one of these two is the reveal target
        $this->insertGameCard($gameId, 5, 'deck', deckPosition: 0); // the bot's own draw
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        self::assertTrue($this->cardIsInPlay($gameId, 71));

        $humanHandCount = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_cards WHERE game_id = :game_id AND owner_game_player_id = :p1 AND zone = 'hand'"
        );
        $humanHandCount->execute(['game_id' => $gameId, 'p1' => $p1]);
        self::assertSame(1, (int) $humanHandCount->fetchColumn(), 'exactly one of the human\'s two cards should remain in hand');

        $bottomedCount = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_cards WHERE game_id = :game_id AND card_id IN (8, 3) AND zone = 'deck'"
        );
        $bottomedCount->execute(['game_id' => $gameId]);
        self::assertSame(1, (int) $bottomedCount->fetchColumn(), 'exactly one of Dignity/Charity should have been bottomed');

        self::assertTrue($this->cardIsInHand($gameId, 5, $botUserId), 'the bot should have drawn a fresh card');
    }

    /**
     * End-to-end coverage of BotPlayerService::pacifismTargetMoodIds()
     * (see BotPlayerServiceTest for the policy itself in isolation)
     * through the FULL advanceAutomatedTurns() -> playMood() ->
     * PacifismEffect::afterPlaying() request lifecycle -- the human
     * opponent has two moods in play (Discipline, value 6, and Dignity,
     * value 3), so the bot's own optional target_mood_ids field gets
     * volunteered for with the human's own single HIGHEST-value mood
     * (Discipline), leaving Dignity untouched.
     */
    public function testBotSuppressesTheHumanOpponentsHighestValueMoodWhenPlayingPacifism(): void
    {
        $u1 = $this->insertUser('human15');
        $botUserId = $this->insertBotUser('bot13');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 20, 'hand', $botPlayerId); // Pacifism
        $this->insertGameCard($gameId, 9, 'in_play', $p1); // human's own Discipline, value 6 -- the suppression target
        $this->insertGameCard($gameId, 8, 'in_play', $p1); // human's own Dignity, value 3 -- should stay unsuppressed
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        self::assertTrue($this->cardIsInPlay($gameId, 20));

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $byCatalogId = [];
        foreach ($inPlay as $mood) {
            $byCatalogId[$mood['catalog_card_id']] = $mood;
        }

        self::assertTrue($byCatalogId[9]['is_suppressed'] ?? null, 'Discipline (the higher-value mood) should be suppressed by Pacifism');
        self::assertFalse($byCatalogId[8]['is_suppressed'] ?? true, 'Dignity should NOT be suppressed -- Pacifism only took the one higher-value target');
    }

    /**
     * End-to-end coverage of BotPlayerService::disillusionmentSafeColor()
     * (see BotPlayerServiceTest for the policy itself in isolation)
     * through the FULL advanceAutomatedTurns() -> respondToDecision() ->
     * DisillusionmentEffect::resolveDecisions() request lifecycle. The
     * HUMAN plays Disillusionment here (not the bot) -- DisillusionmentEffect's
     * own queueOrder() asks every seated player starting with the next
     * one in turn order, so with only 2 players at the table the bot is
     * asked FIRST, before the human's own turn to answer even opens. The
     * bot's own Dignity (white) is in play, so 'white' is unsafe -- it
     * should pick 'blue' instead (the next color in options order), which
     * happens to also be the human's own Ambivalence's color, proving the
     * bot both avoided its own mood AND still meaningfully answered
     * instead of just declining outright.
     */
    public function testBotChoosesASafeColorThatAvoidsItsOwnMoodWhenAnsweringDisillusionment(): void
    {
        $u1 = $this->insertUser('human16');
        $botUserId = $this->insertBotUser('bot14');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $disillusionmentId = $this->insertGameCard($gameId, 10, 'hand', $p1);
        $this->insertGameCard($gameId, 27, 'in_play', $p1); // human's own Ambivalence, blue
        $this->insertGameCard($gameId, 8, 'in_play', $botPlayerId); // bot's own Dignity, white -- should be avoided
        $this->insertGameRound($gameId, 1, $p1, $p1, 1);

        $result = $this->games->playMood($gameId, $p1, $disillusionmentId, []);
        self::assertTrue($result['pending_decision'] ?? false);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        // DisillusionmentEffect::resolveDecisions() only ever runs once the
        // WHOLE queue (every seated player) has answered -- the bot's own
        // step alone doesn't sweep anything yet, so this only confirms the
        // queue moved on to the human next, still waiting on p1's own turn
        // to decide.
        $pending = $this->games->getState($gameId, $u1)['round']['pending_decision'];
        self::assertSame('disillusionment_choose_color', $pending['decision_type']);
        self::assertSame($p1, $pending['target_game_player_id'], 'the queue should now be waiting on the human\'s own answer');

        $this->games->respondToDecision($gameId, $p1, []); // the human declines

        self::assertTrue($this->cardIsInPlay($gameId, 8), 'the bot should have avoided its own color, leaving Dignity untouched');

        $ambivalenceZone = $this->pdo->prepare(
            "SELECT zone FROM game_cards WHERE game_id = :game_id AND card_id = 27"
        );
        $ambivalenceZone->execute(['game_id' => $gameId]);
        self::assertSame('discard', $ambivalenceZone->fetchColumn(), 'the human\'s own blue Ambivalence should have been swept by the bot\'s "blue" pick');
    }

    /**
     * End-to-end coverage of BotPlayerService::creativityBestCopyTargetId()
     * (see BotPlayerServiceTest for the policy itself in isolation)
     * through the FULL advanceAutomatedTurns() -> playMood() ->
     * MoodPlayService's own copy-resolution request lifecycle. The human
     * has two moods in play (Apathy, value 4, and Bashfulness, value 6);
     * the bot's own Creativity should end up copying Bashfulness, the
     * higher-value one.
     */
    public function testBotCopiesTheHighestValueMoodInPlayWithCreativity(): void
    {
        $u1 = $this->insertUser('human17');
        $botUserId = $this->insertBotUser('bot15');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $creativityId = $this->insertGameCard($gameId, 32, 'hand', $botPlayerId);
        $this->insertGameCard($gameId, 55, 'in_play', $p1); // Apathy, value 4
        $this->insertGameCard($gameId, 30, 'in_play', $p1); // Bashfulness, value 6 -- the copy target
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $creativityCard = null;
        foreach ($inPlay as $mood) {
            if ($mood['card_id'] === $creativityId) {
                $creativityCard = $mood;
            }
        }

        self::assertNotNull($creativityCard, 'the bot\'s own Creativity should be in play');
        self::assertTrue($creativityCard['is_creativity_copy']);
        self::assertSame(30, $creativityCard['catalog_card_id'], 'Creativity should have copied Bashfulness, the higher-value mood');
    }

    /**
     * End-to-end coverage of creativityBestCopyTargetId()'s own
     * "to play" cost avoidance -- Self-Loathing (id 75, value 6) is
     * nominally the higher-value candidate, but it has its own "to play"
     * cost ("put one or more of your OWN moods into the discard pile"),
     * which the bot -- with nothing else in play -- can't pay. Copying it
     * anyway would make MoodPlayService::playMood() throw (the copy's own
     * to-play cost check), so the bot should skip it in favor of the SAFE
     * candidate, Apathy (value 4), rather than the play failing outright.
     */
    public function testBotSkipsAnUnpayableCopyTargetWithCreativity(): void
    {
        $u1 = $this->insertUser('human18');
        $botUserId = $this->insertBotUser('bot16');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $creativityId = $this->insertGameCard($gameId, 32, 'hand', $botPlayerId); // the bot's only hand card
        $this->insertGameCard($gameId, 55, 'in_play', $p1); // Apathy, value 4 -- safe
        $this->insertGameCard($gameId, 75, 'in_play', $p1); // Self-Loathing, value 6, has its own to-play cost
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $creativityCard = null;
        foreach ($inPlay as $mood) {
            if ($mood['card_id'] === $creativityId) {
                $creativityCard = $mood;
            }
        }

        self::assertNotNull($creativityCard);
        self::assertSame(55, $creativityCard['catalog_card_id'], 'Creativity should have copied the SAFE candidate, Apathy, not the unpayable Self-Loathing');
    }

    /**
     * End-to-end coverage of BotPlayerService::cynicismHasAGoodReasonToPlayNow()/
     * cynicismChoices() (see BotPlayerServiceTest for the policy itself
     * in isolation) through the FULL advanceAutomatedTurns() -> playMood()
     * -> CynicismEffect::afterPlaying() request lifecycle -- Cynicism's
     * own two fields (discard_card_id/recipient_player_id) are both
     * optional but genuinely interdependent (CynicismEffect throws if
     * one is set without the other), exactly the shape most likely to
     * slip through BotChoiceResolver's own generic per-field machinery
     * with a validation mismatch even though BotPlayerServiceTest's own
     * isolated checks pass, the same class of bug the two Rationalization
     * integration tests above already exist to catch for a different
     * card. Charity (id 3, value 1) sits in the discard pile, well under
     * CYNICISM_LOW_VALUE_DISCARD_THRESHOLD, so boosting is the only
     * legal outcome here.
     */
    public function testBotBoostsCynicismWithACheapDiscardPileCard(): void
    {
        $u1 = $this->insertUser('human10');
        $botUserId = $this->insertBotUser('bot7');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 62, 'hand', $botPlayerId); // Cynicism, base value 3
        $this->insertGameCard($gameId, 3, 'discard'); // Charity, value 1 -- cheap enough to give away for free
        $this->insertGameCard($gameId, 8, 'hand', $p1); // human needs a non-empty hand too
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        $inPlay = $this->games->getState($gameId, $u1)['in_play'];
        $cynicism = null;
        foreach ($inPlay as $mood) {
            if ($mood['catalog_card_id'] === 62) {
                $cynicism = $mood;
            }
        }
        self::assertNotNull($cynicism, 'Cynicism should be in play');
        self::assertSame(6, $cynicism['value'], "Cynicism's value should be boosted to 6");

        self::assertTrue($this->cardIsInHand($gameId, 3, $u1), 'Charity should have moved from the discard pile into the human opponent\'s hand');
    }

    /**
     * End-to-end coverage of BotPlayerService::shouldAttemptZealCycle()
     * (see BotPlayerServiceTest for the policy itself in isolation)
     * through the FULL advanceAutomatedTurns() -> playMood() ->
     * ZealEffect::afterPlaying() request lifecycle. Pacifism (id 20,
     * value 1, not itself an EARLY_PRIORITY_EFFECT_KEYS card -- unlike
     * Charity, which would otherwise compete with Zeal for the primary
     * play here) sits well under ZEAL_LOW_VALUE_HAND_CARD_THRESHOLD, so
     * cycling it is the only legal outcome.
     */
    public function testBotCyclesZealWithALowValueHandCard(): void
    {
        $u1 = $this->insertUser('human11');
        $botUserId = $this->insertBotUser('bot9');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 106, 'hand', $botPlayerId); // Zeal, base value 3
        $this->insertGameCard($gameId, 20, 'hand', $botPlayerId); // Pacifism, value 1 -- cheap enough to cycle
        $this->insertGameCard($gameId, 5, 'deck', deckPosition: 0); // the card the bot should draw
        $this->insertGameCard($gameId, 8, 'hand', $p1); // human needs a non-empty hand too
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        self::assertTrue($this->cardIsInPlay($gameId, 106));
        self::assertFalse($this->cardIsInHand($gameId, 20, $botUserId), 'Pacifism should have been bottomed, not kept');
        self::assertTrue($this->cardIsInHand($gameId, 5, $botUserId), 'the bot should have drawn a fresh card off the deck');
    }

    /**
     * End-to-end coverage of BotPlayerService::avoidanceHasAGoodReasonToPlay()/
     * avoidanceBestDirection() (see BotPlayerServiceTest for the policy
     * itself in isolation) through the FULL advanceAutomatedTurns() ->
     * playMood() -> AvoidanceEffect -> resolvePendingDecisions() request
     * lifecycle -- including the bot's own follow-up pending decision
     * (which of its own moods to give) resolving automatically too,
     * exactly the class of gap the two Rationalization integration tests
     * above already exist to catch for a different card. The bot's own
     * only mood (Charity, id 3, value 1) is cheap enough on its own
     * (AVOIDANCE_LOW_VALUE_MOOD_THRESHOLD) to guarantee the play
     * regardless of direction; the human opponent has no mood in play at
     * all, so AvoidanceEffect never even asks them for an answer
     * (moodsOwnedBy() === [] is skipped outright) and the whole exchange
     * resolves in this single advanceAutomatedTurns() call without
     * waiting on a real player.
     */
    public function testBotPlaysAvoidanceAndGivesAwayItsOwnCheapMood(): void
    {
        $u1 = $this->insertUser('human7');
        $botUserId = $this->insertBotUser('bot6');
        $gameId = $this->insertGame('standard', 'structure', $u1);
        $p1 = $this->insertGamePlayer($gameId, $u1, 0);
        $botPlayerId = $this->insertGamePlayer($gameId, $botUserId, 1);

        $this->insertGameCard($gameId, 29, 'hand', $botPlayerId); // Avoidance, value 3
        $charityId = $this->insertGameCard($gameId, 3, 'in_play', $botPlayerId); // Charity, value 1 -- the bot's only mood
        $this->insertGameCard($gameId, 8, 'hand', $p1); // human needs a non-empty hand too
        $this->insertGameRound($gameId, 1, $botPlayerId, $botPlayerId, 1);

        self::assertNotNull($this->games->advanceAutomatedTurns($gameId));

        self::assertTrue($this->cardIsInPlay($gameId, 29));

        $ownerStmt = $this->pdo->prepare('SELECT owner_game_player_id FROM game_cards WHERE id = :id');
        $ownerStmt->execute(['id' => $charityId]);
        self::assertSame($p1, (int) $ownerStmt->fetchColumn(), "Charity should have been given away to the bot's only opponent");
    }

    /**
     * Issue #359 exposed a real deadlock, reported live: a best-of-three
     * draft match's own "who goes first" freeze (setPlayFirstNextMatchGame(),
     * game 2/3's own round 1 -- see that method's own docblock) had no
     * bot handling at all until advanceBotFirstPlayerDecision() existed.
     * Before then, if the PREVIOUS game's loser happened to be a bot,
     * nothing would ever answer the decision and the round stayed frozen
     * forever -- advanceAutomatedTurns()'s own frozen-round branch only
     * ever tried advanceBotInitialCardPass() (a different, mutually
     * exclusive freeze), so this one just fell straight through to
     * "waiting on a real player" every single time.
     *
     * Built directly via raw SQL rather than playing an entire Quick
     * Draft match out end to end (GameServiceIntegrationTest's own
     * buildQuickDraftMatchAtGameTwoStart() does exactly that, but only
     * for two humans) -- advanceBotFirstPlayerDecision() only ever reads
     * games/game_players/game_rounds, so a real drafted pool/deck is
     * unnecessary; a stub draft_matches row satisfies games.draft_match_id's
     * own FK without needing draft_match_players at all.
     */
    public function testBotAutomaticallyDecidesWhoGoesFirstWhenItLostThePreviousGame(): void
    {
        $humanUserId = $this->insertUser('human6');
        $botUserId = $this->insertBotUser('bot5');

        $draftMatchStmt = $this->pdo->prepare(
            "INSERT INTO draft_matches (created_by_user_id, pool_source, pool_card_ids, status) VALUES (:creator, 'random_48', '[]', 'completed')"
        );
        $draftMatchStmt->execute(['creator' => $humanUserId]);
        $draftMatchId = (int) $this->pdo->lastInsertId();

        // Game 1: already completed -- the bot lost.
        $game1Id = $this->insertGame('draft', 'quick_draft', $humanUserId);
        $this->pdo->prepare("UPDATE games SET draft_match_id = :match_id, match_game_number = 1, status = 'completed' WHERE id = :game_id")
            ->execute(['match_id' => $draftMatchId, 'game_id' => $game1Id]);
        $humanGame1PlayerId = $this->insertGamePlayer($game1Id, $humanUserId, 0);
        $this->insertGamePlayer($game1Id, $botUserId, 1);
        $this->pdo->prepare('UPDATE games SET winner_game_player_id = :winner WHERE id = :game_id')
            ->execute(['winner' => $humanGame1PlayerId, 'game_id' => $game1Id]);

        // Game 2: in progress, round 1 frozen awaiting the loser's (the
        // bot's) decision -- first_game_player_id already holds
        // resolveFirstPlayerId()'s own placeholder (the previous
        // winner), current_turn_game_player_id stays NULL until the
        // decision resolves, same shape startGame() itself leaves it in.
        $game2Id = $this->insertGame('draft', 'quick_draft', $humanUserId);
        $this->pdo->prepare("UPDATE games SET draft_match_id = :match_id, match_game_number = 2, status = 'in_progress' WHERE id = :game_id")
            ->execute(['match_id' => $draftMatchId, 'game_id' => $game2Id]);
        $humanGame2PlayerId = $this->insertGamePlayer($game2Id, $humanUserId, 0);
        $botGame2PlayerId = $this->insertGamePlayer($game2Id, $botUserId, 1);
        $this->pdo->prepare(
            "INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, status)
             VALUES (:game_id, 1, :first_player, NULL, 1, 'in_progress')"
        )->execute(['game_id' => $game2Id, 'first_player' => $humanGame2PlayerId]);
        $this->insertGameCard($game2Id, 8, 'hand', $humanGame2PlayerId);
        $this->insertGameCard($game2Id, 3, 'hand', $botGame2PlayerId);

        $result = $this->games->advanceAutomatedTurns($game2Id);

        self::assertNotNull($result, 'the bot should have automatically resolved the frozen "who goes first" decision instead of deadlocking');
        $round = $this->fetchRound($game2Id);
        self::assertNotNull($round['current_turn_game_player_id'], 'the round should have unfrozen');
        self::assertSame($humanGame2PlayerId, (int) $round['current_turn_game_player_id'], 'the previous winner (human) should go first again -- the bot never opts to go first itself');
    }

    // -- helpers ------------------------------------------------------

    private function cardIsInPlay(int $gameId, int $catalogCardId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM game_cards WHERE game_id = :game_id AND card_id = :card_id AND zone = 'in_play'");
        $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId]);

        return $stmt->fetchColumn() !== false;
    }

    private function cardIsInHand(int $gameId, int $catalogCardId, ?int $ownerUserId = null): bool
    {
        if ($ownerUserId === null) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM game_cards WHERE game_id = :game_id AND card_id = :card_id AND zone = 'hand'");
            $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM game_cards gc JOIN game_players gp ON gp.id = gc.owner_game_player_id
             WHERE gc.game_id = :game_id AND gc.card_id = :card_id AND gc.zone = 'hand' AND gp.user_id = :user_id"
        );
        $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId, 'user_id' => $ownerUserId]);

        return $stmt->fetchColumn() !== false;
    }
}
