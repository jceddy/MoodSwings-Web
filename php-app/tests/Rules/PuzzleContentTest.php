<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\IllegalPlayException;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #524: engine-verifies every puzzle seeded by
 * database/migrations/0394_add_puzzle_collection.sql -- for each one,
 * plays the intended solution through the real GameService/MoodPlayService
 * pipeline (createPuzzleAttempt() -> playMood() -> checkPuzzleGoal()) and
 * asserts the attempt actually ends up completed, and, where the puzzle
 * has a tempting wrong line, asserts that line does NOT solve it. This is
 * both the pre-ship verification the feature's own plan calls for and a
 * permanent regression test -- a future card-effect change that silently
 * breaks a puzzle's solvability gets caught here.
 */
final class PuzzleContentTest extends TestCase
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
        $pdo->exec('TRUNCATE TABLE puzzle_solves');
        $pdo->exec('TRUNCATE TABLE game_events');
        $pdo->exec('TRUNCATE TABLE game_pending_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decision_batches');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_rounds');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE user_achievements');
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
            spawnAutomatedTurnRecheckProcesses: false,
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

    private function puzzleIdForSlug(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM puzzles WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{gameId: int, gamePlayerId: int} */
    private function attempt(string $slug): array
    {
        $userId = $this->insertUser("solver_{$slug}_" . bin2hex(random_bytes(4)));

        return $this->attemptAs($userId, $slug);
    }

    /** Same as attempt(), but for an already-existing $userId -- needed to solve more than one puzzle as the SAME user (e.g. to exercise achievement progress across attempts). */
    private function attemptAs(int $userId, string $slug): array
    {
        $gameId = $this->games->createPuzzleAttempt($userId, $this->puzzleIdForSlug($slug));

        $stmt = $this->pdo->prepare('SELECT id FROM game_players WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        return ['gameId' => $gameId, 'gamePlayerId' => (int) $stmt->fetchColumn()];
    }

    /**
     * Plays a mood and, if Duplicity is in play and the card just played
     * has an after-playing ability of its own, automatically declines the
     * resulting "repeat it again?" offer -- none of these puzzles need
     * the repeat, so a real player would just decline it too. Without
     * this, playMood() itself would return with pending_decision => true
     * instead of resolving the play (see MoodPlayService's
     * duplicityRepeatOfferRequest()).
     */
    private function play(int $gameId, int $gamePlayerId, int $cardId, array $choices = []): array
    {
        $result = $this->games->playMood($gameId, $gamePlayerId, $cardId, $choices);
        if ($result['pending_decision'] ?? false) {
            $result = $this->games->respondToDecision($gameId, $gamePlayerId, ['duplicity_repeat' => ['repeat' => false]]);
        }

        return $result;
    }

    /** The current in-game instance id of the one card matching $catalogCardId in $zone (there's never more than one per zone in these puzzles). */
    private function instanceId(int $gameId, int $catalogCardId, string $zone): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_cards WHERE game_id = :game_id AND card_id = :card_id AND zone = :zone LIMIT 1'
        );
        $stmt->execute(['game_id' => $gameId, 'card_id' => $catalogCardId, 'zone' => $zone]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "No card {$catalogCardId} in zone '{$zone}' for game {$gameId}");

        return (int) $id;
    }

    private function assertGameSolved(int $gameId, int $gamePlayerId): void
    {
        $stmt = $this->pdo->prepare('SELECT status, winner_game_player_id FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);
        $row = $stmt->fetch();

        self::assertSame('completed', $row['status']);
        self::assertSame($gamePlayerId, (int) $row['winner_game_player_id']);
    }

    private function assertGameNotSolved(int $gameId): void
    {
        $stmt = $this->pdo->prepare('SELECT status FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);

        self::assertSame('in_progress', $stmt->fetchColumn());
    }

    private function userIdForGamePlayer(int $gamePlayerId): int
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM game_players WHERE id = :id');
        $stmt->execute(['id' => $gamePlayerId]);

        return (int) $stmt->fetchColumn();
    }

    public function testOneFellSwoopSolvedByDecliningAmbitionsDiscard(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('one-fell-swoop');

        // Friendliness -> Kindness -> Charity -> Ambition, and Ambition's
        // own optional discard is DECLINED (no 'discard_card_id') -- no
        // card ever reaches the discard pile this round, so Vulnerability
        // stays at its base value 1. Final board: solver 1+2+2+2=7 vs
        // PuzzleOpponent's static Vulnerability(1) + Neurosis(5) = 6.
        $this->play($gameId, $p, $this->instanceId($gameId, 13, 'hand'), []); // Friendliness
        $this->play($gameId, $p, $this->instanceId($gameId, 17, 'hand'), []); // Kindness
        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 53, 'hand'), []); // Ambition, no discard

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testOneFellSwoopTakingAmbitionsDiscardBoostsTheOpponentInstead(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('one-fell-swoop');

        // The tempting wrong line: play Ambition first and actually take
        // its discard, expecting the extra play to help -- but ANY card
        // reaching the discard pile this round (not just the acting
        // player's own board) is what flips PuzzleOpponent's own
        // Vulnerability from 1 to 7. Final board: solver
        // Ambition(2)+Charity(1)+Friendliness(2)=5 (Kindness discarded
        // instead of played) vs opponent's now-boosted
        // Vulnerability(7)+Neurosis(5)=12 -- a decisive loss, not a win.
        $kindnessId = $this->instanceId($gameId, 17, 'hand');
        $this->play($gameId, $p, $this->instanceId($gameId, 53, 'hand'), ['discard_card_id' => $kindnessId]); // Ambition, discards Kindness
        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 13, 'hand'), []); // Friendliness

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    /**
     * Reported live: "Add a 'hint' button when in the puzzle, that pops
     * up a dialog with the following hint". getState()'s own
     * game.puzzle_hint is what that button reads (see game.js'
     * renderPuzzleHintButton()) -- One Fell Swoop is the one puzzle
     * seeded with a real hint (migration 0396), everything else stays
     * null so the frontend hides the button entirely for a puzzle with
     * no hint set.
     */
    public function testOneFellSwoopExposesItsHintViaGetState(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('one-fell-swoop');

        $state = $this->games->getState($gameId, $this->userIdForGamePlayer($p));

        self::assertSame("Think carefully before taking Ambition's discard option.", $state['game']['puzzle_hint']);
    }

    public function testChainReactionHasNoHint(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('chain-reaction');

        $state = $this->games->getState($gameId, $this->userIdForGamePlayer($p));

        self::assertNull($state['game']['puzzle_hint']);
    }

    public function testVainEffortExposesItsHintViaGetState(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('vain-effort');

        $state = $this->games->getState($gameId, $this->userIdForGamePlayer($p));

        self::assertSame('Vanity is worth a lot more once your hand is completely empty.', $state['game']['puzzle_hint']);
    }

    public function testWondersChoiceExposesItsHintViaGetState(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('wonders-choice');

        $state = $this->games->getState($gameId, $this->userIdForGamePlayer($p));

        self::assertSame('Which cards does Wonder count?', $state['game']['puzzle_hint']);
    }

    /**
     * Bug caught live via a manual smoke test through the real HTTP
     * route (which -- unlike every other test here -- calls
     * advanceAutomatedTurns() right after playMood(), exactly like
     * POST /games/play does): every user defaults to
     * auto_pass_on_empty_hand = 1, and a solved puzzle's own game_rounds
     * row used to stay status = 'in_progress' forever (advancePuzzleTurn()
     * only ever refreshes it, never scores it), so advanceAutomatedTurns()
     * kept auto-passing the already-solved, now-empty-handed puzzle up to
     * MAX_AUTOMATED_ACTIONS_PER_REQUEST times and returned that instead,
     * clobbering the real game_completed => true the solving play itself
     * had already produced. checkPuzzleGoal() now also flips the round to
     * 'scored' on a solve, so currentRound() throws afterward the same
     * way it already does for every other completed format, and
     * advanceAutomatedTurns() correctly finds nothing left to drive.
     */
    public function testAdvanceAutomatedTurnsDoesNotClobberASolvedPuzzlesResponse(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('chain-reaction');

        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []);
        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []);
        self::assertTrue($result['game_completed']);

        $autoResult = $this->games->advanceAutomatedTurns($gameId);
        self::assertNull($autoResult, 'nothing should be left to auto-drive once a puzzle is solved');
        $this->assertGameSolved($gameId, $p);
    }

    /**
     * Reported live: "There is a Joy in the discard pile. You have
     * Conviction in your hand, and one extra play from Joy... Win the
     * game in one turn." Conviction's own "choose a mood, its player
     * bottoms it and draws a card" is a legal target on ANY mood in play
     * -- targeting Conviction itself (rather than one of the opponent's
     * own Benevolence/Shock, see the two tests below) is what makes the
     * solver themselves the one who draws. That draw is Chivalry, seeded
     * on top of the deck -- worth 5 while in play since the solver didn't
     * go first this round. Playing it with the extra play already banked
     * from Joy puts the solver at exactly 5, outscoring the opponent's
     * Benevolence(2) + Shock(2) = 4.
     */
    public function testTurnItOnYourselfSolvedByTargetingConvictionItself(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('turn-it-on-yourself');

        $convictionId = $this->instanceId($gameId, 6, 'hand');
        $firstResult = $this->play($gameId, $p, $convictionId, ['target_mood_id' => $convictionId]);
        self::assertFalse($firstResult['game_completed']);

        $chivalryId = $this->instanceId($gameId, 4, 'hand');
        $result = $this->play($gameId, $p, $chivalryId, []);

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    /**
     * The tempting wrong line: Conviction's own text never says "one of
     * YOUR moods" -- targeting the opponent's own Benevolence is just as
     * legal. But its OWNER draws the replacement card, not the acting
     * player, so the solver's own hand stays empty and their extra play
     * from Joy goes unused. Final board: solver's own Conviction(2) vs
     * the opponent's remaining Shock(2) -- a tie, which goes to the
     * opponent (they went first this round), not a solver win.
     */
    public function testTurnItOnYourselfTargetingBenevolenceInsteadEndsInATieTheOpponentWins(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('turn-it-on-yourself');

        $convictionId = $this->instanceId($gameId, 6, 'hand');
        $benevolenceId = $this->instanceId($gameId, 2, 'in_play');
        $result = $this->play($gameId, $p, $convictionId, ['target_mood_id' => $benevolenceId]);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    /** Same trap as above, targeting Shock instead -- leaves the opponent's Benevolence(2) tied against the solver's own Conviction(2). */
    public function testTurnItOnYourselfTargetingShockInsteadEndsInATieTheOpponentWins(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('turn-it-on-yourself');

        $convictionId = $this->instanceId($gameId, 6, 'hand');
        $shockId = $this->instanceId($gameId, 101, 'in_play');
        $result = $this->play($gameId, $p, $convictionId, ['target_mood_id' => $shockId]);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    /**
     * Reported live: "The opponent has Benevolence and Happiness in
     * play... You have Charity, Idealism, Indifference, and Animosity in
     * hand." Animosity's own boosted value turned out to already be true
     * from turn 1 in a puzzle (the opponent seat never acts, so its hand
     * size never changes), letting it solve the puzzle alone in one play
     * -- landed instead (confirmed live, deliberately not reusing One
     * Fell Swoop's own discard-pile/Vulnerability trap) on Self-Loathing,
     * which is actually illegal to play before the chain even starts (see
     * the test below), so there's no one-move shortcut here at all.
     * Opponent: Superiority(3, spikes to 7 if its owner has more moods
     * than every other player) + Malice(0) + Spite(1) -- exactly 3 moods,
     * both fillers otherwise inert since a puzzle's opponent cards are
     * dealt straight into play, never actually "played" through the
     * engine. Charity(1) + Idealism(0) + Indifference(4) = 5 solver moods
     * (3 of them) tied with the opponent's own 3 -- not fewer -- so
     * Superiority stays at its base value (3) and the total (4) is
     * cleared.
     */
    public function testChainReactionSolvedByPlayingVanillaLast(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('chain-reaction');

        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity
        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []); // Idealism
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []); // Indifference

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    /**
     * Playing the vanilla card first leaves the solver with just 1 mood
     * against the opponent's 3 -- fewer, not tied -- spiking Superiority
     * to 7 (opponent totals 8) against the solver's own 4, a decisive
     * loss. And even once it refreshes into a fresh mini-turn and the
     * other two get played too (bringing the solver back up to 3 moods,
     * tied with the opponent again, and the score back to a winning 5-4),
     * that refresh already happened, so max_plays' own "one unbroken
     * turn" requirement blocks it from ever counting as solved regardless
     * of the final score.
     */
    public function testChainReactionVanillaFirstDoesNotSolveIt(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('chain-reaction');

        $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []); // Indifference first -- stalls
        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    /**
     * Self-Loathing's own "to play this card, put one or more of your
     * moods into the discard pile. If you can't do that, you can't play
     * this card" makes it genuinely illegal as an opening move -- there's
     * no mood in play yet to pay its cost with -- structurally forcing
     * the Charity/Idealism chain to happen first, unlike Animosity's own
     * unconditional boost in the originally-reported version of this
     * puzzle.
     */
    public function testChainReactionSelfLoathingCannotBePlayedFirst(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('chain-reaction');

        $this->expectException(IllegalPlayException::class);
        $this->play($gameId, $p, $this->instanceId($gameId, 75, 'hand'), []); // Self-Loathing with an empty board -- no mood to discard
    }

    /**
     * The tempting wrong line: Self-Loathing's own flat value (6) beats
     * Indifference's (4) outright. But paying its own discard cost sends
     * whichever of Charity/Idealism was chosen back out of play, shrinking
     * the solver down to just 2 moods -- fewer than the opponent's 3 --
     * which spikes Superiority from 3 to 7 (opponent totals 8), well past
     * whatever the solver ends up with (6 or 7 depending on which mood
     * was discarded), a decisive loss either way.
     */
    public function testChainReactionSelfLoathingLosesDespiteLookingTempting(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('chain-reaction');

        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity
        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []); // Idealism

        $idealismInPlayId = $this->instanceId($gameId, 16, 'in_play');
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 75, 'hand'), ['discard_mood_ids' => [$idealismInPlayId]]); // Self-Loathing, discarding Idealism

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    /**
     * Reported live: "For Color Chain, we need to swap Duplicity out for
     * a card that doesn't itself give an extra play, maybe Indifference".
     * Duplicity's own unconditional extra play used to make the last two
     * cards interchangeable once Benevolence's own color rule was
     * satisfied. With Indifference (no ability at all) in that slot,
     * exactly one of the six possible orders clears the hand: Idealism's
     * own unconditional grant has to come FIRST to cover the third play
     * at all, Benevolence second (its own conditional grant satisfied --
     * Indifference, not yet played, is the only thing left that could
     * still violate its "doesn't share a color" rule), and Indifference
     * last, since it has no grant of its own to spend on anything.
     */
    public function testColorChainSolvedByPlayingIdealismBeforeBenevolence(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('color-chain');

        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []); // Idealism (white) -- its own unconditional grant covers the third play
        $this->play($gameId, $p, $this->instanceId($gameId, 2, 'hand'), []); // Benevolence (white)
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []); // Indifference (blue) -- satisfies Benevolence's own grant

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testColorChainSameColorFollowUpIsIllegal(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('color-chain');

        $this->play($gameId, $p, $this->instanceId($gameId, 2, 'hand'), []); // Benevolence (white)

        $this->expectException(IllegalPlayException::class);
        $this->play($gameId, $p, $this->instanceId($gameId, 16, 'hand'), []); // Idealism (white) -- shares Benevolence's own color
    }

    /**
     * A second, more subtle wrong line the color rule alone doesn't
     * catch: Benevolence -> Indifference is perfectly legal (blue doesn't
     * share Benevolence's white), but Indifference has no grant of its
     * own to spend, so that's the last play available this turn --
     * Idealism, never played, leaves the hand non-empty.
     */
    public function testColorChainBenevolenceThenIndifferenceStallsWithIdealismStranded(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('color-chain');

        $this->play($gameId, $p, $this->instanceId($gameId, 2, 'hand'), []); // Benevolence (white)
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []); // Indifference (blue) -- legal, but grants nothing further

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    /**
     * Reported live: "For Vain Effort, let's take out Idealism and
     * replace it with both Friendliness and Ambition. Change the goal to
     * exactly 12 points." With only 4 cards and just 3 ways to earn an
     * extra play, playing all 4 outright is never possible in one turn --
     * the only way to also empty the hand (tripling Vanity's own value)
     * is to DISCARD Friendliness via Ambition's own "discard a card, then
     * you may play an additional mood" cost instead of ever playing it:
     * Charity(1) + Ambition(2) + Vanity(3 moods x 3, hand now empty) =
     * 1 + 2 + 9 = 12.
     */
    public function testVainEffortSolvedByDiscardingFriendlinessViaAmbition(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('vain-effort');

        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity
        $friendlinessId = $this->instanceId($gameId, 13, 'hand');
        $this->play($gameId, $p, $this->instanceId($gameId, 53, 'hand'), ['discard_card_id' => $friendlinessId]); // Ambition, discards Friendliness
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 79, 'hand'), []); // Vanity, hand now empty

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    /**
     * The tempting wrong line: playing Friendliness instead of sacrificing
     * it. Friendliness's own grant is satisfied by Ambition's even (2)
     * printed value, so Charity -> Friendliness -> Ambition is a legal
     * chain -- but declining Ambition's own discard (nothing worth
     * discarding is left except Vanity itself) strands Vanity in hand,
     * capping the total at Charity(1) + Friendliness(2) + Ambition(2) = 5,
     * far short of 12.
     */
    public function testVainEffortPlayingFriendlinessInsteadOfDiscardingItFallsShort(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('vain-effort');

        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity
        $this->play($gameId, $p, $this->instanceId($gameId, 13, 'hand'), []); // Friendliness
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 53, 'hand'), []); // Ambition, no discard

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    /**
     * Vanity has no after-playing effect of its own -- playing it first
     * (while hand isn't empty yet, worth only +1/mood) stalls the turn
     * immediately, matching the same "vanilla card can't open the chain"
     * lesson the other chaining puzzles already test.
     */
    public function testVainEffortPlayingVanityFirstStallsImmediately(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('vain-effort');

        $result = $this->play($gameId, $p, $this->instanceId($gameId, 79, 'hand'), []);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testWondersChoiceSolvedByChoosingTheMajorityColor(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('wonders-choice');

        // Complacency(4) and Idealism(0) are white and sit in the discard pile
        // (2 matches, since WonderEffect counts discard too); Indifference(4)
        // alone is in play and blue (1 match). Choosing white:
        // Indifference(4) + Wonder(0 + 2*2 = 4) = 8, exactly the target.
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 133, 'hand'), ['color' => 'white']);

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testWondersChoiceChoosingTheMinorityColorFallsShort(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('wonders-choice');

        // Choosing blue: Indifference(4) + Wonder(0 + 2*1 = 2) = 6, short of 8.
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 133, 'hand'), ['color' => 'blue']);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testEnviousTimingSolvedByEstablishingAMoodBeforePayingEnvysCost(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('envious-timing');

        $this->play($gameId, $p, $this->instanceId($gameId, 44, 'hand'), []); // Indifference, establishes a mood in play
        $indifferenceInPlayId = $this->instanceId($gameId, 44, 'in_play');
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 64, 'hand'), ['discard_mood_id' => $indifferenceInPlayId]);

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testEnviousTimingCannotBePlayedFirst(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('envious-timing');

        $this->expectException(IllegalPlayException::class);
        $this->play($gameId, $p, $this->instanceId($gameId, 64, 'hand'), []); // Envy with an empty board -- no mood to pay its cost with
    }

    public function testEnviousTimingExposesItsHintViaGetState(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('envious-timing');

        $state = $this->games->getState($gameId, $this->userIdForGamePlayer($p));

        self::assertSame(
            "Envy can only be played by moving one of your OWN moods already in play to the discard pile -- with an empty board, it can't be played at all yet.",
            $state['game']['puzzle_hint']
        );
    }

    public function testValidationLoopSolvedByChainingThroughTheLowValueCards(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('validation-loop');

        // Sadness and Vulnerability have no "after playing" ability of
        // their own -- unlike the original Idealism/Duplicity, neither
        // grants its own extra play, so the two extra plays this solve
        // needs can only come from Validation's own reactive grant.
        $this->play($gameId, $p, $this->instanceId($gameId, 74, 'hand'), []); // Sadness (0)
        $this->play($gameId, $p, $this->instanceId($gameId, 132, 'hand'), []); // Vulnerability (1)
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 83, 'hand'), []); // Boredom (4)

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testValidationLoopBoredomFirstDoesNotSolveItEfficiently(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('validation-loop');

        // Boredom (4) doesn't trigger Validation's reactive grant and has
        // no ability of its own -- playing it first stalls the turn.
        $this->play($gameId, $p, $this->instanceId($gameId, 83, 'hand'), []);
        $this->play($gameId, $p, $this->instanceId($gameId, 74, 'hand'), []);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 132, 'hand'), []);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testValidationLoopExposesItsHintViaGetState(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('validation-loop');

        $state = $this->games->getState($gameId, $this->userIdForGamePlayer($p));

        self::assertSame(
            'Validation quietly grants you another play every time you play a mood worth 0 or 1.',
            $state['game']['puzzle_hint']
        );
    }

    /**
     * Charity's own UNCONDITIONAL grant has to be spent first (on
     * Eagerness itself, since Eagerness's printed color, green, doesn't
     * match Charity's white), saving Eagerness's own CONDITIONAL grant
     * ("...if it shares a color with one of your moods") for the very
     * end: Laziness (green) is the only card left that can satisfy it
     * once Eagerness is in play.
     */
    public function testKindredColorsSolvedBySpendingCharitysGrantBeforeEagernessOwnConditionalOne(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('kindred-colors');

        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity (white)
        $this->play($gameId, $p, $this->instanceId($gameId, 114, 'hand'), []); // Eagerness (green)
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 126, 'hand'), []); // Laziness (green) -- satisfies Eagerness's own grant

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testKindredColorsDifferentColorFollowUpIsIllegal(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('kindred-colors');

        $this->play($gameId, $p, $this->instanceId($gameId, 114, 'hand'), []); // Eagerness (green)

        $this->expectException(IllegalPlayException::class);
        $this->play($gameId, $p, $this->instanceId($gameId, 3, 'hand'), []); // Charity (white) -- doesn't share Eagerness's own color
    }

    /**
     * Playing Eagerness first instead of last stalls one card short:
     * its own conditional grant is immediately spent on the only
     * qualifying card (Laziness, green), leaving Charity (white) with
     * no further grant to use it -- only 2 of the 3 plays needed.
     */
    public function testKindredColorsPlayingEagernessFirstStallsOneCardShort(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('kindred-colors');

        $this->play($gameId, $p, $this->instanceId($gameId, 114, 'hand'), []); // Eagerness (green)
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 126, 'hand'), []); // Laziness (green) -- satisfies the grant, but has no grant of its own

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testKindredColorsExposesItsHintViaGetState(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('kindred-colors');

        $state = $this->games->getState($gameId, $this->userIdForGamePlayer($p));

        self::assertSame(
            'Eagerness only lets you follow it with a mood that DOES share a color with something you have in play.',
            $state['game']['puzzle_hint']
        );
    }

    public function testTheLesserSacrificeSolvedByTargetingConvictionItself(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('the-lesser-sacrifice');

        $convictionId = $this->instanceId($gameId, 6, 'hand');
        $result = $this->play($gameId, $p, $convictionId, ['target_mood_id' => $convictionId]);

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    public function testTheLesserSacrificeTargetingAHigherValueMoodFallsShort(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('the-lesser-sacrifice');

        $boredomInPlayId = $this->instanceId($gameId, 83, 'in_play');
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 6, 'hand'), ['target_mood_id' => $boredomInPlayId]);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testTheLesserSacrificeExposesItsHintViaGetState(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('the-lesser-sacrifice');

        $state = $this->games->getState($gameId, $this->userIdForGamePlayer($p));

        self::assertSame(
            'Conviction has to send SOME mood to the bottom of the deck when you play it, including itself -- choose wisely.',
            $state['game']['puzzle_hint']
        );
    }

    /**
     * Reported live: puzzles should list Easy, Medium, Hard --
     * GameService::listActivePuzzles()'s own ORDER BY p.difficulty
     * relies on MySQL sorting the ENUM by declaration index. Asserted
     * against the real debut catalog (migration 0394 + this arc's own
     * redesigns) rather than a synthetic fixture, so a future puzzle
     * seeded with the wrong difficulty spelling would fail loudly here.
     */
    public function testListActivePuzzlesOrdersByDifficultyEasyMediumHard(): void
    {
        $userId = $this->insertUser('puzzle-list-order');

        $difficulties = array_column($this->games->listActivePuzzles($userId), 'difficulty');
        $rank = ['easy' => 0, 'medium' => 1, 'hard' => 2];
        $ranks = array_map(static fn (string $d) => $rank[$d], $difficulties);

        self::assertNotEmpty($ranks);
        self::assertSame($ranks, (function (array $r) {
            sort($r);

            return $r;
        })($ranks), 'Puzzles are not listed Easy, Medium, Hard: ' . implode(', ', $difficulties));
    }

    /**
     * Reported live: "Puzzle Solver" should only unlock on a solve where
     * the player never opened the Hint dialog -- end-to-end proof (see
     * AchievementServiceIntegrationTest for the achievement-only logic
     * in isolation) that checkPuzzleGoal() actually reads
     * games.puzzle_hint_viewed (set by markPuzzleHintViewed(), the same
     * method POST /games/puzzle-hint-viewed calls) and passes it through
     * correctly: solving "One Fell Swoop" (which has a hint) after
     * viewing it does NOT unlock the achievement, but the SAME user then
     * solving "The Lesser Sacrifice" without ever viewing IT hint does.
     */
    public function testSolvingAPuzzleAfterViewingItsHintDoesNotUnlockPuzzleSolverButALaterHintlessSolveDoes(): void
    {
        $userId = $this->insertUser('hint-gated-solver');

        ['gameId' => $gameId1, 'gamePlayerId' => $p1] = $this->attemptAs($userId, 'one-fell-swoop');
        $this->games->markPuzzleHintViewed($gameId1, $p1);
        $this->play($gameId1, $p1, $this->instanceId($gameId1, 13, 'hand'), []); // Friendliness
        $this->play($gameId1, $p1, $this->instanceId($gameId1, 17, 'hand'), []); // Kindness
        $this->play($gameId1, $p1, $this->instanceId($gameId1, 3, 'hand'), []); // Charity
        $result1 = $this->play($gameId1, $p1, $this->instanceId($gameId1, 53, 'hand'), []); // Ambition, no discard
        self::assertTrue($result1['game_completed']);
        self::assertFalse($this->isAchievementUnlocked($userId, 'puzzle-solver'), 'Viewing the hint should have blocked this solve from unlocking Puzzle Solver');

        ['gameId' => $gameId2, 'gamePlayerId' => $p2] = $this->attemptAs($userId, 'the-lesser-sacrifice');
        $convictionId = $this->instanceId($gameId2, 6, 'hand');
        $result2 = $this->play($gameId2, $p2, $convictionId, ['target_mood_id' => $convictionId]);
        self::assertTrue($result2['game_completed']);
        self::assertTrue($this->isAchievementUnlocked($userId, 'puzzle-solver'), 'A later hintless solve should still unlock Puzzle Solver');
    }

    /**
     * "Perfect Disguise" (issue #524 follow-up, redesigned live after
     * playtesting: the original three-play version needed a mid-attempt
     * turn refresh the UI gave no visible cue for). Joy(125) now starts
     * already in play with its own extra play pre-banked, so the whole
     * solution is exactly two plays in the SAME turn: Bliss(108) alone
     * (keyed green via the Eagerness(114) discard) only reaches 15 --
     * still short of the opponent's fixed 16 -- then Creativity(32)
     * copying the already-in-play Joy adds a THIRD green mood, and
     * Bliss's own bonus applies to all three, for 24. See migration
     * 0413's own docblock for the full arithmetic.
     */
    public function testPerfectDisguiseSolvedByCopyingJoyUnderAGreenKeyedBliss(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('perfect-disguise');

        $this->play($gameId, $p, $this->instanceId($gameId, 108, 'hand'), [
            'discard_card_id' => $this->instanceId($gameId, 114, 'hand'), // Eagerness (green)
        ]);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 32, 'hand'), [
            'copy_card_id' => $this->instanceId($gameId, 125, 'in_play'), // copy the already-in-play Joy
        ]);

        self::assertTrue($result['game_completed']);
        $this->assertGameSolved($gameId, $p);
    }

    /**
     * The tempting wrong line: discarding Indifference (blue) to Bliss's
     * cost instead, on the theory that Creativity's own printed blue
     * needs a blue-keyed Bliss to benefit. Nothing is ever actually blue
     * in play (Creativity becomes green the instant it copies Joy), so
     * the bonus is always 0 -- final total 8, never enough to clear the
     * opponent's 16, and both real plays (and the attempt's max_plays=2
     * budget) are already spent within this same turn.
     */
    public function testPerfectDisguiseDiscardingIndifferenceToBlissInsteadOfEagernessFallsShort(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('perfect-disguise');

        $this->play($gameId, $p, $this->instanceId($gameId, 108, 'hand'), [
            'discard_card_id' => $this->instanceId($gameId, 44, 'hand'), // Indifference (blue) -- the trap
        ]);
        $result = $this->play($gameId, $p, $this->instanceId($gameId, 32, 'hand'), [
            'copy_card_id' => $this->instanceId($gameId, 125, 'in_play'),
        ]);

        self::assertFalse($result['game_completed']);
        $this->assertGameNotSolved($gameId);
    }

    public function testPerfectDisguiseExposesItsHintViaGetState(): void
    {
        ['gameId' => $gameId, 'gamePlayerId' => $p] = $this->attempt('perfect-disguise');

        $state = $this->games->getState($gameId, $this->userIdForGamePlayer($p));

        self::assertSame(
            "Once Creativity copies another mood, it takes on that mood's own color -- not its own printed blue -- for anything that cares about color.",
            $state['game']['puzzle_hint']
        );
    }

    private function isAchievementUnlocked(int $userId, string $slug): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT ua.unlocked_at FROM user_achievements ua JOIN achievements a ON a.id = ua.achievement_id
             WHERE ua.user_id = :u AND a.slug = :slug'
        );
        $stmt->execute(['u' => $userId, 'slug' => $slug]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null;
    }
}
