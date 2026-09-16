<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Tournament;

use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\GameService;
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\TournamentMatchRepository;
use MoodSwings\Repository\TournamentParticipantRepository;
use MoodSwings\Repository\TournamentPodRepository;
use MoodSwings\Repository\TournamentRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use MoodSwings\Tournament\BoosterDraftPodBuilder;
use MoodSwings\Tournament\BoosterPackBuilder;
use MoodSwings\Tournament\NotAuthorizedForTournamentException;
use MoodSwings\Tournament\TournamentBracketBuilder;
use MoodSwings\Tournament\TournamentService;
use MoodSwings\Tournament\TournamentStateException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class TournamentServiceIntegrationTest extends TestCase
{
    private PDO $pdo;
    private GameService $games;
    private TournamentService $tournaments;
    private TournamentMatchRepository $matchRepo;

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
        $pdo->exec('TRUNCATE TABLE tournament_matches');
        $pdo->exec('TRUNCATE TABLE tournament_rounds');
        $pdo->exec('TRUNCATE TABLE tournament_pod_picks');
        $pdo->exec('TRUNCATE TABLE tournament_pod_boosters');
        $pdo->exec('TRUNCATE TABLE tournament_pod_participants');
        $pdo->exec('TRUNCATE TABLE tournament_pods');
        $pdo->exec('TRUNCATE TABLE tournament_participants');
        $pdo->exec('TRUNCATE TABLE tournaments');
        $pdo->exec('TRUNCATE TABLE game_matches');
        $pdo->exec('TRUNCATE TABLE game_events');
        $pdo->exec('TRUNCATE TABLE game_notes');
        $pdo->exec('TRUNCATE TABLE game_chat_messages');
        $pdo->exec('TRUNCATE TABLE game_pending_decisions');
        $pdo->exec('TRUNCATE TABLE game_pending_decision_batches');
        $pdo->exec('TRUNCATE TABLE game_round_scores');
        $pdo->exec('TRUNCATE TABLE game_cards');
        $pdo->exec('TRUNCATE TABLE game_rounds');
        $pdo->exec('TRUNCATE TABLE game_players');
        $pdo->exec('TRUNCATE TABLE games');
        $pdo->exec('TRUNCATE TABLE user_decklists');
        $pdo->exec('TRUNCATE TABLE user_lifetime_stats');
        $pdo->exec('TRUNCATE TABLE friendships');
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

        $this->matchRepo = new TournamentMatchRepository();
        $this->tournaments = new TournamentService(
            new TournamentRepository(),
            new TournamentParticipantRepository(),
            $this->matchRepo,
            new TournamentBracketBuilder(),
            $this->games,
            new UserRepository(),
            new FriendshipRepository(),
            new TournamentPodRepository(),
            new BoosterPackBuilder(),
            new BoosterDraftPodBuilder(),
        );
        $this->games->setTournamentObserver($this->tournaments);
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

    /**
     * A generic bracket-mechanics tournament -- Traditional format, whose
     * always-implied Structure deck (see TournamentService::createTournament()'s
     * own docblock) starts every match's game immediately with no
     * decklist submission required, exactly what these tests need to
     * drive a bracket to completion purely via decideMatch() resignations.
     * Named for what it tests (bracket shape/progression), not the
     * format itself, since that's incidental here.
     *
     * @param int[] $inviteUserIds
     */
    private function createStandardTournament(int $creatorUserId, array $inviteUserIds, string $bracketType, ?int $swissRoundCount = null): int
    {
        return $this->tournaments->createTournament(
            $creatorUserId,
            'Test Cup',
            $bracketType,
            'invite_only',
            ['format' => 'standard'],
            $swissRoundCount,
            minParticipants: 2,
            maxParticipants: null,
            inviteUserIds: $inviteUserIds,
        );
    }

    /** Resigns the LOSING seat of $gameId so the other player wins, driving the tournament's own observer hook. */
    private function loseGameAs(int $gameId, int $losingUserId): void
    {
        $gamePlayerId = $this->games->gamePlayerIdFor($gameId, $losingUserId);
        self::assertNotNull($gamePlayerId, "user {$losingUserId} isn't seated in game {$gameId}");
        $this->games->resignGame($gameId, $gamePlayerId);
    }

    /**
     * Resigns $losingUserId enough times to decide a tournament match
     * outright -- every tournament match is best-of-three now (see
     * TournamentService::createTournament()'s own docblock), so a single
     * resignation only ever wins game 1. Every generic bracket test in
     * this file plays Traditional (createStandardTournament()), whose
     * shared 'custom' deck_type carries its card ids forward
     * automatically once GameService::advanceGameMatch() creates game
     * 2 -- unlike a Power Duel/Booster Draft match, no fresh decklist
     * submission is needed, just an explicit startGame() call (the same
     * one an ordinary ad hoc match's own browser-side polling would
     * otherwise make).
     */
    private function decideMatch(int $firstGameId, int $losingUserId): void
    {
        $this->loseGameAs($firstGameId, $losingUserId);
        $gameMatchId = (int) $this->fetchGame($firstGameId)['game_match_id'];
        $game2Id = $this->fetchLatestGameIdForMatch($gameMatchId);
        $this->games->startGame($game2Id);
        $this->loseGameAs($game2Id, $losingUserId);
    }

    private function onlyGameFor(int $tournamentMatchId): array
    {
        $match = $this->matchRepo->find($tournamentMatchId);
        self::assertNotNull($match['game_id'], "tournament match {$tournamentMatchId} has no game yet");

        return $match;
    }

    public function testSingleEliminationFourPlayersToCompletion(): void
    {
        $creator = $this->insertUser('single_p1');
        $p2 = $this->insertUser('single_p2');
        $p3 = $this->insertUser('single_p3');
        $p4 = $this->insertUser('single_p4');

        $tournamentId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'single_elimination');
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }

        $this->tournaments->startTournament($tournamentId, $creator);

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('in_progress', $state['tournament']['status']);
        $round1 = array_values(array_filter($state['rounds'], static fn (array $r): bool => (int) $r['round_number'] === 1));
        self::assertCount(1, $round1);
        $round1Matches = $state['matches_by_round'][(int) $round1[0]['id']];
        self::assertCount(2, $round1Matches);

        // Resolve both round-1 games -- resign the seat NOT in our
        // intended winners list so seedToParticipantId's randomness
        // doesn't matter to the assertions below.
        $winners = [];
        foreach ($round1Matches as $match) {
            $game = $this->onlyGameFor((int) $match['id']);
            $p1 = $this->participantUserId((int) $match['participant1_id']);
            $p2Id = $this->participantUserId((int) $match['participant2_id']);
            $this->decideMatch((int) $game['game_id'], $p2Id);
            $winners[] = $p1;
        }

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('in_progress', $state['tournament']['status']);
        $round2 = array_values(array_filter($state['rounds'], static fn (array $r): bool => (int) $r['round_number'] === 2));
        $round2Match = $state['matches_by_round'][(int) $round2[0]['id']][0];
        self::assertNotNull($round2Match['participant1_id']);
        self::assertNotNull($round2Match['participant2_id']);

        $finalGame = $this->onlyGameFor((int) $round2Match['id']);
        $finalWinnerUserId = $winners[0];
        $finalLoserUserId = $this->participantUserId((int) $round2Match['participant1_id']) === $finalWinnerUserId
            ? $this->participantUserId((int) $round2Match['participant2_id'])
            : $this->participantUserId((int) $round2Match['participant1_id']);
        $this->decideMatch((int) $finalGame['game_id'], $finalLoserUserId);

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('completed', $state['tournament']['status']);
        self::assertSame($finalWinnerUserId, (int) $state['tournament']['winner_user_id']);
    }

    /**
     * createTournament() forces deck_type/duel_deck_rules/best_of_three
     * itself now -- the New Tournament dialog no longer offers any of
     * these as a choice, so this proves the server-side contract holds
     * even against a caller that sends something else entirely (a direct
     * API request bypassing the frontend, or a stale client): Duel
     * always becomes "Power Duel" (deck_type 'custom_duel' under the
     * "power" preset), Traditional always becomes 'structure' with its
     * own freshly-generated fixed deck, Booster Draft's own
     * `deck_type: 'booster_draft'` sentinel survives untouched (it's
     * also `format: 'duel'` under the hood but is a completely different
     * format choice, not "Duel" with something to override), and every
     * format gets best_of_three forced to true.
     */
    public function testCreateTournamentForcesFormatImpliedSettingsRegardlessOfWhatIsSent(): void
    {
        $creator = $this->insertUser('force_p1');
        $tournaments = new TournamentRepository();

        $duelId = $this->tournaments->createTournament(
            $creator,
            'Duel Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'duel', 'deck_type' => 'structure', 'best_of_three' => false],
            swissRoundCount: null,
            minParticipants: 2,
            maxParticipants: null,
        );
        $duelParams = $tournaments->find($duelId)['match_params'];
        self::assertSame('custom_duel', $duelParams['deck_type']);
        self::assertSame(['preset' => 'power'], $duelParams['duel_deck_rules']);
        self::assertTrue($duelParams['best_of_three']);

        $standardId = $this->tournaments->createTournament(
            $creator,
            'Standard Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'standard', 'deck_type' => 'power', 'best_of_three' => false],
            swissRoundCount: null,
            minParticipants: 2,
            maxParticipants: null,
        );
        $standardParams = $tournaments->find($standardId)['match_params'];
        self::assertSame('structure', $standardParams['deck_type']);
        self::assertNotEmpty($standardParams['structure_deck_card_ids']);
        self::assertTrue($standardParams['best_of_three']);

        $boosterDraftId = $this->tournaments->createTournament(
            $creator,
            'Booster Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'duel', 'deck_type' => 'booster_draft', 'best_of_three' => false],
            swissRoundCount: null,
            minParticipants: 2,
            maxParticipants: null,
        );
        $boosterDraftParams = $tournaments->find($boosterDraftId)['match_params'];
        self::assertSame('booster_draft', $boosterDraftParams['deck_type'], 'Booster Draft\'s own sentinel must survive the Duel override');
        self::assertArrayNotHasKey('duel_deck_rules', $boosterDraftParams);
        self::assertTrue($boosterDraftParams['best_of_three']);
    }

    /**
     * Traditional's fixed, once-per-tournament Structure deck (see
     * TournamentService::createTournament()'s own docblock) -- both
     * round-1 matches, seating four DIFFERENT participants, deal from
     * the exact same card ids GameService::generateStructureDeckCardIds()
     * generated once at tournament-creation time (stored on
     * match_params.structure_deck_card_ids), not a fresh random deck per
     * match the way an ordinary non-tournament Traditional game still
     * gets. Also proves the deck_type/name translation
     * startMatchGame() does under the hood: the underlying `games` row
     * is deck_type 'custom' (not 'structure') with custom_deck_name
     * 'Structure Deck', even though the tournament's own match_params
     * (and therefore tournamentMatchSummary() on the frontend) still say
     * 'structure'.
     */
    public function testStandardTournamentUsesOneFixedStructureDeckForEveryMatch(): void
    {
        $creator = $this->insertUser('fixed_deck_p1');
        $p2 = $this->insertUser('fixed_deck_p2');
        $p3 = $this->insertUser('fixed_deck_p3');
        $p4 = $this->insertUser('fixed_deck_p4');

        $tournamentId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'single_elimination');
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        $tournament = (new TournamentRepository())->find($tournamentId);
        self::assertSame('structure', $tournament['match_params']['deck_type']);
        $expectedCardIds = $tournament['match_params']['structure_deck_card_ids'];
        self::assertNotEmpty($expectedCardIds);

        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $round1Matches = $this->matchRepo->listForRound((int) $round1['id']);
        self::assertCount(2, $round1Matches);

        foreach ($round1Matches as $match) {
            $matchGame = $this->onlyGameFor((int) $match['id']);
            $game = $this->fetchGame((int) $matchGame['game_id']);
            self::assertSame('custom', $game['deck_type'], 'Traditional tournament matches use deck_type custom under the hood');
            self::assertSame('Structure Deck', $game['custom_deck_name']);
            self::assertSame($expectedCardIds, array_map(intval(...), json_decode((string) $game['custom_deck_card_ids'], true)));
        }
    }

    public function testSingleEliminationWithByeAutoAdvances(): void
    {
        $creator = $this->insertUser('bye_p1');
        $p2 = $this->insertUser('bye_p2');
        $p3 = $this->insertUser('bye_p3');

        $tournamentId = $this->createStandardTournament($creator, [$p2, $p3], 'single_elimination');
        $this->tournaments->acceptInvite($tournamentId, $p2);
        $this->tournaments->acceptInvite($tournamentId, $p3);
        $this->tournaments->startTournament($tournamentId, $creator);

        $rounds = $this->matchRepo->listRounds($tournamentId);
        $round1 = current(array_filter($rounds, static fn (array $r): bool => (int) $r['round_number'] === 1));
        $round1Matches = $this->matchRepo->listForRound((int) $round1['id']);

        $byeMatches = array_values(array_filter($round1Matches, static fn (array $m): bool => $m['status'] === 'bye'));
        $realMatches = array_values(array_filter($round1Matches, static fn (array $m): bool => $m['status'] === 'in_progress'));
        self::assertCount(1, $byeMatches);
        self::assertCount(1, $realMatches);
        self::assertNotNull($byeMatches[0]['winner_participant_id']);

        // The bye winner should already be seated in round 2.
        $round2 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => (int) $r['round_number'] === 2));
        $round2Match = $this->matchRepo->listForRound((int) $round2['id'])[0];
        self::assertContains((int) $byeMatches[0]['winner_participant_id'], [$round2Match['participant1_id'], $round2Match['participant2_id']]);
    }

    public function testDoubleEliminationWithBracketReset(): void
    {
        $creator = $this->insertUser('double_p1');
        $p2 = $this->insertUser('double_p2');
        $p3 = $this->insertUser('double_p3');
        $p4 = $this->insertUser('double_p4');

        $tournamentId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'double_elimination');
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        // Drive every winners-bracket round-1 match: p_winnerA beats
        // p_loserA, p_winnerB beats p_loserB (arbitrary which seed lands
        // where -- read it back from the actual bracket).
        $wbRound1 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => $r['bracket'] === 'single' && (int) $r['round_number'] === 1));
        $wbRound1Matches = $this->matchRepo->listForRound((int) $wbRound1['id']);
        self::assertCount(2, $wbRound1Matches);

        $wbLosers = [];
        foreach ($wbRound1Matches as $match) {
            $game = $this->onlyGameFor((int) $match['id']);
            $p1UserId = $this->participantUserId((int) $match['participant1_id']);
            $p2UserId = $this->participantUserId((int) $match['participant2_id']);
            $this->decideMatch((int) $game['game_id'], $p2UserId); // participant1 always wins WB round 1 in this test
            $wbLosers[] = $p2UserId;
        }

        // Losers bracket round 1: the two WB round-1 losers play each other.
        $lbRound1 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => $r['bracket'] === 'losers' && (int) $r['round_number'] === 1));
        $lbRound1Matches = $this->matchRepo->listForRound((int) $lbRound1['id']);
        self::assertCount(1, $lbRound1Matches);
        $lbMatch = $this->onlyGameFor((int) $lbRound1Matches[0]['id']);
        $lbP1 = $this->participantUserId((int) $lbRound1Matches[0]['participant1_id']);
        $lbP2 = $this->participantUserId((int) $lbRound1Matches[0]['participant2_id']);
        self::assertEqualsCanonicalizing($wbLosers, [$lbP1, $lbP2]);
        $this->decideMatch((int) $lbMatch['game_id'], $lbP2); // lbP1 wins LB round 1

        // Winners bracket final (round 2 of 'single'): the two WB round-1 winners.
        $wbFinalRound = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => $r['bracket'] === 'single' && (int) $r['round_number'] === 2));
        $wbFinalMatch = $this->matchRepo->listForRound((int) $wbFinalRound['id'])[0];
        $wbFinalGame = $this->onlyGameFor((int) $wbFinalMatch['id']);
        $wbFinalP1 = $this->participantUserId((int) $wbFinalMatch['participant1_id']);
        $wbFinalP2 = $this->participantUserId((int) $wbFinalMatch['participant2_id']);
        $this->decideMatch((int) $wbFinalGame['game_id'], $wbFinalP2); // wbFinalP1 is the WB champion

        // Losers bracket final (round 2 of 'losers'): LB round 1 winner vs WB final loser.
        $lbFinalRound = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => $r['bracket'] === 'losers' && (int) $r['round_number'] === 2));
        $lbFinalMatch = $this->matchRepo->listForRound((int) $lbFinalRound['id'])[0];
        $lbFinalGame = $this->onlyGameFor((int) $lbFinalMatch['id']);
        $lbFinalP1 = $this->participantUserId((int) $lbFinalMatch['participant1_id']);
        $lbFinalP2 = $this->participantUserId((int) $lbFinalMatch['participant2_id']);
        self::assertEqualsCanonicalizing([$lbP1, $wbFinalP2], [$lbFinalP1, $lbFinalP2]);
        // The losers-bracket champion (lbP1) wins through to the grand final.
        $lbChampion = $lbP1;
        $lbFinalLoser = $lbFinalP1 === $lbChampion ? $lbFinalP2 : $lbFinalP1;
        $this->decideMatch((int) $lbFinalGame['game_id'], $lbFinalLoser);

        // Grand final round 1: WB champion vs LB champion. Force the LB
        // champion to win -- this must trigger a bracket-reset round 2,
        // NOT end the tournament outright.
        $gfRound1 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => $r['bracket'] === 'grand_final' && (int) $r['round_number'] === 1));
        $gfMatch1 = $this->matchRepo->listForRound((int) $gfRound1['id'])[0];
        self::assertSame($wbFinalP1, $this->participantUserId((int) $gfMatch1['participant1_id']));
        self::assertSame($lbChampion, $this->participantUserId((int) $gfMatch1['participant2_id']));
        $gfGame1 = $this->onlyGameFor((int) $gfMatch1['id']);
        $this->decideMatch((int) $gfGame1['game_id'], $wbFinalP1); // LB champion wins round 1 -- WB champion's first loss

        $stateAfterGf1 = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('in_progress', $stateAfterGf1['tournament']['status'], 'a bracket reset must be played, not end the tournament yet');

        $gfRound2 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => $r['bracket'] === 'grand_final' && (int) $r['round_number'] === 2));
        $gfMatch2 = $this->matchRepo->listForRound((int) $gfRound2['id'])[0];
        $gfGame2 = $this->onlyGameFor((int) $gfMatch2['id']);
        // WB champion wins the reset -- they should be the tournament champion outright.
        $this->decideMatch((int) $gfGame2['game_id'], $lbChampion);

        $finalState = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('completed', $finalState['tournament']['status']);
        self::assertSame($wbFinalP1, (int) $finalState['tournament']['winner_user_id']);
    }

    /**
     * 5 participants pads to a size-8 bracket with 3 byes -- exercises
     * the exact shape hand-traced in TournamentBracketBuilder's own
     * docblock: one losers-bracket slot ends up a genuine bye (a lone
     * winners-bracket-round-1 bye-winner's eventual loser has no
     * opponent waiting), and another ends up entirely EMPTY (both its
     * would-be sources were themselves winners-bracket byes) -- neither
     * of which existed before non-power-of-two support. Drives the
     * whole bracket to completion by always resigning whichever seat is
     * NOT participant1 of each newly-in_progress match, in a loop until
     * nothing is left in_progress, then asserts the tournament actually
     * reaches 'completed' (not stuck waiting on a slot that can never
     * fill) and that at least one losers-bracket match resolved via a
     * genuine bye (status 'bye', never got a game of its own).
     */
    public function testDoubleEliminationWithFiveParticipantsUsesLosersBracketByes(): void
    {
        $creator = $this->insertUser('de5_p1');
        $invitees = [
            $this->insertUser('de5_p2'),
            $this->insertUser('de5_p3'),
            $this->insertUser('de5_p4'),
            $this->insertUser('de5_p5'),
        ];

        $tournamentId = $this->createStandardTournament($creator, $invitees, 'double_elimination');
        foreach ($invitees as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        for ($i = 0; $i < 20; $i++) {
            $resolvedAny = false;
            foreach ($this->matchRepo->listForTournament($tournamentId) as $match) {
                if ($match['status'] === 'in_progress' && $match['game_id'] !== null) {
                    $loserUserId = $this->participantUserId((int) $match['participant2_id']);
                    $this->decideMatch((int) $match['game_id'], $loserUserId);
                    $resolvedAny = true;
                }
            }
            if (!$resolvedAny) {
                break;
            }
        }

        $finalState = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('completed', $finalState['tournament']['status'], 'the bracket must not get stuck on an unfillable slot');
        self::assertNotNull($finalState['tournament']['winner_user_id']);

        $byeCount = 0;
        $lbMatchesExisting = 0;
        foreach ($finalState['rounds'] as $round) {
            if ($round['bracket'] !== 'losers') {
                continue;
            }
            foreach ($finalState['matches_by_round'][$round['id']] as $match) {
                $lbMatchesExisting++;
                if ($match['status'] === 'bye') {
                    $byeCount++;
                }
            }
        }
        self::assertGreaterThan(0, $byeCount, 'at least one losers-bracket slot should have resolved as a genuine bye');
        // 5 participants, size 8: losers bracket has 4 rounds' worth of
        // slot capacity but one slot is structurally EMPTY (both
        // winners-round-1 sources were byes) -- see this test's own
        // docblock -- so strictly fewer rows exist than the full
        // power-of-two shape would have.
        self::assertLessThan(6, $lbMatchesExisting, 'the structurally-empty losers-bracket slot must never get a row at all');
    }

    public function testSwissFourPlayersTwoRoundsToCompletion(): void
    {
        $creator = $this->insertUser('swiss_p1');
        $p2 = $this->insertUser('swiss_p2');
        $p3 = $this->insertUser('swiss_p3');
        $p4 = $this->insertUser('swiss_p4');

        $tournamentId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'swiss', swissRoundCount: 2);
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        $round1 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => (int) $r['round_number'] === 1));
        $round1Matches = $this->matchRepo->listForRound((int) $round1['id']);
        self::assertCount(2, $round1Matches);

        $round1WinnerUserIds = [];
        foreach ($round1Matches as $match) {
            $game = $this->onlyGameFor((int) $match['id']);
            $p1UserId = $this->participantUserId((int) $match['participant1_id']);
            $p2UserId = $this->participantUserId((int) $match['participant2_id']);
            $this->decideMatch((int) $game['game_id'], $p2UserId);
            $round1WinnerUserIds[] = $p1UserId;
        }

        // Round 2 must pair the two round-1 winners against each other
        // (only 2 players have 1 win, the "no repeat pairing" rule
        // doesn't force anything here since round 1 already used up the
        // only pairing between the two 0-win players too).
        $round2 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => (int) $r['round_number'] === 2));
        $round2Matches = $this->matchRepo->listForRound((int) $round2['id']);
        self::assertCount(2, $round2Matches);

        foreach ($round2Matches as $match) {
            $game = $this->onlyGameFor((int) $match['id']);
            $p1UserId = $this->participantUserId((int) $match['participant1_id']);
            $p2UserId = $this->participantUserId((int) $match['participant2_id']);
            // Whoever is participant1 wins again -- if this is the
            // undefeated-vs-undefeated match, that decides the champion
            // outright at 2-0.
            $this->decideMatch((int) $game['game_id'], $p2UserId);
        }

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('completed', $state['tournament']['status']);
        self::assertNotNull($state['tournament']['winner_user_id']);
        self::assertNotNull($state['standings']);
    }

    public function testOpenRegistrationRespectsDiscoverabilityAndCapacity(): void
    {
        $creator = $this->insertUser('open_p1');
        (new UserRepository())->setMatchmakingDiscoverable($creator, true);
        $joiner = $this->insertUser('open_p2');
        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Open Cup',
            'single_elimination',
            'open',
            ['format' => 'standard'],
            null,
            minParticipants: 2,
            maxParticipants: 2,
        );

        $this->tournaments->joinOpenTournament($tournamentId, $joiner);

        $thirdJoiner = $this->insertUser('open_p3');
        $this->expectException(TournamentStateException::class);
        $this->tournaments->joinOpenTournament($tournamentId, $thirdJoiner);
    }

    public function testNonCreatorCannotStartTournament(): void
    {
        $creator = $this->insertUser('auth_p1');
        $p2 = $this->insertUser('auth_p2');
        $tournamentId = $this->createStandardTournament($creator, [$p2], 'single_elimination');
        $this->tournaments->acceptInvite($tournamentId, $p2);

        $this->expectException(NotAuthorizedForTournamentException::class);
        $this->tournaments->startTournament($tournamentId, $p2);
    }

    /**
     * A Duel tournament ("Power Duel" in the New Tournament dialog)
     * always uses deck_type 'custom_duel' under the "power"
     * duel_deck_rules preset now (each player submits their own
     * decklist, validated against DuelDeckRules::forPreset('power'))
     * rather than one of the algorithmically-assembled deck types --
     * createTournament() forces this itself (see its own docblock)
     * regardless of what's passed in, which this test proves by passing
     * neither 'deck_type' nor 'duel_deck_rules' at all. startMatchGame()
     * creates the game up front same as any other match, but it starts
     * out 'waiting' rather than 'in_progress' since neither player has
     * submitted a decklist yet -- exactly the same tolerance the class
     * docblock already describes for draft matches. allow_sideboarding
     * here also proves TournamentService threads it through to the
     * created game_match wrapper.
     */
    public function testDuelTournamentSupportsCustomDuelPowerDecksWithSideboarding(): void
    {
        $creator = $this->insertUser('power_p1');
        $p2 = $this->insertUser('power_p2');

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Power Cup',
            'single_elimination',
            'invite_only',
            [
                'format' => 'duel',
                'allow_sideboarding' => true,
            ],
            swissRoundCount: null,
            minParticipants: 2,
            maxParticipants: null,
            inviteUserIds: [$p2],
        );
        $this->tournaments->acceptInvite($tournamentId, $p2);
        $this->tournaments->startTournament($tournamentId, $creator);

        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $match = $this->matchRepo->listForRound((int) $round1['id'])[0];
        self::assertNotNull($match['game_id'], 'a custom_duel match should still create its game up front, left waiting on decklists');

        $game = $this->fetchGame((int) $match['game_id']);
        self::assertSame('waiting', $game['status'], "a custom_duel game can't start until both players submit a decklist");
        self::assertSame('custom_duel', $game['deck_type']);
        self::assertNotNull($game['game_match_id']);
        self::assertTrue((bool) $this->fetchGameMatch((int) $game['game_match_id'])['allow_sideboarding']);

        $decklistText = implode("\n", array_map(static fn (string $name): string => "1 {$name}", $this->fetchNonMythicCardNames(15)));
        $this->games->submitCustomDuelDeck((int) $match['game_id'], $this->games->gamePlayerIdFor((int) $match['game_id'], $creator), $decklistText);
        $this->games->submitCustomDuelDeck((int) $match['game_id'], $this->games->gamePlayerIdFor((int) $match['game_id'], $p2), $decklistText);
        $this->games->startGame((int) $match['game_id']);

        self::assertSame('in_progress', $this->fetchGame((int) $match['game_id'])['status']);
    }

    /**
     * A Draft-format tournament may also use deck_type 'grid_draft'
     * instead of the original 'quick_draft' -- both are 2-4 player draft
     * deck types that play a best-of-three match at exactly 2 players
     * (every tournament match's own fixed seat count), so nothing about
     * TournamentService itself needs to change, only the New Tournament
     * dialog's own "Draft type" option and (see below) the pool source
     * every draft deck_type requires. match_params must set
     * 'grid_draft_pool_source' itself -- GameService::createGame() has no
     * default of its own for it (an omitted pool source resolves to an
     * empty string, which buildDraftPool() rejects with 'Unknown pool
     * source ""'), so the New Tournament dialog now always sends
     * 'random_48' for whichever draft type is chosen.
     */
    public function testDraftTournamentSupportsGridDraft(): void
    {
        $creator = $this->insertUser('grid_draft_p1');
        $p2 = $this->insertUser('grid_draft_p2');

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Grid Draft Cup',
            'single_elimination',
            'invite_only',
            [
                'format' => 'draft',
                'deck_type' => 'grid_draft',
                'grid_draft_pool_source' => 'random_48',
            ],
            swissRoundCount: null,
            minParticipants: 2,
            maxParticipants: null,
            inviteUserIds: [$p2],
        );
        $this->tournaments->acceptInvite($tournamentId, $p2);
        $this->tournaments->startTournament($tournamentId, $creator);

        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $match = $this->matchRepo->listForRound((int) $round1['id'])[0];
        self::assertNotNull($match['game_id'], 'a grid_draft match should still create its game up front, left drafting');

        $game = $this->fetchGame((int) $match['game_id']);
        self::assertSame('grid_draft', $game['deck_type']);
        self::assertNotNull($game['draft_match_id']);

        $state = $this->games->getState((int) $match['game_id'], $creator);
        self::assertSame('drafting', $state['grid_draft']['status']);
        self::assertCount(9, $state['grid_draft']['drafting']['grid_cards'], '2 players draft from a 3x3 grid');
    }

    /**
     * Omitting a draft deck_type's own pool source entirely (the bug this
     * feature's own fix addresses -- see testDraftTournamentSupportsGridDraft()'s
     * own docblock) fails the match at start time exactly like any other
     * bad fixed match setting, converted to a TournamentStateException
     * the same way custom_duel's own "needs at least 4 participants"-style
     * GameStateExceptions already are.
     */
    public function testDraftTournamentWithoutAPoolSourceFailsToStart(): void
    {
        $creator = $this->insertUser('no_pool_p1');
        $p2 = $this->insertUser('no_pool_p2');

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'No Pool Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'draft', 'deck_type' => 'grid_draft'],
            swissRoundCount: null,
            minParticipants: 2,
            maxParticipants: null,
            inviteUserIds: [$p2],
        );
        $this->tournaments->acceptInvite($tournamentId, $p2);

        $this->expectException(TournamentStateException::class);
        $this->expectExceptionMessage('Unknown pool source');
        $this->tournaments->startTournament($tournamentId, $creator);
    }

    /**
     * A 5-player Booster Draft tournament (fits in a single pod, since
     * pods only split above 8 -- see BoosterDraftPodBuilderTest for the
     * pod-splitting math itself) end to end: pod formation, drafting all
     * 15 rounds to completion, the tournament auto-transitioning out of
     * 'drafting' once the pod finishes, the resulting bracket's own
     * first real match using each side's own tournament-drafted pool
     * (deck_type 'custom_duel' + game_players.custom_deck_allowed_card_ids,
     * never the tournament's own 'booster_draft' sentinel directly --
     * see TournamentService::startMatchGame()'s own docblock), and
     * GameService::submitCustomDuelDeck()'s own onCustomDuelDeckSubmitted()
     * hook persisting each side's submission as their new
     * tournament_participants.current_deck_card_ids, and driving that
     * match to completion over two games (every tournament match is
     * best-of-three now, so one resignation only decides game 1).
     */
    public function testBoosterDraftFormsAPodDraftsToCompletionAndPlaysBracketMatches(): void
    {
        $userIds = [];
        foreach (['bd_p1', 'bd_p2', 'bd_p3', 'bd_p4', 'bd_p5'] as $username) {
            $userIds[] = $this->insertUser($username);
        }
        $creator = $userIds[0];
        $others = array_slice($userIds, 1);

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Booster Draft Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'duel', 'deck_type' => 'booster_draft'],
            swissRoundCount: null,
            minParticipants: 2,
            maxParticipants: null,
            inviteUserIds: $others,
        );
        foreach ($others as $userId) {
            $this->tournaments->acceptInvite($tournamentId, $userId);
        }

        $this->tournaments->startTournament($tournamentId, $creator);

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('drafting', $state['tournament']['status']);
        self::assertCount(1, $state['pods'], '5 participants fit in a single pod');
        self::assertCount(5, $state['pods'][0]['seats']);

        // Drive the whole pod draft to completion: 15 rounds, both
        // directions, every seat always takes the first card its own
        // currently-held booster offers.
        for ($round = 1; $round <= 15; $round++) {
            foreach (['left', 'right'] as $direction) {
                foreach ($userIds as $userId) {
                    $podState = $this->tournaments->getPodDraftState($tournamentId, $userId);
                    if ($podState[$direction] === null) {
                        continue;
                    }
                    $this->tournaments->pickBoosterDraftCard($tournamentId, $userId, $direction, $podState[$direction][0]);
                }
            }
        }

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('in_progress', $state['tournament']['status'], 'drafting done -> bracket should auto-materialize');
        self::assertSame('completed', $state['pods'][0]['status']);

        $participants = new TournamentParticipantRepository();
        foreach ($userIds as $userId) {
            $participant = $participants->findForUser($tournamentId, $userId);
            self::assertCount(30, $participant['draft_pool_card_ids'], "user {$userId} should have drafted exactly 30 cards");
            self::assertNull($participant['current_deck_card_ids'], 'no deck submitted yet');
        }

        // 5 participants -> bracket size 8: exactly one round-1 match
        // (the two non-bye seeds) is real; the other three are byes.
        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $round1Matches = $this->matchRepo->listForRound((int) $round1['id']);
        $realMatches = array_values(array_filter($round1Matches, static fn (array $m): bool => $m['status'] === 'in_progress'));
        self::assertCount(1, $realMatches);
        $match = $realMatches[0];

        $game = $this->fetchGame((int) $match['game_id']);
        self::assertSame('custom_duel', $game['deck_type'], 'Booster Draft matches are ordinary custom_duel games under the hood');

        $p1UserId = $this->participantUserId((int) $match['participant1_id']);
        $p2UserId = $this->participantUserId((int) $match['participant2_id']);
        $p1Pool = $participants->find((int) $match['participant1_id'])['draft_pool_card_ids'];
        $p2Pool = $participants->find((int) $match['participant2_id'])['draft_pool_card_ids'];

        $p1PlayerId = $this->games->gamePlayerIdFor((int) $match['game_id'], $p1UserId);
        $p2PlayerId = $this->games->gamePlayerIdFor((int) $match['game_id'], $p2UserId);
        $allowedStmt = $this->pdo->prepare('SELECT custom_deck_allowed_card_ids FROM game_players WHERE id = :id');
        $allowedStmt->execute(['id' => $p1PlayerId]);
        self::assertNotNull($allowedStmt->fetchColumn(), "seat should carry its own pool restriction");

        // Each side submits a 12-card deck drawn from a prefix of their
        // own pool -- trivially a legal subset, multiplicity included.
        $this->games->submitCustomDuelDeck((int) $match['game_id'], $p1PlayerId, $this->decklistTextForCardIds(array_slice($p1Pool, 0, 12)));
        $this->games->submitCustomDuelDeck((int) $match['game_id'], $p2PlayerId, $this->decklistTextForCardIds(array_slice($p2Pool, 0, 12)));
        $this->games->startGame((int) $match['game_id']);

        self::assertSame('in_progress', $this->fetchGame((int) $match['game_id'])['status']);

        $p1Participant = $participants->find((int) $match['participant1_id']);
        $p2Participant = $participants->find((int) $match['participant2_id']);
        self::assertCount(12, $p1Participant['current_deck_card_ids'], 'submission should have persisted as this participant\'s new current deck');
        self::assertCount(12, $p2Participant['current_deck_card_ids']);

        // Booster Draft matches are best-of-three now too (every
        // tournament format is -- see TournamentService::createTournament()'s
        // own docblock), so one resignation only wins game 1, not the
        // match itself. Booster Draft's own "sideboard from your whole
        // pool every round" story means neither seat's deck carries
        // forward automatically the way a locked Power Duel match's does
        // (isPowerDuelMatch is false for a 'user_defined' preset) --
        // game 2 needs its own fresh submission from the same pool
        // before it can start.
        $this->loseGameAs((int) $match['game_id'], $p2UserId);
        $gameMatchId = (int) $this->fetchGame((int) $match['game_id'])['game_match_id'];
        $game2Id = $this->fetchLatestGameIdForMatch($gameMatchId);
        $this->games->submitCustomDuelDeck($game2Id, $this->games->gamePlayerIdFor($game2Id, $p1UserId), $this->decklistTextForCardIds(array_slice($p1Pool, 0, 12)));
        $this->games->submitCustomDuelDeck($game2Id, $this->games->gamePlayerIdFor($game2Id, $p2UserId), $this->decklistTextForCardIds(array_slice($p2Pool, 0, 12)));
        $this->games->startGame($game2Id);
        $this->loseGameAs($game2Id, $p2UserId);

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('in_progress', $state['tournament']['status'], 'more rounds remain with 5 participants');
    }

    private function fetchLatestGameIdForMatch(int $gameMatchId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM games WHERE game_match_id = :id ORDER BY match_game_number DESC LIMIT 1');
        $stmt->execute(['id' => $gameMatchId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param int[] $cardIds honors multiplicity (a repeated id becomes "2 Name") */
    private function decklistTextForCardIds(array $cardIds): string
    {
        $counts = array_count_values($cardIds);
        $placeholders = implode(',', array_fill(0, count($counts), '?'));
        $stmt = $this->pdo->prepare("SELECT id, name FROM cards WHERE id IN ({$placeholders})");
        $stmt->execute(array_keys($counts));
        $namesById = array_column($stmt->fetchAll(), 'name', 'id');

        $lines = [];
        foreach ($counts as $cardId => $count) {
            $lines[] = "{$count} {$namesById[$cardId]}";
        }

        return implode("\n", $lines);
    }

    private function participantUserId(int $participantId): int
    {
        $participant = (new TournamentParticipantRepository())->find($participantId);

        return (int) $participant['user_id'];
    }

    private function fetchGame(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);

        return $stmt->fetch();
    }

    private function fetchGameMatch(int $gameMatchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_matches WHERE id = :id');
        $stmt->execute(['id' => $gameMatchId]);

        return $stmt->fetch();
    }

    /** @return string[] */
    private function fetchNonMythicCardNames(int $count): array
    {
        $stmt = $this->pdo->prepare("SELECT name FROM cards WHERE rarity != 'mythic' ORDER BY id LIMIT :count");
        $stmt->bindValue('count', $count, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
