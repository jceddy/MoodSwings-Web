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
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #359: practice bots seated in a draft game. Exercises
 * GameService::advanceBotDraftTurn() (and createGame()'s own now-relaxed
 * bot-seating validation for draft formats) end to end, against a real
 * database -- MoodSwings\Tests\Bot\BotPlayerServiceTest already covers the
 * draft-pick SCORING policy itself in isolation, so these focus on the
 * request-lifecycle wiring: does a bot's own pick/action/deck submission
 * actually get driven for each of the 5 draft deck_types, does it stop at
 * a real player's own turn, and does the game auto-start once every seat
 * (bot or human) has a deck in.
 */
final class BotDraftGameplayIntegrationTest extends TestCase
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
        $pdo->exec('TRUNCATE TABLE draft_pile_stage_picks');
        $pdo->exec('TRUNCATE TABLE draft_round_picks');
        $pdo->exec('TRUNCATE TABLE draft_winston_state');
        $pdo->exec('TRUNCATE TABLE draft_grid_state');
        $pdo->exec('TRUNCATE TABLE draft_rotisserie_state');
        $pdo->exec('TRUNCATE TABLE draft_tiered_rotisserie_state');
        $pdo->exec('TRUNCATE TABLE draft_match_players');
        $pdo->exec('TRUNCATE TABLE draft_matches');
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

    private function fetchGame(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM games WHERE id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetch();
    }

    /**
     * Rotisserie Draft (issue #359) exercises the "single current turn"
     * shape (draft_rotisserie_state.current_turn_user_id) shared by
     * Winston/Grid/Tiered Rotisserie Draft too -- driven end to end here:
     * a human submits an arbitrary legal pick each time it's their turn,
     * advanceAutomatedTurns() (called the same way index.php calls it
     * after every draft route) handles every bot turn in between,
     * including several bot picks in a row where the snake order calls
     * for it, all the way through the whole match. Once drafting itself
     * finishes, the SAME loop also has to submit the bot's own deck
     * (advanceBotDraftDeck()) and, once the human submits theirs too,
     * start the game outright (tryAutoStartDraftGame()) -- nothing here
     * ever calls startGame() directly.
     */
    public function testRotisserieDraftBotCompletesAWholeDraftAndAutoStartsTheGame(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'rotisserie_draft',
            rotisserieDraftPoolSource: 'random_48',
            rotisserieDraftCutoffCount: 13,
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        for ($i = 0; $i < 60; $i++) {
            $stateStmt = $this->pdo->prepare('SELECT * FROM draft_rotisserie_state WHERE draft_match_id = :id');
            $stateStmt->execute(['id' => $draftMatchId]);
            $state = $stateStmt->fetch();

            if ($state === false) {
                break; // drafting itself has finished -- only deck submission/game start left, both bot-driven below
            }

            if ((int) $state['current_turn_user_id'] === $human) {
                $pool = array_map(intval(...), json_decode((string) $state['pool_card_ids'], true));
                $this->games->submitRotisserieDraftPick($gameId, $human, $pool[0]);
            }

            $this->games->advanceAutomatedTurns($gameId);

            if ($this->fetchGame($gameId)['status'] !== 'waiting') {
                break; // the bot's own deck submission already auto-started the game
            }
        }

        $draftedStmt = $this->pdo->prepare('SELECT drafted_card_ids, deck_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $draftedStmt->execute(['id' => $draftMatchId, 'user_id' => $bot]);
        $botRow = $draftedStmt->fetch();
        self::assertCount(13, json_decode((string) $botRow['drafted_card_ids'], true));
        self::assertNotNull($botRow['deck_card_ids'], "The bot's own deck should have been submitted automatically once drafting finished");

        // The human still needs to submit their own deck -- the game can't
        // have auto-started on the bot's deck submission alone.
        self::assertSame('waiting', $this->fetchGame($gameId)['status']);

        $humanDraftedStmt = $this->pdo->prepare('SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $humanDraftedStmt->execute(['id' => $draftMatchId, 'user_id' => $human]);
        $humanDrafted = array_map(intval(...), json_decode((string) $humanDraftedStmt->fetchColumn(), true));

        $this->games->submitDraftDeck($gameId, $human, $humanDrafted);
        $this->games->advanceAutomatedTurns($gameId);

        self::assertSame('in_progress', $this->fetchGame($gameId)['status']);
    }

    /**
     * Open Team Play (confirmed by the maintainer): a bot whose own
     * teammate is a human should wait for that human to submit their own
     * deck first, rather than always beating them to it the instant
     * drafting ends -- see GameService::awaitingHumanTeammatesDraftDeck()'s
     * own docblock for why submission order matters here. Team 1 pairs
     * the human with a bot (partnerUserId); team 2 is two MORE bots
     * (bot2/bot3), included specifically to prove this gating is about a
     * HUMAN teammate, not "any teammate" -- bot2/bot3 submit their own
     * decks immediately once drafting ends, with no human anywhere on
     * their own team to wait for, while the human's own partner keeps
     * its deck_card_ids NULL until the human submits theirs.
     *
     * A homogeneous custom pool (Charity only, not 'random_48') is used
     * deliberately -- the final assertion below needs the game to
     * actually reach 'in_progress' (proving the bot's own deferred
     * submission genuinely unblocks, not just that deck_card_ids got
     * set), and advanceAutomatedTurns() keeps driving real automated
     * turns for the game's 3 seated bots in that same call once it
     * starts. A random pool risks drafting a card whose own pending-
     * decision handling isn't otherwise exercised by 3 bots playing
     * unattended for many turns in a row -- an unrelated concern this
     * test has no interest in; a single simple, heavily-tested card
     * keeps the whole match deterministic and safe.
     */
    public function testBotWaitsForItsHumanTeammateToSubmitADeckFirstInOpenTeamPlay(): void
    {
        $human = $this->insertUser('human2');
        $bot = $this->insertBotUser('bot4');
        $bot2 = $this->insertBotUser('bot5');
        $bot3 = $this->insertBotUser('bot6');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot, $bot2, $bot3],
            format: 'team',
            deckType: 'rotisserie_draft',
            partnerUserId: $bot,
            rotisserieDraftPoolSource: 'custom',
            rotisserieDraftCustomPoolText: "100 Charity\n",
            rotisserieDraftCutoffCount: 13,
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        for ($i = 0; $i < 100; $i++) {
            $stateStmt = $this->pdo->prepare('SELECT * FROM draft_rotisserie_state WHERE draft_match_id = :id');
            $stateStmt->execute(['id' => $draftMatchId]);
            $state = $stateStmt->fetch();

            if ($state === false) {
                break; // drafting itself has finished -- only deck submission/game start left
            }

            if ((int) $state['current_turn_user_id'] === $human) {
                $pool = array_map(intval(...), json_decode((string) $state['pool_card_ids'], true));
                $this->games->submitRotisserieDraftPick($gameId, $human, $pool[0]);
            }

            $this->games->advanceAutomatedTurns($gameId);
        }

        $deckCardIdsFor = function (int $userId) use ($draftMatchId): ?string {
            $stmt = $this->pdo->prepare('SELECT deck_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
            $stmt->execute(['id' => $draftMatchId, 'user_id' => $userId]);

            return $stmt->fetchColumn() ?: null;
        };

        self::assertNotNull($deckCardIdsFor($bot2), "bot2's own deck should be submitted immediately -- its own teammate (bot3) isn't human");
        self::assertNotNull($deckCardIdsFor($bot3), "bot3's own deck should be submitted immediately -- its own teammate (bot2) isn't human");
        self::assertNull($deckCardIdsFor($bot), "the human's own bot partner should still be waiting -- the human hasn't submitted a deck yet");
        self::assertSame('waiting', $this->fetchGame($gameId)['status']);

        $humanDraftedStmt = $this->pdo->prepare('SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $humanDraftedStmt->execute(['id' => $draftMatchId, 'user_id' => $human]);
        $humanDrafted = array_map(intval(...), json_decode((string) $humanDraftedStmt->fetchColumn(), true));

        $this->games->submitDraftDeck($gameId, $human, $humanDrafted);
        $this->games->advanceAutomatedTurns($gameId);

        self::assertNotNull($deckCardIdsFor($bot), "the bot partner should submit its own deck now that its human teammate has submitted theirs");
        self::assertSame('in_progress', $this->fetchGame($gameId)['status']);
    }

    /**
     * End-to-end coverage of GameService::pickableDraftPoolFor() (confirmed
     * by the maintainer, a real bug caught live from a user report) through
     * advanceBotDraftDeck() -- Open Team Play's shared draft pool means a
     * bot's own RAW drafted_card_ids alone is no longer a safe basis for
     * its own deck: a card the bot itself drafted can end up unavailable
     * if its human teammate's own already-submitted deck claimed it first
     * (first-come-first-served -- see submitDraftDeck()'s own docblock).
     * Before this fix, advanceBotDraftDeck() built the bot's deck from its
     * own drafted_card_ids alone, with nothing anywhere catching the
     * GameStateException submitDraftDeck() throws when that guess turns
     * out to be wrong -- silently and PERMANENTLY stalling the bot's own
     * submission, since chooseDraftDeck() deterministically repeats the
     * exact same losing choice every time this runs.
     *
     * Bypasses the actual pick-by-pick draft entirely (already covered by
     * the tests above) and hand-crafts drafted_card_ids directly, so the
     * numbers below are exact regardless of any draft_priority_score
     * internals: the human's own 13 are mostly Dignity (id 8, one
     * Pacifism, id 20), the bot's own 13 are the mirror image (mostly
     * Pacifism, one Dignity) -- a 13/13 combined team split either way.
     * The human then submits a deck of mostly PACIFISM (using the team's
     * shared pool, not just their own personally-drafted cards) -- 12 of
     * the team's 13 total Pacifism, leaving only 1 actually pickable
     * afterward. The bot's own RAW pool is mostly Pacifism (12 of its own
     * 13), so an unfixed bot's naive top-12 trim asks for far more
     * Pacifism than the single copy actually still available once its
     * teammate's deck is accounted for.
     */
    public function testBotAvoidsCardsItsHumanTeammateAlreadyClaimedWhenSubmittingItsOwnDraftDeck(): void
    {
        $human = $this->insertUser('human4');
        $bot = $this->insertBotUser('bot7');
        $bot2 = $this->insertBotUser('bot8');
        $bot3 = $this->insertBotUser('bot9');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot, $bot2, $bot3],
            format: 'team',
            deckType: 'rotisserie_draft',
            partnerUserId: $bot,
            rotisserieDraftPoolSource: 'random_48',
            rotisserieDraftCutoffCount: 13,
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $this->pdo->prepare("UPDATE draft_matches SET status = 'deck_building' WHERE id = :id")->execute(['id' => $draftMatchId]);

        $setDrafted = function (int $userId, array $cardIds) use ($draftMatchId): void {
            $this->pdo->prepare(
                'UPDATE draft_match_players SET drafted_card_ids = :ids, deck_card_ids = NULL WHERE draft_match_id = :match_id AND user_id = :user_id'
            )->execute(['ids' => json_encode($cardIds), 'match_id' => $draftMatchId, 'user_id' => $userId]);
        };

        // Dignity (id 8), Pacifism (id 20) -- both simple white commons,
        // chosen only for their catalog ids here, never actually played.
        $setDrafted($human, array_merge(array_fill(0, 12, 8), [20]));
        $setDrafted($bot, array_merge(array_fill(0, 12, 20), [8]));
        // Team 2 -- no human teammate, unrelated to this test.
        $setDrafted($bot2, array_fill(0, 12, 8));
        $setDrafted($bot3, array_fill(0, 12, 8));

        $this->games->submitDraftDeck($gameId, $human, array_merge(array_fill(0, 12, 20), [8]));

        // Before the fix, this call itself throws (see this test's own
        // docblock) -- the bot's own naive deck choice collides with what
        // its human teammate's deck already claimed, and nothing catches
        // the resulting GameStateException.
        $this->games->advanceAutomatedTurns($gameId);

        $botRow = $this->pdo->prepare('SELECT deck_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $botRow->execute(['id' => $draftMatchId, 'user_id' => $bot]);
        $botDeck = json_decode((string) $botRow->fetchColumn(), true);

        self::assertNotNull($botDeck, "the bot's own deck should have been submitted, not silently stalled forever");
        self::assertGreaterThanOrEqual(12, count($botDeck));

        $pacifismCount = count(array_filter($botDeck, fn (int $id) => $id === 20));
        self::assertLessThanOrEqual(1, $pacifismCount, "the bot should never submit more Pacifism than was actually still available once its human teammate's deck claimed the rest");
    }

    /**
     * Quick Draft's own simultaneous-per-stage picking (submitQuickDraftPick())
     * has no single "current turn" at all -- every seated player picks
     * independently once a stage opens, and a stage can't advance until
     * EVERY seat's own pick for it is in. This is the one format where
     * advanceBotQuickDraftPick() has to figure out the active stage and
     * loop through every bot seat itself, rather than just checking one
     * current_turn_user_id column -- proven here directly: with 1 human +
     * 3 bots, a single advanceAutomatedTurns() call (with the human not
     * having picked yet) should resolve all 3 bots' own stage-1 picks at
     * once and stop there, still waiting on the human -- not advance the
     * round, and not touch the human's own pick.
     */
    public function testQuickDraftBotsPickSimultaneouslyWithoutWaitingForTurnOrder(): void
    {
        $human = $this->insertUser('human1');
        $bot1 = $this->insertBotUser('bot1');
        $bot2 = $this->insertBotUser('bot2');
        $bot3 = $this->insertBotUser('bot3');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot1, $bot2, $bot3],
            format: 'draft',
            deckType: 'quick_draft',
            quickDraftPoolSource: 'random_48',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $this->games->advanceAutomatedTurns($gameId);

        $stmt = $this->pdo->prepare(
            'SELECT pile_owner_user_id FROM draft_pile_stage_picks WHERE draft_match_id = :id AND round_number = 1 AND stage_number = 1'
        );
        $stmt->execute(['id' => $draftMatchId]);
        $pileOwnersWithAPick = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));

        self::assertCount(3, $pileOwnersWithAPick, 'exactly the 3 bot seats should have picked stage 1 automatically');

        // Confirmed by elimination: every bot picked, the human didn't,
        // and the round is still on stage 1 (a human pick is the only
        // thing that could complete it, with 4 seated players).
        $matchStmt = $this->pdo->prepare('SELECT status FROM draft_matches WHERE id = :id');
        $matchStmt->execute(['id' => $draftMatchId]);
        self::assertSame('drafting', $matchStmt->fetchColumn());
    }

    /** Winston Draft's own single-current-turn take/pass, driven automatically whenever it lands on a bot -- proven for whichever of the two players the random starter_seat_offset actually picked. */
    public function testWinstonDraftBotActsAutomaticallyWhenItsTurn(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'winston_draft',
            winstonDraftPoolSource: 'random_48',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $stateStmt = $this->pdo->prepare('SELECT current_player_user_id FROM draft_winston_state WHERE draft_match_id = :id');
        $stateStmt->execute(['id' => $draftMatchId]);
        $startingPlayerUserId = (int) $stateStmt->fetchColumn();

        $result = $this->games->advanceAutomatedTurns($gameId);

        if ($startingPlayerUserId === $bot) {
            self::assertNotNull($result, "the bot's own opening take/pass should have been driven automatically");
        } else {
            self::assertNull($result, "nothing should act while it's the human's own turn");
        }
    }

    /**
     * A real bug reported live: with the shared deck already empty,
     * declining pile 3 gets nothing back (submitWinstonDraftPick()'s own
     * "mandatory" deck draw only fires `if ($deck !== [])`) -- so if this
     * is also the bot's last remaining pile this turn (pile_1/pile_2
     * already empty themselves, having been taken earlier while the deck
     * was already dry), a bot that just follows chooseWinstonAction()'s
     * own plain "is this pile good enough" scoring could pass on its
     * only real chance and end the turn with literally zero cards.
     * Altruism (id 1, draft_priority_score 1) sits well below the
     * catalog's own average, so chooseWinstonAction() would naturally
     * say 'pass' here on its own -- advanceBotWinstonDraftPick()'s own
     * winstonDraftPassWouldForfeitTheWholeTurn() guard is what forces
     * 'take' instead.
     */
    public function testWinstonDraftBotTakesItsLastChanceRatherThanForfeitingTheWholeTurn(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'winston_draft',
            winstonDraftPoolSource: 'random_48',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $this->pdo->prepare(
            "UPDATE draft_winston_state SET current_player_user_id = :bot, current_pile_number = 3,
                remaining_deck_card_ids = '[]', pile_1_card_ids = '[]', pile_2_card_ids = '[]', pile_3_card_ids = '[1]'
             WHERE draft_match_id = :match_id"
        )->execute(['bot' => $bot, 'match_id' => $draftMatchId]);

        $this->games->advanceAutomatedTurns($gameId);

        $draftedStmt = $this->pdo->prepare('SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $draftedStmt->execute(['id' => $draftMatchId, 'user_id' => $bot]);
        $botDrafted = array_map(intval(...), json_decode((string) $draftedStmt->fetchColumn(), true));

        self::assertContains(1, $botDrafted, "the bot should have taken pile 3 rather than forfeiting its only remaining chance this turn");
    }

    /**
     * Issue #359 exposed a pre-existing gap: recordMatchCompletionStats()
     * (lifetime match_wins/match_losses, issue #106) had no containsBot
     * check of its own -- harmless before this issue, since a draft
     * match could never have a bot seated at all, but now that one can,
     * an unguarded version would leave a stray user_lifetime_stats row
     * behind for the bot's own user id every time a bot-seated draft
     * match completed. Fixed alongside the rest of this feature to match
     * recordGameCompletionStats()'s own identical exclusion. Triggered
     * here via Winston Draft's own under-WINSTON_MIN_DECK_SIZE(12)
     * auto-loss finish (finalizeWinstonDraft()) -- completes the whole
     * MATCH the instant the draft itself ends, with no actual round of
     * the underlying card game needing to be played out first, unlike
     * every other way a draft match can complete.
     */
    public function testBotSeededDraftMatchCompletionDoesNotRecordLifetimeMatchStatsForEitherPlayer(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'winston_draft',
            winstonDraftPoolSource: 'random_48',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        // Human already has a full (well above the floor) drafted pool;
        // the bot has deliberately been left short of WINSTON_MIN_DECK_SIZE
        // (12) -- finalizeWinstonDraft() will drop it as a short player
        // and hand the human an outright, no-games-played match win the
        // instant the draft itself finishes below.
        $humanCardIds = range(1, 14);
        $botCardIds = [1, 2, 3];
        $this->pdo->prepare('UPDATE draft_match_players SET drafted_card_ids = :ids WHERE draft_match_id = :match_id AND user_id = :user_id')
            ->execute(['ids' => json_encode($humanCardIds), 'match_id' => $draftMatchId, 'user_id' => $human]);
        $this->pdo->prepare('UPDATE draft_match_players SET drafted_card_ids = :ids WHERE draft_match_id = :match_id AND user_id = :user_id')
            ->execute(['ids' => json_encode($botCardIds), 'match_id' => $draftMatchId, 'user_id' => $bot]);

        // One final human pick that empties the shared deck and every
        // pile simultaneously -- the exact condition submitWinstonDraftPick()
        // itself checks to end the draft outright.
        $this->pdo->prepare(
            "UPDATE draft_winston_state SET current_player_user_id = :human, current_pile_number = 1,
                remaining_deck_card_ids = '[]', pile_1_card_ids = '[15]', pile_2_card_ids = '[]', pile_3_card_ids = '[]'
             WHERE draft_match_id = :match_id"
        )->execute(['human' => $human, 'match_id' => $draftMatchId]);

        $this->games->submitWinstonDraftPick($gameId, $human, 'take');

        $matchStmt = $this->pdo->prepare('SELECT status, winner_user_id FROM draft_matches WHERE id = :id');
        $matchStmt->execute(['id' => $draftMatchId]);
        $match = $matchStmt->fetch();
        self::assertSame('completed', $match['status']);
        self::assertSame($human, (int) $match['winner_user_id']);

        $statsStmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_lifetime_stats WHERE user_id IN (:human, :bot)');
        $statsStmt->execute(['human' => $human, 'bot' => $bot]);
        self::assertSame(0, (int) $statsStmt->fetchColumn(), 'a bot-seated draft match should leave no lifetime match stats behind for either player');
    }

    /** Grid Draft's own single-current-turn row/column pick, driven automatically whenever it lands on a bot. */
    public function testGridDraftBotActsAutomaticallyWhenItsTurn(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'grid_draft',
            gridDraftPoolSource: 'random_48',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $stateStmt = $this->pdo->prepare('SELECT current_turn_user_id FROM draft_grid_state WHERE draft_match_id = :id');
        $stateStmt->execute(['id' => $draftMatchId]);
        $startingPlayerUserId = (int) $stateStmt->fetchColumn();

        $result = $this->games->advanceAutomatedTurns($gameId);

        if ($startingPlayerUserId === $bot) {
            self::assertNotNull($result, "the bot's own opening pick should have been driven automatically");
        } else {
            self::assertNull($result, "nothing should act while it's the human's own turn");
        }
    }

    /**
     * Tiered Rotisserie Draft's own single-current-turn pick, same shape
     * as base Rotisserie Draft -- checked specifically for the
     * current-tier scoping ('rarity' mode's first tier is always
     * 'mythic'): whichever card a bot picks first has to come from that
     * tier's own pool, never any other rarity's.
     */
    public function testTieredRotisserieDraftBotOnlyPicksFromTheCurrentTier(): void
    {
        $human = $this->insertUser('human1');
        $bot = $this->insertBotUser('bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'tiered_rotisserie_draft',
            tieredRotisserieDraftMode: 'rarity',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $stateStmt = $this->pdo->prepare('SELECT current_turn_user_id FROM draft_tiered_rotisserie_state WHERE draft_match_id = :id');
        $stateStmt->execute(['id' => $draftMatchId]);
        $startingPlayerUserId = (int) $stateStmt->fetchColumn();

        if ($startingPlayerUserId !== $bot) {
            self::markTestSkipped('the random starter this run was the human, not the bot -- nothing to drive yet');
        }

        $this->games->advanceAutomatedTurns($gameId);

        $draftedStmt = $this->pdo->prepare('SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $draftedStmt->execute(['id' => $draftMatchId, 'user_id' => $bot]);
        $draftedCardIds = array_map(intval(...), json_decode((string) $draftedStmt->fetchColumn(), true));
        self::assertCount(1, $draftedCardIds);

        $rarityStmt = $this->pdo->prepare('SELECT rarity FROM cards WHERE id = :id');
        $rarityStmt->execute(['id' => $draftedCardIds[0]]);
        self::assertSame('mythic', $rarityStmt->fetchColumn());
    }

    // -- Bot games excluded from global card statistics (confirmed by the maintainer) --

    /**
     * Issue #315's own live per-pick card-stats signal
     * (recordQuickDraftPick() et al., see submitQuickDraftPick()'s own
     * docblock) is a completely separate write path from
     * recordGameCompletionStats()'s own $containsBot guard -- it fires
     * from the pick itself, well before a game (let alone a whole match)
     * ever completes, so it needed (and, before this fix, was missing)
     * its own bot check. A match with a bot seated anywhere in it should
     * never feed the global card-stats pick-position signal at all,
     * matching the same "a bot game doesn't count" policy already
     * applied to lifetime/deck-win-rate stats.
     */
    public function testQuickDraftPicksAgainstABotAreNotRecordedInCardStats(): void
    {
        $human = $this->insertUser('human4');
        $bot1 = $this->insertBotUser('bot7');
        $bot2 = $this->insertBotUser('bot8');
        $bot3 = $this->insertBotUser('bot9');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot1, $bot2, $bot3],
            format: 'draft',
            deckType: 'quick_draft',
            quickDraftPoolSource: 'random_48',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $this->games->advanceAutomatedTurns($gameId); // the 3 bot seats each pick stage 1 automatically

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM draft_pile_stage_picks WHERE draft_match_id = :id AND round_number = 1 AND stage_number = 1'
        );
        $stmt->execute(['id' => $draftMatchId]);
        self::assertSame(3, (int) $stmt->fetchColumn(), 'the 3 bot picks should have genuinely happened');

        $totalPicks = (int) $this->pdo->query('SELECT SUM(quick_draft_pick_position_count) FROM card_stats')->fetchColumn();
        self::assertSame(0, $totalPicks, 'none of those bot picks should have been recorded into the global card-stats signal');
    }

    public function testWinstonDraftPicksAgainstABotAreNotRecordedInCardStats(): void
    {
        $human = $this->insertUser('human5');
        $bot = $this->insertBotUser('bot10');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'winston_draft',
            winstonDraftPoolSource: 'random_48',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $this->pdo->prepare('UPDATE draft_winston_state SET current_player_user_id = :bot WHERE draft_match_id = :match_id')
            ->execute(['bot' => $bot, 'match_id' => $draftMatchId]);

        $this->games->advanceAutomatedTurns($gameId);

        $draftedStmt = $this->pdo->prepare('SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $draftedStmt->execute(['id' => $draftMatchId, 'user_id' => $bot]);
        self::assertNotSame('[]', $draftedStmt->fetchColumn(), "the bot's own pick should have genuinely happened");

        $totalPicks = (int) $this->pdo->query('SELECT SUM(winston_draft_pick_pile_size_count) FROM card_stats')->fetchColumn();
        self::assertSame(0, $totalPicks, 'the bot pick should not have been recorded into the global card-stats signal');
    }

    public function testGridDraftPicksAgainstABotAreNotRecordedInCardStats(): void
    {
        $human = $this->insertUser('human6');
        $bot = $this->insertBotUser('bot11');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'grid_draft',
            gridDraftPoolSource: 'random_48',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];
        $this->pdo->prepare('UPDATE draft_grid_state SET current_turn_user_id = :bot WHERE draft_match_id = :match_id')
            ->execute(['bot' => $bot, 'match_id' => $draftMatchId]);

        $this->games->advanceAutomatedTurns($gameId);

        $draftedStmt = $this->pdo->prepare('SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $draftedStmt->execute(['id' => $draftMatchId, 'user_id' => $bot]);
        self::assertNotSame('[]', $draftedStmt->fetchColumn(), "the bot's own pick should have genuinely happened");

        $totalPicks = (int) $this->pdo->query('SELECT SUM(grid_draft_pick_round_count) FROM card_stats')->fetchColumn();
        self::assertSame(0, $totalPicks, 'the bot pick should not have been recorded into the global card-stats signal');
    }

    public function testRotisserieDraftPicksAgainstABotAreNotRecordedInCardStats(): void
    {
        $human = $this->insertUser('human7');
        $bot = $this->insertBotUser('bot12');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'rotisserie_draft',
            rotisserieDraftPoolSource: 'random_48',
            rotisserieDraftCutoffCount: 13,
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        for ($i = 0; $i < 26; $i++) {
            $stateStmt = $this->pdo->prepare('SELECT * FROM draft_rotisserie_state WHERE draft_match_id = :id');
            $stateStmt->execute(['id' => $draftMatchId]);
            $state = $stateStmt->fetch();
            if ($state === false) {
                break;
            }
            if ((int) $state['current_turn_user_id'] === $human) {
                $pool = array_map(intval(...), json_decode((string) $state['pool_card_ids'], true));
                $this->games->submitRotisserieDraftPick($gameId, $human, $pool[0]);
            }
            $this->games->advanceAutomatedTurns($gameId);
        }

        $draftedStmt = $this->pdo->prepare('SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $draftedStmt->execute(['id' => $draftMatchId, 'user_id' => $human]);
        self::assertCount(13, json_decode((string) $draftedStmt->fetchColumn(), true), "the human's own picks should have genuinely happened too");

        $totalPicks = (int) $this->pdo->query('SELECT SUM(rotisserie_draft_pick_position_count) FROM card_stats')->fetchColumn();
        self::assertSame(0, $totalPicks, 'none of the 26 picks -- bot or human -- should have been recorded, since a bot is seated in this match');
    }

    /**
     * Sealed Deck (issue #392) has no live drafting phase at all, so
     * there's no equivalent of the other 5 draft deck_types' own
     * advanceBotXDraftPick() loop -- a bot seated here goes straight to
     * advanceBotDraftDeck() the very first time advanceAutomatedTurns()
     * runs, since createGame() itself already dealt its own 45-card pool
     * and moved the match to 'deck_building' immediately (see
     * GameService::initializeSealedDeck()). Once the human submits their
     * own deck too, the same loop starts the game outright, exactly as it
     * does for every other draft deck_type.
     */
    public function testSealedDeckBotSubmitsItsOwnDeckAutomaticallyAndAutoStartsTheGame(): void
    {
        $human = $this->insertUser('sealeddeck-human1');
        $bot = $this->insertBotUser('sealeddeck-bot1');

        $gameId = $this->games->createGame(
            $human,
            [$human, $bot],
            format: 'draft',
            deckType: 'sealed_deck',
        );

        $draftMatchId = (int) $this->fetchGame($gameId)['draft_match_id'];

        $this->games->advanceAutomatedTurns($gameId);

        $botDeckStmt = $this->pdo->prepare('SELECT deck_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $botDeckStmt->execute(['id' => $draftMatchId, 'user_id' => $bot]);
        $botDeckCardIds = json_decode((string) $botDeckStmt->fetchColumn(), true);
        self::assertNotNull($botDeckCardIds, "the bot should have submitted its own sealed deck without any human action");
        self::assertGreaterThanOrEqual(12, count($botDeckCardIds));

        self::assertSame('waiting', $this->fetchGame($gameId)['status'], 'the game should not auto-start until the human submits their own deck too');

        $humanPoolStmt = $this->pdo->prepare('SELECT drafted_card_ids FROM draft_match_players WHERE draft_match_id = :id AND user_id = :user_id');
        $humanPoolStmt->execute(['id' => $draftMatchId, 'user_id' => $human]);
        $humanPool = array_map(intval(...), json_decode((string) $humanPoolStmt->fetchColumn(), true));
        $this->games->submitDraftDeck($gameId, $human, array_slice($humanPool, 0, 12));
        $this->games->advanceAutomatedTurns($gameId);

        self::assertSame('in_progress', $this->fetchGame($gameId)['status'], 'the game should auto-start once both the human and the bot have a deck in');
    }
}
