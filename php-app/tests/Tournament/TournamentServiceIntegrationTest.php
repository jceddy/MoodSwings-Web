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
use MoodSwings\Repository\TournamentRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
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

    /** @param int[] $userIds */
    private function createDuelTournament(int $creatorUserId, array $inviteUserIds, string $bracketType, ?int $swissRoundCount = null): int
    {
        return $this->tournaments->createTournament(
            $creatorUserId,
            'Test Cup',
            $bracketType,
            'invite_only',
            ['format' => 'duel', 'deck_type' => 'structure'],
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

        $tournamentId = $this->createDuelTournament($creator, [$p2, $p3, $p4], 'single_elimination');
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
            $this->loseGameAs((int) $game['game_id'], $p2Id);
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
        $this->loseGameAs((int) $finalGame['game_id'], $finalLoserUserId);

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('completed', $state['tournament']['status']);
        self::assertSame($finalWinnerUserId, (int) $state['tournament']['winner_user_id']);
    }

    public function testSingleEliminationWithByeAutoAdvances(): void
    {
        $creator = $this->insertUser('bye_p1');
        $p2 = $this->insertUser('bye_p2');
        $p3 = $this->insertUser('bye_p3');

        $tournamentId = $this->createDuelTournament($creator, [$p2, $p3], 'single_elimination');
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

        $tournamentId = $this->createDuelTournament($creator, [$p2, $p3, $p4], 'double_elimination');
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
            $this->loseGameAs((int) $game['game_id'], $p2UserId); // participant1 always wins WB round 1 in this test
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
        $this->loseGameAs((int) $lbMatch['game_id'], $lbP2); // lbP1 wins LB round 1

        // Winners bracket final (round 2 of 'single'): the two WB round-1 winners.
        $wbFinalRound = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => $r['bracket'] === 'single' && (int) $r['round_number'] === 2));
        $wbFinalMatch = $this->matchRepo->listForRound((int) $wbFinalRound['id'])[0];
        $wbFinalGame = $this->onlyGameFor((int) $wbFinalMatch['id']);
        $wbFinalP1 = $this->participantUserId((int) $wbFinalMatch['participant1_id']);
        $wbFinalP2 = $this->participantUserId((int) $wbFinalMatch['participant2_id']);
        $this->loseGameAs((int) $wbFinalGame['game_id'], $wbFinalP2); // wbFinalP1 is the WB champion

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
        $this->loseGameAs((int) $lbFinalGame['game_id'], $lbFinalLoser);

        // Grand final round 1: WB champion vs LB champion. Force the LB
        // champion to win -- this must trigger a bracket-reset round 2,
        // NOT end the tournament outright.
        $gfRound1 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => $r['bracket'] === 'grand_final' && (int) $r['round_number'] === 1));
        $gfMatch1 = $this->matchRepo->listForRound((int) $gfRound1['id'])[0];
        self::assertSame($wbFinalP1, $this->participantUserId((int) $gfMatch1['participant1_id']));
        self::assertSame($lbChampion, $this->participantUserId((int) $gfMatch1['participant2_id']));
        $gfGame1 = $this->onlyGameFor((int) $gfMatch1['id']);
        $this->loseGameAs((int) $gfGame1['game_id'], $wbFinalP1); // LB champion wins round 1 -- WB champion's first loss

        $stateAfterGf1 = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('in_progress', $stateAfterGf1['tournament']['status'], 'a bracket reset must be played, not end the tournament yet');

        $gfRound2 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => $r['bracket'] === 'grand_final' && (int) $r['round_number'] === 2));
        $gfMatch2 = $this->matchRepo->listForRound((int) $gfRound2['id'])[0];
        $gfGame2 = $this->onlyGameFor((int) $gfMatch2['id']);
        // WB champion wins the reset -- they should be the tournament champion outright.
        $this->loseGameAs((int) $gfGame2['game_id'], $lbChampion);

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

        $tournamentId = $this->createDuelTournament($creator, $invitees, 'double_elimination');
        foreach ($invitees as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        for ($i = 0; $i < 20; $i++) {
            $resolvedAny = false;
            foreach ($this->matchRepo->listForTournament($tournamentId) as $match) {
                if ($match['status'] === 'in_progress' && $match['game_id'] !== null) {
                    $loserUserId = $this->participantUserId((int) $match['participant2_id']);
                    $this->loseGameAs((int) $match['game_id'], $loserUserId);
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

        $tournamentId = $this->createDuelTournament($creator, [$p2, $p3, $p4], 'swiss', swissRoundCount: 2);
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
            $this->loseGameAs((int) $game['game_id'], $p2UserId);
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
            $this->loseGameAs((int) $game['game_id'], $p2UserId);
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
            ['format' => 'duel', 'deck_type' => 'structure'],
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
        $tournamentId = $this->createDuelTournament($creator, [$p2], 'single_elimination');
        $this->tournaments->acceptInvite($tournamentId, $p2);

        $this->expectException(NotAuthorizedForTournamentException::class);
        $this->tournaments->startTournament($tournamentId, $p2);
    }

    private function participantUserId(int $participantId): int
    {
        $participant = (new TournamentParticipantRepository())->find($participantId);

        return (int) $participant['user_id'];
    }
}
