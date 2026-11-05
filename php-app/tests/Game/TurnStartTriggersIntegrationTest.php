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
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * "At the start of each of your turns" moods of the community set (TurnStartEffect), plus the other custom cards
 * that need real game flow (round wins, after-scoring): a real two-seat
 * duel is built straight in the database, a turn is handed over with pass(), and the trigger is
 * followed through its pending decision(s) with respondToDecision().
 */
final class TurnStartTriggersIntegrationTest extends TestCase
{
    private PDO $pdo;
    private GameService $games;
    private int $gameId;
    private int $p1;
    private int $p2;
    /** @var array<string, int> catalog card id by name */
    private array $catalogIds = [];

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
        foreach (['game_events', 'game_pending_decisions', 'game_pending_decision_batches', 'game_round_scores', 'game_cards', 'game_rounds', 'game_players', 'games', 'users'] as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        putenv("DB_HOST={$host}");
        putenv("DB_PORT={$port}");
        putenv("DB_NAME={$name}");
        putenv("DB_USER={$user}");
        putenv("DB_PASSWORD={$password}");

        $this->pdo = $pdo;
        foreach ($pdo->query('SELECT id, name FROM cards')->fetchAll() as $row) {
            $this->catalogIds[$row['name']] = (int) $row['id'];
        }
        if (!isset($this->catalogIds['Brooding'])) {
            self::markTestSkipped('Migration 0498 (custom set batch 2) is not applied to the test database');
        }

        $registry = DefaultEffectRegistry::build();
        $this->games = new GameService(
            new BoardStateRepository($registry),
            new MoodPlayService($registry),
            new RoundScorer(),
            new UserDecklistService(new UserDecklistRepository(), new FriendshipService(new UserRepository(), new FriendshipRepository())),
            new ReplayStateBuilder($registry),
            spawnAutomatedTurnRecheckProcesses: false,
        );

        foreach (['tsu1', 'tsu2'] as $username) {
            $pdo->prepare("INSERT INTO users (username, email, password_hash, email_verified_at) VALUES (:u, :e, 'hash', NOW())")
                ->execute(['u' => $username, 'e' => "{$username}@example.com"]);
        }
        $userIds = array_map('intval', $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare("INSERT INTO games (format, status, created_by_user_id, wins_needed) VALUES ('duel', 'in_progress', :u, 3)")->execute(['u' => $userIds[0]]);
        $this->gameId = (int) $pdo->lastInsertId();
        $insertPlayer = $pdo->prepare('INSERT INTO game_players (game_id, user_id, seat_order) VALUES (:g, :u, :s)');
        $insertPlayer->execute(['g' => $this->gameId, 'u' => $userIds[0], 's' => 0]);
        $this->p1 = (int) $pdo->lastInsertId();
        $insertPlayer->execute(['g' => $this->gameId, 'u' => $userIds[1], 's' => 1]);
        $this->p2 = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, pending_play_grants, status) VALUES (:g, 1, :p1, :p1, 1, '[null]', 'in_progress')")
            ->execute(['g' => $this->gameId, 'p1' => $this->p1]);
    }

    /** Inserts a card instance and returns its game_cards id (the id the rules engine uses). */
    private function card(string $name, string $zone, int $owner, ?int $deckPosition = null): int
    {
        $this->pdo->prepare('INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, deck_position) VALUES (:g, :c, :z, :o, :d)')
            ->execute(['g' => $this->gameId, 'c' => $this->catalogIds[$name], 'z' => $zone, 'o' => $owner, 'd' => $deckPosition]);

        return (int) $this->pdo->lastInsertId();
    }

    private function state(): BoardState
    {
        return (new BoardStateRepository(DefaultEffectRegistry::build()))->load($this->gameId);
    }

    /** Player 1 (the first player) ends their turn, handing player 2 the turn -- and so player 2's turn-start triggers. */
    private function handTurnToPlayerTwo(): void
    {
        $this->games->pass($this->gameId, $this->p1);
    }

    private function pendingDecisionTarget(): ?int
    {
        $value = $this->pdo->query('SELECT d.target_game_player_id FROM game_pending_decisions d JOIN game_pending_decision_batches b ON b.id = d.batch_id WHERE b.resolved_at IS NULL AND d.resolved_at IS NULL ORDER BY d.step_index LIMIT 1')->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    public function testMaturityGrowsAtTheStartOfItsOwnersTurnOnly(): void
    {
        $maturity = $this->card('Maturity', 'in_play', $this->p2);
        $this->card('Apathy', 'hand', $this->p1);
        $this->card('Apathy', 'hand', $this->p2);

        self::assertSame(2, $this->state()->valueOf($maturity));
        $this->handTurnToPlayerTwo();

        self::assertSame(4, $this->state()->valueOf($maturity));
        self::assertNull($this->pendingDecisionTarget());
    }

    public function testVolatilityDiscardsARandomLowMood(): void
    {
        $this->card('Volatility', 'in_play', $this->p2); // value 2 -- not low enough itself
        $creativity = $this->card('Creativity', 'in_play', $this->p1); // value 0
        $apathy = $this->card('Apathy', 'in_play', $this->p1); // value 4
        $this->card('Apathy', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();

        $state = $this->state();
        self::assertFalse($state->isInPlay($creativity));
        self::assertTrue($state->isInPlay($apathy));
        self::assertContains($creativity, $state->discardPile());
    }

    public function testRuminationAsksBeforeAnyonePlaysAndReturnsTheChosenMood(): void
    {
        $rumination = $this->card('Rumination', 'in_play', $this->p2);
        $apathy = $this->card('Apathy', 'in_play', $this->p2);
        $this->card('Creativity', 'hand', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();

        self::assertSame($this->p2, $this->pendingDecisionTarget());
        try {
            $this->games->playMood($this->gameId, $this->p2, $this->state()->hand($this->p2)[0], []);
            self::fail('A play was allowed while the turn-start decision was pending');
        } catch (GameStateException) {
            self::assertTrue(true);
        }

        $this->games->respondToDecision($this->gameId, $this->p2, ['target_mood_id' => $apathy]);

        $state = $this->state();
        self::assertFalse($state->isInPlay($apathy));
        self::assertContains($apathy, $state->hand($this->p2));
        self::assertTrue($state->isInPlay($rumination));
        self::assertNull($this->pendingDecisionTarget());
    }

    public function testDecliningAnOptionalTriggerJustLetsTheTurnProceed(): void
    {
        $apathy = $this->card('Apathy', 'in_play', $this->p2);
        $this->card('Rumination', 'in_play', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, []);

        self::assertTrue($this->state()->isInPlay($apathy));
        self::assertNull($this->pendingDecisionTarget());
    }

    public function testBroodingTakesFromTheDiscardPileThenAsksForADiscard(): void
    {
        $this->card('Brooding', 'in_play', $this->p2);
        $inDiscard = $this->card('Apathy', 'discard', $this->p1);
        $handCard = $this->card('Creativity', 'hand', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, ['take_card_id' => $inDiscard]);

        self::assertSame($this->p2, $this->pendingDecisionTarget(), 'the follow-up discard is asked next');
        self::assertContains($inDiscard, $this->state()->hand($this->p2));

        $this->games->respondToDecision($this->gameId, $this->p2, ['discard_hand_card_id' => $handCard]);

        $state = $this->state();
        self::assertContains($inDiscard, $state->hand($this->p2));
        self::assertContains($handCard, $state->discardPile());
        self::assertNull($this->pendingDecisionTarget());
    }

    public function testCovetousnessTakesALowOpposingMood(): void
    {
        $this->card('Covetousness', 'in_play', $this->p2);
        $low = $this->card('Creativity', 'in_play', $this->p1);
        $high = $this->card('Apathy', 'in_play', $this->p1);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        try {
            $this->games->respondToDecision($this->gameId, $this->p2, ['target_mood_id' => $high]);
            self::fail('A mood worth more than 1 was accepted');
        } catch (\MoodSwings\Rules\Exceptions\InvalidChoiceException $e) {
            self::assertStringContainsString('1 or less', $e->getMessage());
        }
        $this->games->respondToDecision($this->gameId, $this->p2, ['target_mood_id' => $low]);

        self::assertSame($this->p2, $this->state()->ownerOf($low));
        self::assertSame($this->p1, $this->state()->ownerOf($high));
    }

    public function testMartyrdomSacrificeMakesTheOpponentDiscard(): void
    {
        $this->card('Martyrdom', 'in_play', $this->p2);
        $sacrifice = $this->card('Apathy', 'in_play', $this->p2);
        $theirs = $this->card('Creativity', 'hand', $this->p1);
        $this->card('Complacency', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, ['sacrifice_mood_id' => $sacrifice]);

        self::assertSame($this->p1, $this->pendingDecisionTarget(), 'the opponent is asked what to discard');
        $this->games->respondToDecision($this->gameId, $this->p1, ['discard_card_id_' . $this->p1 => $theirs]);

        $state = $this->state();
        self::assertContains($sacrifice, $state->discardPile());
        self::assertContains($theirs, $state->discardPile());
        self::assertNull($this->pendingDecisionTarget());
    }

    public function testJealousyAsksOnlyAnOpponentWithMoreMoods(): void
    {
        $this->card('Jealousy', 'in_play', $this->p2);
        $a = $this->card('Apathy', 'in_play', $this->p1);
        $b = $this->card('Creativity', 'in_play', $this->p1);
        $this->card('Complacency', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();

        self::assertSame($this->p1, $this->pendingDecisionTarget());
        $this->games->respondToDecision($this->gameId, $this->p1, ['discard_mood_id_' . $this->p1 => $b]);

        $state = $this->state();
        self::assertFalse($state->isInPlay($b));
        self::assertTrue($state->isInPlay($a));
    }

    public function testJealousyDoesNothingWhenNoOpponentHasMoreMoods(): void
    {
        $this->card('Jealousy', 'in_play', $this->p2);
        $a = $this->card('Apathy', 'in_play', $this->p1);
        $this->card('Complacency', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();

        self::assertNull($this->pendingDecisionTarget());
        self::assertTrue($this->state()->isInPlay($a));
    }

    public function testNeglectHitsAnOpponentWithAnEmptyHand(): void
    {
        $this->card('Neglect', 'in_play', $this->p2);
        $a = $this->card('Apathy', 'in_play', $this->p1);
        $this->card('Complacency', 'hand', $this->p2); // p1 has no hand cards, and its turn already ended

        $this->handTurnToPlayerTwo();

        self::assertSame($this->p1, $this->pendingDecisionTarget());
        $this->games->respondToDecision($this->gameId, $this->p1, ['discard_mood_id_' . $this->p1 => $a]);
        self::assertFalse($this->state()->isInPlay($a));
    }

    public function testFairnessSuppressesTheSingleHighestMoodAutomatically(): void
    {
        $this->card('Fairness', 'in_play', $this->p2); // 1
        $high = $this->card('Apathy', 'in_play', $this->p1); // 4
        $this->card('Complacency', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();

        self::assertNull($this->pendingDecisionTarget());
        self::assertTrue($this->state()->isSuppressed($high));
    }

    public function testFairnessAsksWhenSeveralMoodsTieForHighest(): void
    {
        $this->card('Fairness', 'in_play', $this->p2);
        $first = $this->card('Apathy', 'in_play', $this->p1); // 4
        $second = $this->card('Complacency', 'in_play', $this->p1); // 4
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        self::assertSame($this->p2, $this->pendingDecisionTarget());
        $this->games->respondToDecision($this->gameId, $this->p2, ['target_mood_id' => $second]);

        $state = $this->state();
        self::assertTrue($state->isSuppressed($second));
        self::assertFalse($state->isSuppressed($first));
    }

    public function testFomoLeavesPlayWhenTheDiscardIsDeclined(): void
    {
        $fomo = $this->card('FOMO', 'in_play', $this->p2);
        $this->card('Creativity', 'hand', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, []);

        self::assertFalse($this->state()->isInPlay($fomo));
    }

    public function testFomoStaysWhenACardIsDiscarded(): void
    {
        $fomo = $this->card('FOMO', 'in_play', $this->p2);
        $handCard = $this->card('Creativity', 'hand', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, ['discard_card_id' => $handCard]);

        $state = $this->state();
        self::assertTrue($state->isInPlay($fomo));
        self::assertContains($handCard, $state->discardPile());
    }

    public function testFomoWithAnEmptyHandLeavesPlayWithoutAsking(): void
    {
        $fomo = $this->card('FOMO', 'in_play', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();

        self::assertNull($this->pendingDecisionTarget());
        self::assertFalse($this->state()->isInPlay($fomo));
    }

    public function testWastefulnessPutsTheTopOfTheOwnersDeckIntoTheDiscardPile(): void
    {
        $this->card('Wastefulness', 'in_play', $this->p2);
        $top = $this->card('Apathy', 'deck', $this->p2, 0);
        $next = $this->card('Complacency', 'deck', $this->p2, 1);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, ['discard_top_card' => true]);

        $state = $this->state();
        self::assertSame([$top], $state->discardPile());
        self::assertSame([$next], $state->deck($this->p2));
    }

    public function testVitalityBoostsOwnMoodsOfTheChosenColorUntilTheRoundEnds(): void
    {
        $this->card('Vitality', 'in_play', $this->p2); // green, 1
        $anger = $this->card('Anger', 'in_play', $this->p2); // red, 0
        $apathy = $this->card('Apathy', 'in_play', $this->p2); // black, 4
        $theirs = $this->card('Boredom', 'in_play', $this->p1); // red, 4 -- an opponent's mood is never boosted
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, ['color' => 'red']);

        $state = $this->state();
        self::assertSame(2, $state->valueOf($anger));
        self::assertSame(4, $state->valueOf($apathy));
        self::assertSame(4, $state->valueOf($theirs));

        $this->games->pass($this->gameId, $this->p2); // the round ends
        self::assertSame(0, $this->state()->valueOf($anger), 'the bonus lasts only until the end of the round');
        self::assertSame([], $this->state()->roundColorBonuses());
    }

    public function testVitalityAlsoBoostsMoodsOfThatColorPlayedLaterThisTurn(): void
    {
        $this->card('Vitality', 'in_play', $this->p2);
        $this->card('Anger', 'in_play', $this->p2);
        $this->card('Hope', 'in_play', $this->p2); // an extra play each turn, so the round is still open after Boredom
        $boredom = $this->card('Boredom', 'hand', $this->p2); // red, 4 -- played after the trigger
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, ['color' => 'red']);
        $this->games->playMood($this->gameId, $this->p2, $boredom, []);

        self::assertSame(6, $this->state()->valueOf($boredom), '4 printed, +2 from Vitality');
    }

    public function testVitalitysBonusSurvivesItLeavingPlayAndFollowsOwnership(): void
    {
        $vitality = $this->card('Vitality', 'in_play', $this->p2);
        $anger = $this->card('Anger', 'in_play', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, ['color' => 'red']);

        $state = $this->state();
        $state->moveInPlayToDiscard($vitality);
        self::assertSame(2, $state->valueOf($anger), 'Vitality leaving play does not end the bonus');

        $state->giveInPlayToPlayer($anger, $this->p1);
        self::assertSame(0, $state->valueOf($anger), "the bonus is for the chooser's moods: a mood that changes hands stops counting");
    }

    public function testPerseveranceGrowsAfterARoundThatLeavesItsOwnerBehind(): void
    {
        $perseverance = $this->card('Perseverance', 'in_play', $this->p1); // 2
        $this->card('Apathy', 'in_play', $this->p2); // 4 -- player 2 wins round 1
        $this->card('Creativity', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p2);

        $this->games->pass($this->gameId, $this->p1);
        $this->games->pass($this->gameId, $this->p2); // round 1 scores

        $state = $this->state();
        self::assertSame(4, $state->valueOf($perseverance), 'behind 0-1 after the round: +2, permanently');
        self::assertSame(1, $state->roundWinsOf($this->p2));
        self::assertSame(0, $state->roundWinsOf($this->p1));
    }

    public function testPerseveranceDoesNotGrowForTheRoundsWinner(): void
    {
        $perseverance = $this->card('Perseverance', 'in_play', $this->p1); // 2 -- beats nothing
        $this->card('Creativity', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p2);

        $this->games->pass($this->gameId, $this->p1);
        $this->games->pass($this->gameId, $this->p2); // player 1 wins round 1, 2-0

        self::assertSame(2, $this->state()->valueOf($perseverance));
        self::assertSame(1, $this->state()->roundWinsOf($this->p1));
    }

    public function testVindicationReadsRoundWinsFromTheScoredRounds(): void
    {
        $vindication = $this->card('Vindication', 'in_play', $this->p1);
        $this->pdo->prepare("INSERT INTO game_rounds (game_id, round_number, first_game_player_id, current_turn_game_player_id, plays_remaining, status, winner_game_player_id, wins_awarded) VALUES (:g, 0, :p, NULL, 0, 'scored', :p, 2)")
            ->execute(['g' => $this->gameId, 'p' => $this->p1]);

        self::assertSame(2 + 2 * 2, $this->state()->valueOf($vindication), 'a round that awarded two wins counts twice');
    }

    private function userIdOf(int $gamePlayerId): int
    {
        return (int) $this->pdo->query("SELECT user_id FROM game_players WHERE id = {$gamePlayerId}")->fetchColumn();
    }

    public function testCandorRevealsAnOpponentsHandToTheOwner(): void
    {
        $this->card('Candor', 'in_play', $this->p1);
        $secret = $this->card('Apathy', 'hand', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $mine = $this->games->getState($this->gameId, $this->userIdOf($this->p1));
        self::assertCount(1, $mine['you']['revealed_hands']);
        self::assertSame($this->p2, $mine['you']['revealed_hands'][0]['game_player_id']);
        self::assertSame([$secret], array_column($mine['you']['revealed_hands'][0]['hand'], 'card_id'));

        $theirs = $this->games->getState($this->gameId, $this->userIdOf($this->p2));
        self::assertArrayNotHasKey('revealed_hands', $theirs['you'], "the owner's own hand isn't revealed, and an opponent's own hand needs no reveal to them");
    }

    public function testCandorsRevealIsToAllPlayersSoOpponentsSeeEachOthersHands(): void
    {
        $this->pdo->prepare("INSERT INTO users (username, email, password_hash, email_verified_at) VALUES ('tsu3', 'tsu3@example.com', 'hash', NOW())")->execute();
        $thirdUserId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO game_players (game_id, user_id, seat_order) VALUES (:g, :u, 2)')->execute(['g' => $this->gameId, 'u' => $thirdUserId]);
        $p3 = (int) $this->pdo->lastInsertId();

        $this->card('Candor', 'in_play', $this->p1);
        $hand2 = $this->card('Apathy', 'hand', $this->p2);
        $hand3 = $this->card('Complacency', 'hand', $p3);
        $hand1 = $this->card('Creativity', 'hand', $this->p1);

        $seenBy = fn (int $gamePlayerId): array => array_column(
            $this->games->getState($this->gameId, $this->userIdOf($gamePlayerId))['you']['revealed_hands'] ?? [],
            'hand',
            'game_player_id',
        );

        $owner = $seenBy($this->p1);
        self::assertSame([$hand2], array_column($owner[$this->p2], 'card_id'));
        self::assertSame([$hand3], array_column($owner[$p3], 'card_id'));

        $second = $seenBy($this->p2);
        self::assertSame([$p3], array_keys($second), 'player 2 sees player 3\'s hand -- both are the owner\'s opponents');
        self::assertSame([$hand3], array_column($second[$p3], 'card_id'));
        self::assertNotContains($hand1, array_column($second[$p3], 'card_id'));
    }

    public function testWithoutCandorNoHandsAreRevealed(): void
    {
        $this->card('Apathy', 'hand', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $state = $this->games->getState($this->gameId, $this->userIdOf($this->p1));
        self::assertArrayNotHasKey('revealed_hands', $state['you']);
    }

    public function testHonestysRevealIsNamedInTheLogForItsOwner(): void
    {
        $honesty = $this->card('Honesty', 'hand', $this->p1);
        $this->card('Laziness', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $honesty, []);

        $log = array_column($this->games->fullEventLog($this->gameId), 'description');
        self::assertStringContainsString("revealing Laziness from tsu1's hand", implode("\n", $log));
    }

    public function testGreedTakesAChosenCardFromTheRevealedHandAndTheOpponentRedraws(): void
    {
        $greed = $this->card('Greed', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p1);
        $keepable = $this->card('Complacency', 'hand', $this->p2);
        $taken = $this->card('Apathy', 'hand', $this->p2);
        $drawn = $this->card('Courage', 'deck', $this->p2, 0);

        $this->games->playMood($this->gameId, $this->p1, $greed, ['target_player_id' => $this->p2]);

        self::assertSame($this->p1, $this->pendingDecisionTarget(), 'the player who played Greed picks');
        $pending = $this->games->getState($this->gameId, $this->userIdOf($this->p1))['round']['pending_decision'];
        self::assertSame('revealed_card', $pending['field']['type']);
        $labels = array_column($pending['field']['options'], 'label', 'value');
        self::assertSame('Apathy (black, 4)', $labels[$taken]);
        self::assertCount(2, $labels, "exactly the opponent's two hand cards");

        $this->games->respondToDecision($this->gameId, $this->p1, ['picked_card_id' => $taken]);

        $state = $this->state();
        self::assertContains($taken, $state->hand($this->p1));
        self::assertEqualsCanonicalizing([$keepable, $drawn], $state->hand($this->p2));
        $log = implode("\n", array_column($this->games->fullEventLog($this->gameId), 'description'));
        self::assertStringContainsString("revealing Complacency, Apathy from tsu2's hand", str_replace('Apathy, Complacency', 'Complacency, Apathy', $log));
    }

    public function testGreedRejectsAnOpponentWithNoCards(): void
    {
        $greed = $this->card('Greed', 'hand', $this->p1);

        $this->expectException(\MoodSwings\Rules\Exceptions\InvalidChoiceException::class);
        $this->games->playMood($this->gameId, $this->p1, $greed, ['target_player_id' => $this->p2]);
    }

    public function testNosinessMakesTheOpponentDiscardTheCardTheOwnerChooses(): void
    {
        $nosiness = $this->card('Nosiness', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p1);
        $kept = $this->card('Complacency', 'hand', $this->p2);
        $lost = $this->card('Apathy', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $nosiness, ['target_player_id' => $this->p2]);
        $this->games->respondToDecision($this->gameId, $this->p1, ['picked_card_id' => $lost]);

        $state = $this->state();
        self::assertSame([$kept], $state->hand($this->p2));
        self::assertContains($lost, $state->discardPile());
    }

    public function testEntitlementRevealPathLetsItsOwnerTakeACardAndPlayItAtOnce(): void
    {
        $entitlement = $this->card('Entitlement', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p1);
        $this->card('Boredom', 'in_play', $this->p2);
        $other = $this->card('Complacency', 'hand', $this->p2);
        $taken = $this->card('Apathy', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $entitlement, ['target_player_id' => $this->p2]);
        self::assertSame($this->p2, $this->pendingDecisionTarget(), 'the target chooses whether to reveal');
        $this->games->respondToDecision($this->gameId, $this->p2, ['reveal_hand' => true]);

        self::assertSame($this->p1, $this->pendingDecisionTarget(), 'then the owner picks from the revealed hand');
        $this->games->respondToDecision($this->gameId, $this->p1, ['taken_card_id' => $taken]);

        self::assertContains($taken, $this->state()->hand($this->p1));
        self::assertSame([$other], $this->state()->hand($this->p2));
        $this->games->playMood($this->gameId, $this->p1, $taken, []); // the granted extra play, for that card only
        self::assertTrue($this->state()->isInPlay($taken));
    }

    public function testEntitlementDeclinedMakesTheTargetLoseAHighestMood(): void
    {
        $entitlement = $this->card('Entitlement', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p1);
        $high = $this->card('Apathy', 'in_play', $this->p2); // 4
        $low = $this->card('Creativity', 'in_play', $this->p2); // 0
        $this->card('Complacency', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $entitlement, ['target_player_id' => $this->p2]);
        $this->games->respondToDecision($this->gameId, $this->p2, []);

        self::assertNull($this->pendingDecisionTarget(), 'a single highest mood goes without a further choice');
        $state = $this->state();
        self::assertFalse($state->isInPlay($high));
        self::assertTrue($state->isInPlay($low));
    }

    public function testEntitlementDeclinedWithTiedHighestMoodsLetsTheTargetChoose(): void
    {
        $entitlement = $this->card('Entitlement', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p1);
        $first = $this->card('Apathy', 'in_play', $this->p2); // 4
        $second = $this->card('Boredom', 'in_play', $this->p2); // 4
        $this->card('Complacency', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $entitlement, ['target_player_id' => $this->p2]);
        $this->games->respondToDecision($this->gameId, $this->p2, []);

        self::assertSame($this->p2, $this->pendingDecisionTarget());
        $this->games->respondToDecision($this->gameId, $this->p2, ['discard_mood_id' => $second]);

        self::assertTrue($this->state()->isInPlay($first));
        self::assertFalse($this->state()->isInPlay($second));
    }

    public function testEntitlementNeedsAnOpponentWithACardAndAMood(): void
    {
        $entitlement = $this->card('Entitlement', 'hand', $this->p1);
        $this->card('Complacency', 'hand', $this->p2); // a hand card but no mood

        $this->expectException(\MoodSwings\Rules\Exceptions\InvalidChoiceException::class);
        $this->games->playMood($this->gameId, $this->p1, $entitlement, ['target_player_id' => $this->p2]);
    }

    public function testInspirationKeepsTheChosenOfTheTopThreeBottomsTheRestAndGrantsAPlayOfIt(): void
    {
        $inspiration = $this->card('Inspiration', 'hand', $this->p1);
        $first = $this->card('Apathy', 'deck', $this->p1, 0);
        $second = $this->card('Complacency', 'deck', $this->p1, 1);
        $third = $this->card('Creativity', 'deck', $this->p1, 2);
        $fourth = $this->card('Courage', 'deck', $this->p1, 3);
        $this->card('Boredom', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $inspiration, []);
        $pending = $this->games->getState($this->gameId, $this->userIdOf($this->p1))['round']['pending_decision'];
        self::assertEqualsCanonicalizing([$first, $second, $third], array_column($pending['field']['options'], 'value'), 'only the top three are offered');

        $this->games->respondToDecision($this->gameId, $this->p1, ['chosen_card_id' => $second]);

        $state = $this->state();
        self::assertSame([$second], $state->hand($this->p1));
        self::assertSame($fourth, $state->deck($this->p1)[0], 'the unrevealed fourth card is now on top');
        self::assertEqualsCanonicalizing([$first, $third], array_slice($state->deck($this->p1), 1), 'the other two went to the bottom');
        $this->games->playMood($this->gameId, $this->p1, $second, []); // the granted extra play
        self::assertTrue($this->state()->isInPlay($second));
    }

    public function testInspirationRejectsACardOutsideTheTopThree(): void
    {
        $inspiration = $this->card('Inspiration', 'hand', $this->p1);
        $this->card('Apathy', 'deck', $this->p1, 0);
        $this->card('Complacency', 'deck', $this->p1, 1);
        $this->card('Creativity', 'deck', $this->p1, 2);
        $fourth = $this->card('Courage', 'deck', $this->p1, 3);
        $this->card('Boredom', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $inspiration, []);

        $this->expectException(\MoodSwings\Rules\Exceptions\InvalidChoiceException::class);
        $this->games->respondToDecision($this->gameId, $this->p1, ['chosen_card_id' => $fourth]);
    }

    public function testInspirationWithAnEmptyDeckDoesNothing(): void
    {
        $inspiration = $this->card('Inspiration', 'hand', $this->p1);
        $this->card('Boredom', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $inspiration, []);

        self::assertNull($this->pendingDecisionTarget());
        self::assertTrue($this->state()->isInPlay($inspiration));
    }

    public function testSchadenfreudeBecomesSixWhenAnOpponentDiscardsAndStaysThatWayForTheRound(): void
    {
        $schadenfreude = $this->card('Schadenfreude', 'in_play', $this->p1);
        $suspicion = $this->card('Suspicion', 'hand', $this->p1);
        $forced = $this->card('Apathy', 'hand', $this->p2);
        $this->card('Creativity', 'hand', $this->p2);

        self::assertSame(3, $this->state()->valueOf($schadenfreude));
        $this->games->playMood($this->gameId, $this->p1, $suspicion, ['player_ids' => [$this->p2]]);
        $this->games->respondToDecision($this->gameId, $this->p2, ['discarded_card_id_' . $this->p2 => $forced]);

        self::assertSame(6, $this->state()->valueOf($schadenfreude), "player 2 discarded a card from hand; it's remembered across requests");
        // The discard also counts on the next turn of the same round -- the record is round-scoped.
        self::assertSame(6, $this->state()->valueOf($schadenfreude));
    }

    public function testSchadenfreudeIsThreeAgainAtTheStartOfTheNextRound(): void
    {
        $schadenfreude = $this->card('Schadenfreude', 'in_play', $this->p1);
        $suspicion = $this->card('Suspicion', 'hand', $this->p1);
        $forced = $this->card('Apathy', 'hand', $this->p2);
        $this->card('Creativity', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $suspicion, ['player_ids' => [$this->p2]]);
        $this->games->respondToDecision($this->gameId, $this->p2, ['discarded_card_id_' . $this->p2 => $forced]);
        self::assertSame(6, $this->state()->valueOf($schadenfreude));

        $this->games->pass($this->gameId, $this->p2); // the round ends

        self::assertSame(3, $this->state()->valueOf($schadenfreude), 'the record does not carry into the next round');
    }

    public function testAnOpponentsMoodGoingToTheDiscardPileDoesNotTurnSchadenfreudeOn(): void
    {
        $schadenfreude = $this->card('Schadenfreude', 'in_play', $this->p1);
        $rashness = $this->card('Rashness', 'hand', $this->p1); // discards an opposing mood worth 1 or less
        $victim = $this->card('Creativity', 'in_play', $this->p2);
        $this->card('Boredom', 'hand', $this->p2);

        $this->games->playMood($this->gameId, $this->p1, $rashness, ['target_mood_id' => $victim]);

        self::assertSame(3, $this->state()->valueOf($schadenfreude));
    }

    public function testUnityWinsTheRoundForItsOwnerRegardlessOfScore(): void
    {
        $this->card('Unity', 'in_play', $this->p1);
        foreach (['Anger', 'Rashness', 'Belligerence', 'Intuition', 'Exuberance'] as $red) {
            $this->card($red, 'in_play', $this->p1); // five red moods, worth very little
        }
        foreach (['Bashfulness', 'Neurosis', 'Apathy'] as $big) {
            $this->card($big, 'in_play', $this->p2); // 15 points against p1's 9
        }
        $this->card('Creativity', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p2);

        $this->games->pass($this->gameId, $this->p1);
        $this->games->pass($this->gameId, $this->p2); // round 1 scores

        self::assertSame(1, $this->state()->roundWinsOf($this->p1), 'Unity made player 1 the winner');
        self::assertSame(0, $this->state()->roundWinsOf($this->p2));
    }

    public function testWithoutUnityTheHigherScoreWins(): void
    {
        foreach (['Anger', 'Rashness', 'Belligerence', 'Intuition', 'Exuberance'] as $red) {
            $this->card($red, 'in_play', $this->p1);
        }
        foreach (['Bashfulness', 'Neurosis', 'Apathy'] as $big) {
            $this->card($big, 'in_play', $this->p2);
        }
        $this->card('Creativity', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p2);

        $this->games->pass($this->gameId, $this->p1);
        $this->games->pass($this->gameId, $this->p2);

        self::assertSame(1, $this->state()->roundWinsOf($this->p2));
    }

    public function testReplayFollowsAWastefulnessCardFromTheDeckToTheDiscardPile(): void
    {
        $this->card('Wastefulness', 'in_play', $this->p2);
        $top = $this->card('Apathy', 'deck', $this->p2, 0);
        $next = $this->card('Complacency', 'deck', $this->p2, 1);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();
        $this->games->respondToDecision($this->gameId, $this->p2, ['discard_top_card' => true]);

        $this->pdo->exec("UPDATE games SET status = 'completed', completed_at = NOW() WHERE id = {$this->gameId}");
        $replay = new ReplayStateBuilder(DefaultEffectRegistry::build());
        $events = $this->games->fullEventLog($this->gameId);
        $resolved = null;
        foreach ($events as $event) {
            if ($event['event_type'] === 'pending_decision_resolved') {
                $resolved = $event['id'];
            }
        }
        self::assertNotNull($resolved);

        $after = $replay->stateAsOf($this->gameId, $resolved);
        self::assertSame([$top], $after->discardPile());
        self::assertSame([$next], $after->deck($this->p2));
        $before = $replay->stateAsOf($this->gameId, $resolved - 1);
        self::assertSame([], $before->discardPile());
        self::assertSame([$top, $next], $before->deck($this->p2));
    }

    public function testSeveralTriggersRunOneAfterAnotherInPlayOrder(): void
    {
        $maturity = $this->card('Maturity', 'in_play', $this->p2);
        $rumination = $this->card('Rumination', 'in_play', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();

        // Maturity (automatic) has already fired, and the round waits on Rumination.
        self::assertSame(4, $this->state()->valueOf($maturity));
        self::assertSame($this->p2, $this->pendingDecisionTarget());
        $this->games->respondToDecision($this->gameId, $this->p2, ['target_mood_id' => $rumination]);
        self::assertContains($rumination, $this->state()->hand($this->p2));
        self::assertNull($this->pendingDecisionTarget());
    }

    public function testTriggersFireAtTheStartOfTheNextRoundForItsFirstPlayer(): void
    {
        $maturity = $this->card('Maturity', 'in_play', $this->p1);
        $this->card('Creativity', 'hand', $this->p1);
        $this->card('Creativity', 'hand', $this->p2);

        $this->games->pass($this->gameId, $this->p1);
        $this->games->pass($this->gameId, $this->p2); // round 1 scores; Maturity's owner wins and goes first

        self::assertSame(2, $this->state()->valueOf($maturity) - 2, 'Maturity grew once, at the start of round 2');
    }

    public function testResilienceGrantsAnExtraPlayFromTheDiscardPileEachTurn(): void
    {
        $this->card('Resilience', 'in_play', $this->p2);
        $low = $this->card('Creativity', 'discard', $this->p1);
        $this->card('Apathy', 'discard', $this->p1);
        $this->card('Complacency', 'hand', $this->p2);
        $this->card('Creativity', 'hand', $this->p1);

        $this->handTurnToPlayerTwo();

        $round = $this->pdo->query('SELECT plays_remaining FROM game_rounds')->fetch();
        self::assertSame(2, (int) $round['plays_remaining']);
        // The extra play can only take a 0-3 mood from the discard pile.
        $this->games->playMood($this->gameId, $this->p2, $low, []);
        self::assertTrue($this->state()->isInPlay($low));
    }
}
