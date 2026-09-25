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
use MoodSwings\Tournament\GridDraftPodBuilder;
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
        $pdo->exec('TRUNCATE TABLE tournament_cast_grants');
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
            new GridDraftPodBuilder(),
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
            minParticipants: 4,
            maxParticipants: 16,
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

    /**
     * decideMatch()'s own two-resignation pattern, but for a Grid Draft
     * "Pods with playoffs" pod-bracket match (or any other pool-restricted
     * custom_duel tournament match): unlike Traditional's shared 'custom'
     * deck_type, a pool-restricted match's own game 2 does NOT carry a
     * decklist forward automatically (same "sideboard from your whole
     * pool every round" story testBoosterDraftFormsAPodDraftsToCompletionAndPlaysBracketMatches()'s
     * own docblock describes) -- game 2 needs its own fresh submission
     * from the same pool before startGame() will accept it.
     */
    private function decidePodBracketMatch(int $firstGameId, int $participant1Id, int $participant2Id, int $losingUserId): void
    {
        $this->loseGameAs($firstGameId, $losingUserId);
        $gameMatchId = (int) $this->fetchGame($firstGameId)['game_match_id'];
        $game2Id = $this->fetchLatestGameIdForMatch($gameMatchId);
        $this->submitPodBracketDecksAndStart($game2Id, $participant1Id, $participant2Id);
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

        // Reported live: show the winner on the tournaments display --
        // TournamentRepository::listForUser()'s own LEFT JOIN onto users
        // resolves winner_user_id into a plain username the frontend can
        // show without a separate lookup.
        $expectedWinnerUsername = (new UserRepository())->findById($finalWinnerUserId)['username'];
        $listed = array_values(array_filter($this->tournaments->listMine($creator), static fn (array $t): bool => (int) $t['id'] === $tournamentId))[0];
        self::assertSame($expectedWinnerUsername, $listed['winner_username']);
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
            minParticipants: 4,
            maxParticipants: 16,
            creatorDecklistText: $this->powerDuelDecklistText(),
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
            minParticipants: 4,
            maxParticipants: 16,
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
            minParticipants: 4,
            maxParticipants: 16,
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

    /**
     * Reported live: no "Rematch" button for a tournament match -- the
     * bracket already decides who plays whom next. `canRematch()`
     * (web-static/js/game.js) reads `getState()['game']['is_tournament_match']`
     * to hide it, computed by `GameService::buildGameState()` from
     * whether a `tournament_matches` row points at the game -- true for
     * a tournament match (this test's own Traditional match, whose
     * `deck_type` is `'custom'` under the hood, same as any other
     * `game_id`-linked single game), false for an ordinary ad hoc game
     * that never touches the tournament system at all.
     */
    public function testGameStateExposesIsTournamentMatchForRematchButtonVisibility(): void
    {
        $creator = $this->insertUser('tourney_game_p1');
        $p2 = $this->insertUser('tourney_game_p2');
        $p3 = $this->insertUser('tourney_game_p3');
        $p4 = $this->insertUser('tourney_game_p4');

        $tournamentId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'single_elimination');
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $match = $this->matchRepo->listForRound((int) $round1['id'])[0];
        $tournamentGameId = (int) $match['game_id'];
        // is_tournament_match is only ever computed for a game's own
        // creator (see buildGameState()'s own comment) -- whichever of
        // this match's two participants happened to seed first, not
        // necessarily the tournament's own creator.
        $tournamentGameCreatorUserId = (int) $this->fetchGame($tournamentGameId)['created_by_user_id'];

        $tournamentGameState = $this->games->getState($tournamentGameId, $tournamentGameCreatorUserId);
        self::assertTrue($tournamentGameState['game']['is_tournament_match']);

        $opponent = $this->insertUser('tourney_game_opponent');
        $adHocGameId = $this->games->createGame($creator, [$creator, $opponent]);

        $adHocGameState = $this->games->getState($adHocGameId, $creator);
        self::assertFalse($adHocGameState['game']['is_tournament_match']);
    }

    /**
     * Tournament spectator mode (issue #238): GameService::
     * tournamentIdForGame() is the authorization plumbing
     * canCastTournamentGame() (public/index.php) relies on -- resolves a
     * real tournament match's own game_id back to its tournament, null
     * for an ad hoc game. Combined here with the full grant ->
     * hasCastAccess -> getTournamentCastState() chain against a real
     * still-in_progress tournament match (unlike
     * GameServiceIntegrationTest's own equivalent, which drives
     * buildGameState() directly against a hand-built game row).
     */
    public function testTournamentIdForGameResolvesARealMatchAndCastStateRevealsItsHands(): void
    {
        $creator = $this->insertUser('cast_tourney_p1');
        $p2 = $this->insertUser('cast_tourney_p2');
        $p3 = $this->insertUser('cast_tourney_p3');
        $p4 = $this->insertUser('cast_tourney_p4');
        $caster = $this->insertUser('cast_tourney_caster');

        $tournamentId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'single_elimination');
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $match = $this->matchRepo->listForRound((int) $round1['id'])[0];
        $tournamentGameId = (int) $match['game_id'];

        self::assertSame($tournamentId, $this->games->tournamentIdForGame($tournamentGameId));

        $adHocGameId = $this->games->createGame($creator, [$creator, $p2]);
        self::assertNull($this->games->tournamentIdForGame($adHocGameId));

        self::assertFalse($this->tournaments->hasCastAccess($tournamentId, $caster));
        $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_tourney_caster');
        self::assertTrue($this->tournaments->hasCastAccess($tournamentId, $caster));
        self::assertTrue($this->tournaments->castRevealsHands($tournamentId, $caster), 'grantCastAccess() defaults to a full, hands-revealed grant');

        $castState = $this->games->getTournamentCastState($tournamentGameId, revealHands: true);
        self::assertSame('in_progress', $castState['game']['status']);
        foreach ($castState['players'] as $player) {
            self::assertArrayHasKey('hand', $player, "player {$player['game_player_id']}'s hand should be revealed in cast mode");
        }
    }

    /**
     * 5 participants (the smallest odd count >= the new 4-participant
     * floor) pads to a size-8 bracket, needing byes in round 1 -- same
     * "auto-advance a bye winner straight into round 2" story as before,
     * just without hard-coding exactly how many byes/real matches a
     * 3-participant field used to produce.
     */
    public function testSingleEliminationWithByeAutoAdvances(): void
    {
        $creator = $this->insertUser('bye_p1');
        $p2 = $this->insertUser('bye_p2');
        $p3 = $this->insertUser('bye_p3');
        $p4 = $this->insertUser('bye_p4');
        $p5 = $this->insertUser('bye_p5');

        $tournamentId = $this->createStandardTournament($creator, [$p2, $p3, $p4, $p5], 'single_elimination');
        foreach ([$p2, $p3, $p4, $p5] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        $rounds = $this->matchRepo->listRounds($tournamentId);
        $round1 = current(array_filter($rounds, static fn (array $r): bool => (int) $r['round_number'] === 1));
        $round1Matches = $this->matchRepo->listForRound((int) $round1['id']);

        $byeMatches = array_values(array_filter($round1Matches, static fn (array $m): bool => $m['status'] === 'bye'));
        $realMatches = array_values(array_filter($round1Matches, static fn (array $m): bool => $m['status'] === 'in_progress'));
        self::assertNotEmpty($byeMatches, '5 participants pads to a size-8 bracket, needing byes');
        self::assertNotEmpty($realMatches);
        foreach ($byeMatches as $byeMatch) {
            self::assertNotNull($byeMatch['winner_participant_id']);
        }

        // Every bye winner should already be seated in round 2.
        $round2 = current(array_filter($this->matchRepo->listRounds($tournamentId), static fn (array $r): bool => (int) $r['round_number'] === 2));
        $round2ParticipantIds = [];
        foreach ($this->matchRepo->listForRound((int) $round2['id']) as $round2Match) {
            $round2ParticipantIds[] = $round2Match['participant1_id'];
            $round2ParticipantIds[] = $round2Match['participant2_id'];
        }
        foreach ($byeMatches as $byeMatch) {
            self::assertContains((int) $byeMatch['winner_participant_id'], $round2ParticipantIds);
        }
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
        $joiner1 = $this->insertUser('open_p2');
        $joiner2 = $this->insertUser('open_p3');
        $joiner3 = $this->insertUser('open_p4');
        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Open Cup',
            'single_elimination',
            'open',
            ['format' => 'standard'],
            null,
            minParticipants: 4,
            maxParticipants: 4,
        );

        // Creator + 3 joiners fills the tournament to its own max_participants (4).
        $this->tournaments->joinOpenTournament($tournamentId, $joiner1);
        $this->tournaments->joinOpenTournament($tournamentId, $joiner2);
        $this->tournaments->joinOpenTournament($tournamentId, $joiner3);

        // Reported live: show the number of players joined on the
        // tournaments display, for the tournament's own creator --
        // listMine()'s own joined_count field (TournamentRepository::
        // listForUser()'s new subquery).
        $listed = array_values(array_filter($this->tournaments->listMine($creator), static fn (array $t): bool => (int) $t['id'] === $tournamentId))[0];
        self::assertSame(4, (int) $listed['joined_count']);
        self::assertSame(4, (int) $listed['max_participants']);

        $fourthJoiner = $this->insertUser('open_p5');
        $this->expectException(TournamentStateException::class);
        $this->tournaments->joinOpenTournament($tournamentId, $fourthJoiner);
    }

    public function testWithdrawnParticipantCanRejoinOpenTournamentIfRoomRemains(): void
    {
        $creator = $this->insertUser('rejoin_p1');
        (new UserRepository())->setMatchmakingDiscoverable($creator, true);
        $joiner = $this->insertUser('rejoin_p2');
        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Rejoin Cup',
            'single_elimination',
            'open',
            ['format' => 'standard'],
            null,
            minParticipants: 4,
            maxParticipants: 4,
        );

        $this->tournaments->joinOpenTournament($tournamentId, $joiner);
        $this->tournaments->withdraw($tournamentId, $joiner);

        // Withdrawn shouldn't count against capacity, and the room it
        // freed up should be visible to rejoin -- the tournament reappears
        // in listOpenFor() for this exact user despite their own
        // (withdrawn) row already existing.
        $openListings = $this->tournaments->listOpenFor($joiner);
        self::assertNotEmpty(array_filter($openListings, static fn (array $t): bool => (int) $t['id'] === $tournamentId));

        $this->tournaments->joinOpenTournament($tournamentId, $joiner);

        $participant = (new TournamentParticipantRepository())->findForUser($tournamentId, $joiner);
        self::assertSame('joined', $participant['status']);
    }

    public function testWithdrawnParticipantCannotRejoinAFullOpenTournament(): void
    {
        $creator = $this->insertUser('rejoin_full_p1');
        (new UserRepository())->setMatchmakingDiscoverable($creator, true);
        $joiner1 = $this->insertUser('rejoin_full_p2');
        $joiner2 = $this->insertUser('rejoin_full_p3');
        $joiner3 = $this->insertUser('rejoin_full_p4');
        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Rejoin Full Cup',
            'single_elimination',
            'open',
            ['format' => 'standard'],
            null,
            minParticipants: 4,
            maxParticipants: 4,
        );

        $this->tournaments->joinOpenTournament($tournamentId, $joiner1);
        $this->tournaments->withdraw($tournamentId, $joiner1);
        // Someone else takes the now-open seat, filling the tournament
        // back up to its own max_participants (creator + joiner2 + joiner3
        // + a fresh joiner1 replacement) before joiner1 tries to come back.
        $this->tournaments->joinOpenTournament($tournamentId, $joiner2);
        $this->tournaments->joinOpenTournament($tournamentId, $joiner3);
        $joiner4 = $this->insertUser('rejoin_full_p5');
        $this->tournaments->joinOpenTournament($tournamentId, $joiner4);

        $this->expectException(TournamentStateException::class);
        $this->tournaments->joinOpenTournament($tournamentId, $joiner1);
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
     * duel_deck_rules preset now, rather than one of the
     * algorithmically-assembled deck types -- createTournament() forces
     * this itself (see its own docblock) regardless of what's passed in,
     * which this test proves by passing neither 'deck_type' nor
     * 'duel_deck_rules' at all.
     *
     * Issue reported live: "the deck submission should happen when the
     * player joins the tournament -- players use the same submitted
     * deck for the entire tournament" -- every participant's own
     * decklist (validated against DuelDeckRules::forPreset('power')) is
     * now required at join time (createTournament()'s own auto-join for
     * the creator, acceptInvite() here) rather than per-match, so
     * startMatchGame() carries it straight onto the freshly-created
     * game's own game_players row and the game starts 'in_progress'
     * immediately -- no separate per-match decklist submission needed
     * any more (see testPowerDuelLegacyParticipantFallsBackToPerGameSubmission()
     * for a participant predating this feature, whose deck still has to
     * be submitted the old way). allow_sideboarding here also proves
     * TournamentService threads both the game_match wrapper's own flag
     * and each participant's own declared sideboard pool through.
     */
    public function testDuelTournamentCarriesJoinTimeDeckIntoMatchOneImmediately(): void
    {
        $creator = $this->insertUser('power_p1');
        $p2 = $this->insertUser('power_p2');
        $p3 = $this->insertUser('power_p3');
        $p4 = $this->insertUser('power_p4');

        $mainDeck = $this->fetchNonMythicCardNames(15);
        $sideboardCard = $this->fetchNonMythicCardNames(16)[15];
        $decklistText = implode("\n", array_map(static fn (string $name): string => "1 {$name}", $mainDeck))
            . "\n\nSideboard\n1 {$sideboardCard}";

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
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: [$p2, $p3, $p4],
            creatorDecklistText: $decklistText,
        );
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee, $decklistText);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $match = $this->matchRepo->listForRound((int) $round1['id'])[0];
        self::assertNotNull($match['game_id'], 'a custom_duel match should still create its game up front');

        $game = $this->fetchGame((int) $match['game_id']);
        self::assertSame('custom_duel', $game['deck_type']);
        self::assertNotNull($game['game_match_id']);
        self::assertTrue((bool) $this->fetchGameMatch((int) $game['game_match_id'])['allow_sideboarding']);
        self::assertSame(
            'in_progress',
            $game['status'],
            'both seats already have their join-time deck carried forward, so the game should need no further decklist submission to start'
        );

        $p1UserId = $this->participantUserId((int) $match['participant1_id']);
        $p1GamePlayer = $this->fetchGamePlayer((int) $match['game_id'], $p1UserId);
        self::assertCount(15, json_decode((string) $p1GamePlayer['custom_deck_card_ids'], true));
        self::assertSame(
            [$sideboardCard],
            array_map(
                fn (int $cardId): string => $this->cardName($cardId),
                json_decode((string) $p1GamePlayer['custom_deck_sideboard_card_ids'], true)
            )
        );
    }

    /**
     * A participant who joined before this feature existed (migration
     * 0345) has a null tournament_participants.deck_card_ids -- their
     * match still gets created the same way, but startMatchGame() has
     * nothing to carry forward, so the game is left 'waiting' on the
     * original per-game submitCustomDuelDeck() flow exactly as before,
     * proving legacy tournaments keep working unmodified.
     */
    public function testPowerDuelLegacyParticipantFallsBackToPerGameSubmission(): void
    {
        $creator = $this->insertUser('legacy_p1');
        $p2 = $this->insertUser('legacy_p2');
        $p3 = $this->insertUser('legacy_p3');
        $p4 = $this->insertUser('legacy_p4');

        $decklistText = $this->powerDuelDecklistText();

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Legacy Power Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'duel'],
            swissRoundCount: null,
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: [$p2, $p3, $p4],
            creatorDecklistText: $decklistText,
        );
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee, $decklistText);
        }

        // Simulates every participant having joined before migration
        // 0345 -- nothing in the public API can produce this state any
        // more (a decklist is now required to join a custom_duel
        // tournament at all), so it's fabricated directly.
        $this->pdo->exec('UPDATE tournament_participants SET deck_card_ids = NULL, deck_sideboard_card_ids = NULL');

        $this->tournaments->startTournament($tournamentId, $creator);

        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $match = $this->matchRepo->listForRound((int) $round1['id'])[0];
        $game = $this->fetchGame((int) $match['game_id']);
        self::assertSame('waiting', $game['status'], "a legacy participant's match still waits on the old per-game submission flow");

        $p1UserId = $this->participantUserId((int) $match['participant1_id']);
        $p2UserId = $this->participantUserId((int) $match['participant2_id']);
        $this->games->submitCustomDuelDeck((int) $match['game_id'], $this->games->gamePlayerIdFor((int) $match['game_id'], $p1UserId), $decklistText);
        $this->games->submitCustomDuelDeck((int) $match['game_id'], $this->games->gamePlayerIdFor((int) $match['game_id'], $p2UserId), $decklistText);
        $this->games->startGame((int) $match['game_id']);

        self::assertSame('in_progress', $this->fetchGame((int) $match['game_id'])['status']);
    }

    /**
     * createTournament()/acceptInvite() both require a valid decklist
     * atomically for a custom_duel tournament -- a missing/invalid one
     * fails the whole call, leaving no half-joined participant behind.
     */
    public function testPowerDuelRequiresAValidDeckToCreateOrJoin(): void
    {
        $creator = $this->insertUser('nodeck_p1');
        $invitee = $this->insertUser('nodeck_p2');

        try {
            $this->tournaments->createTournament(
                $creator,
                'No Deck Cup',
                'single_elimination',
                'invite_only',
                ['format' => 'duel'],
                swissRoundCount: null,
                minParticipants: 4,
                maxParticipants: 16,
            );
            self::fail('Expected a GameStateException for the missing creator decklist');
        } catch (\MoodSwings\Game\Exceptions\GameStateException $e) {
            self::assertSame('A decklist is required', $e->getMessage());
        }

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Deck Required Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'duel'],
            swissRoundCount: null,
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: [$invitee],
            creatorDecklistText: $this->powerDuelDecklistText(),
        );

        try {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
            self::fail('Expected a GameStateException for the missing invitee decklist');
        } catch (\MoodSwings\Game\Exceptions\GameStateException $e) {
            self::assertSame('A decklist is required', $e->getMessage());
        }
        self::assertSame('invited', (new TournamentParticipantRepository())->findForUser($tournamentId, $invitee)['status'], 'a failed accept must not leave the participant joined');
    }

    /**
     * submitTournamentDeck() lets an already-joined participant change
     * their mind before the bracket locks, and is refused once the
     * tournament has actually started.
     */
    public function testSubmitTournamentDeckAllowsEditingUntilStart(): void
    {
        $creator = $this->insertUser('editdeck_p1');
        $p2 = $this->insertUser('editdeck_p2');
        $p3 = $this->insertUser('editdeck_p3');
        $p4 = $this->insertUser('editdeck_p4');

        $decklistText = $this->powerDuelDecklistText();
        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Edit Deck Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'duel'],
            swissRoundCount: null,
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: [$p2, $p3, $p4],
            creatorDecklistText: $decklistText,
        );
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee, $decklistText);
        }

        $participants = new TournamentParticipantRepository();
        $creatorParticipant = $participants->findForUser($tournamentId, $creator);
        self::assertSame(15, count($creatorParticipant['deck_card_ids']));

        $newDeckNames = array_slice($this->fetchNonMythicCardNames(20), 5, 15);
        $newDecklistText = implode("\n", array_map(static fn (string $name): string => "1 {$name}", $newDeckNames));
        $this->tournaments->submitTournamentDeck($tournamentId, $creator, $newDecklistText);

        $updatedParticipant = $participants->findForUser($tournamentId, $creator);
        self::assertNotSame($creatorParticipant['deck_card_ids'], $updatedParticipant['deck_card_ids']);

        $this->tournaments->startTournament($tournamentId, $creator);

        $this->expectException(TournamentStateException::class);
        $this->tournaments->submitTournamentDeck($tournamentId, $creator, $decklistText);
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
        $p3 = $this->insertUser('grid_draft_p3');
        $p4 = $this->insertUser('grid_draft_p4');

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
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: [$p2, $p3, $p4],
        );
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }
        $this->tournaments->startTournament($tournamentId, $creator);

        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $match = $this->matchRepo->listForRound((int) $round1['id'])[0];
        self::assertNotNull($match['game_id'], 'a grid_draft match should still create its game up front, left drafting');

        $game = $this->fetchGame((int) $match['game_id']);
        self::assertSame('grid_draft', $game['deck_type']);
        self::assertNotNull($game['draft_match_id']);

        $p1UserId = $this->participantUserId((int) $match['participant1_id']);
        $state = $this->games->getState((int) $match['game_id'], $p1UserId);
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
        $p3 = $this->insertUser('no_pool_p3');
        $p4 = $this->insertUser('no_pool_p4');

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'No Pool Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'draft', 'deck_type' => 'grid_draft'],
            swissRoundCount: null,
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: [$p2, $p3, $p4],
        );
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($tournamentId, $invitee);
        }

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
            minParticipants: 4,
            maxParticipants: 16,
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

    /**
     * Grid Draft's own "Pod draft (once)" tournament option (issue #91
     * follow-up) end to end, mirroring testBoosterDraftFormsAPodDraftsToCompletionAndPlaysBracketMatches()'s
     * own shape: a 4-player tournament (fits in a single pod, since Grid
     * Draft's own drafting mechanic caps pods at 4 -- see
     * GridDraftPodBuilder), pod formation creating one ordinary
     * multiplayer Grid Draft game, drafting it to completion via the
     * public API (submitGridDraftPick()/submitDraftDeck()) the same way
     * an ordinary ad hoc Grid Draft game would be, the pod's own backing
     * game getting abandoned (never actually played) once every seat's
     * deck is in, the tournament auto-transitioning out of 'drafting',
     * and the resulting bracket's own first real match using each side's
     * own tournament-drafted pool (deck_type 'custom_duel' +
     * game_players.custom_deck_allowed_card_ids, never the tournament's
     * own 'grid_draft_pod' sentinel directly -- see
     * TournamentService::startMatchGame()'s own docblock).
     */
    public function testGridDraftPodFormsAPodDraftsToCompletionAndPlaysBracketMatches(): void
    {
        $userIds = [];
        foreach (['gdp_p1', 'gdp_p2', 'gdp_p3', 'gdp_p4'] as $username) {
            $userIds[] = $this->insertUser($username);
        }
        $creator = $userIds[0];
        $others = array_slice($userIds, 1);

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Grid Draft Pod Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'draft', 'deck_type' => 'grid_draft_pod', 'grid_draft_pool_source' => 'random_48'],
            swissRoundCount: null,
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: $others,
        );
        foreach ($others as $userId) {
            $this->tournaments->acceptInvite($tournamentId, $userId);
        }

        $this->tournaments->startTournament($tournamentId, $creator);

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('drafting', $state['tournament']['status']);
        self::assertCount(1, $state['pods'], '4 participants fit in a single pod');
        self::assertCount(4, $state['pods'][0]['seats']);
        $podGameId = $state['pods'][0]['game_id'];
        self::assertNotNull($podGameId, "a Grid Draft pod is backed by an ordinary Grid Draft game");

        $podGame = $this->fetchGame($podGameId);
        self::assertSame('grid_draft', $podGame['deck_type']);
        self::assertSame('waiting', $podGame['status'], "a pod's backing game is never actually started/played");

        // Drive the pod's own 4-player Grid Draft to deck-building via
        // the exact same "first available grid line" deterministic
        // policy GameServiceIntegrationTest's own multiplayer Grid Draft
        // tests use -- always a legal pick regardless of how many
        // players/refills have already happened this round.
        $draftMatchId = (int) $podGame['draft_match_id'];
        for ($i = 0; $i < 300; $i++) {
            if ($this->fetchDraftMatch($draftMatchId)['status'] !== 'drafting') {
                break;
            }
            $gridState = $this->fetchGridState($draftMatchId);
            $currentUserId = (int) $gridState['current_turn_user_id'];
            $grid = json_decode((string) $gridState['grid_card_ids'], true);
            [$axis, $index] = $this->firstAvailableGridLine($grid);
            $this->games->submitGridDraftPick($podGameId, $currentUserId, $axis, $index);
        }
        self::assertSame('deck_building', $this->fetchDraftMatch($draftMatchId)['status'], 'Grid Draft did not reach deck-building within 300 picks -- possible infinite loop');

        // Every seat submits their own full drafted pool as their deck --
        // the last submission should trigger pod completion via
        // GameService::submitDraftDeck()'s own onDraftDeckSubmitted() hook.
        foreach ($userIds as $userId) {
            $playerState = $this->games->getState($podGameId, $userId);
            $cardIds = array_column($playerState['grid_draft']['deck_building']['drafted_cards'], 'card_id');
            $this->games->submitDraftDeck($podGameId, $userId, $cardIds);
        }

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('in_progress', $state['tournament']['status'], 'drafting done -> bracket should auto-materialize');
        self::assertSame('completed', $state['pods'][0]['status']);
        self::assertSame('abandoned', $this->fetchGame($podGameId)['status'], "the pod's own backing game is retired, never played");

        $participants = new TournamentParticipantRepository();
        foreach ($userIds as $userId) {
            $participant = $participants->findForUser($tournamentId, $userId);
            self::assertNotEmpty($participant['draft_pool_card_ids'], "user {$userId} should have a drafted pool");
            self::assertNull($participant['current_deck_card_ids'], 'no deck submitted yet');
        }

        // 4 participants -> a clean 2-round bracket, both round-1 matches real.
        $round1 = $this->matchRepo->listRounds($tournamentId)[0];
        $round1Matches = $this->matchRepo->listForRound((int) $round1['id']);
        self::assertCount(2, $round1Matches);
        $match = $round1Matches[0];

        $matchGame = $this->fetchGame((int) $match['game_id']);
        self::assertSame('custom_duel', $matchGame['deck_type'], 'Grid Draft pod matches are ordinary custom_duel games under the hood');

        $p1UserId = $this->participantUserId((int) $match['participant1_id']);
        $p2UserId = $this->participantUserId((int) $match['participant2_id']);
        $p1Pool = $participants->find((int) $match['participant1_id'])['draft_pool_card_ids'];
        $p2Pool = $participants->find((int) $match['participant2_id'])['draft_pool_card_ids'];

        $p1PlayerId = $this->games->gamePlayerIdFor((int) $match['game_id'], $p1UserId);
        $allowedStmt = $this->pdo->prepare('SELECT custom_deck_allowed_card_ids FROM game_players WHERE id = :id');
        $allowedStmt->execute(['id' => $p1PlayerId]);
        self::assertNotNull($allowedStmt->fetchColumn(), 'seat should carry its own pool restriction');

        $this->games->submitCustomDuelDeck((int) $match['game_id'], $p1PlayerId, $this->decklistTextForCardIds(array_slice($p1Pool, 0, 12)));
        $this->games->submitCustomDuelDeck((int) $match['game_id'], $this->games->gamePlayerIdFor((int) $match['game_id'], $p2UserId), $this->decklistTextForCardIds(array_slice($p2Pool, 0, 12)));
        $this->games->startGame((int) $match['game_id']);

        self::assertSame('in_progress', $this->fetchGame((int) $match['game_id'])['status']);
    }

    /**
     * Grid Draft's third tournament option, "Pods with playoffs" (issue
     * #91 follow-up), end to end: 8 participants split into two pods of
     * 4 each (GridDraftPodBuilder's own even split), each pod drafts to
     * completion and plays its OWN 4-player single-elimination bracket
     * (unrelated to the other pod's own bracket at all -- different
     * tournament_rounds.pod_id) to decide that pod's own winner, then
     * those two winners draft together in one final pod (kind: 'final',
     * always exactly 2 players here since there are only 2 regular pods)
     * and play the deciding match to become the tournament champion --
     * exercising every stage onPodBracketFinished()/
     * startGridDraftPodPlayoffFinals() add on top of "Pod draft (once)"'s
     * own shared pod-forming/drafting machinery.
     */
    public function testGridDraftPodPlayoffFormsPodsPlaysEachPodsOwnBracketThenAFinalsBracket(): void
    {
        $userIds = [];
        foreach (['gdpp_p1', 'gdpp_p2', 'gdpp_p3', 'gdpp_p4', 'gdpp_p5', 'gdpp_p6', 'gdpp_p7', 'gdpp_p8'] as $username) {
            $userIds[] = $this->insertUser($username);
        }
        $creator = $userIds[0];
        $others = array_slice($userIds, 1);

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Grid Draft Playoff Cup',
            'single_elimination',
            'invite_only',
            ['format' => 'draft', 'deck_type' => 'grid_draft_pod_playoff', 'grid_draft_pool_source' => 'random_48'],
            swissRoundCount: null,
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: $others,
        );
        foreach ($others as $userId) {
            $this->tournaments->acceptInvite($tournamentId, $userId);
        }

        $this->tournaments->startTournament($tournamentId, $creator);

        $podRepo = new TournamentPodRepository();
        $regularPods = $podRepo->listPodsForTournament($tournamentId);
        self::assertCount(2, $regularPods, '8 participants split into two pods of 4');
        foreach ($regularPods as $pod) {
            self::assertSame('regular', $pod['kind']);
            self::assertSame('drafting', $pod['status']);
        }

        // Drive each regular pod's own draft to deck submission, then
        // its own bracket to completion via the same "always resign
        // whichever seat is NOT participant1" convention
        // testDoubleEliminationWithFiveParticipantsUsesLosersBracketByes()
        // already uses.
        foreach ($regularPods as $pod) {
            $podUserIds = array_map(
                fn (array $pp): int => $this->participantUserId((int) $pp['participant_id']),
                $podRepo->listPodParticipants((int) $pod['id']),
            );
            $this->driveGridDraftPodToDeckSubmission((int) $pod['game_id'], $podUserIds);

            $playingPod = $podRepo->findPod((int) $pod['id']);
            self::assertSame('playing', $playingPod['status'], "pod {$pod['pod_number']} should start playing its own bracket once drafting finishes");
            self::assertSame('abandoned', $this->fetchGame((int) $pod['game_id'])['status'], "the pod's own backing game is retired, never played");

            for ($i = 0; $i < 20; $i++) {
                $resolvedAny = false;
                foreach ($this->matchRepo->listRoundsForPod((int) $pod['id']) as $round) {
                    foreach ($this->matchRepo->listForRound((int) $round['id']) as $match) {
                        // A pod's own bracket match is an ordinary
                        // custom_duel game restricted to each side's own
                        // drafted pool -- 'waiting' on both decklists
                        // until submitted, exactly like
                        // testGridDraftPodFormsAPodDraftsToCompletionAndPlaysBracketMatches()'s
                        // own round-1 match.
                        if ($match['game_id'] !== null && $this->fetchGame((int) $match['game_id'])['status'] === 'waiting') {
                            $this->submitPodBracketDecksAndStart((int) $match['game_id'], (int) $match['participant1_id'], (int) $match['participant2_id']);
                        }
                    }
                }
                foreach ($this->matchRepo->listRoundsForPod((int) $pod['id']) as $round) {
                    foreach ($this->matchRepo->listForRound((int) $round['id']) as $match) {
                        if ($match['status'] === 'in_progress' && $match['game_id'] !== null && $this->fetchGame((int) $match['game_id'])['status'] === 'in_progress') {
                            $loserUserId = $this->participantUserId((int) $match['participant2_id']);
                            $this->decidePodBracketMatch((int) $match['game_id'], (int) $match['participant1_id'], (int) $match['participant2_id'], $loserUserId);
                            $resolvedAny = true;
                        }
                    }
                }
                if (!$resolvedAny) {
                    break;
                }
            }

            $completedPod = $podRepo->findPod((int) $pod['id']);
            self::assertSame('completed', $completedPod['status'], "pod {$pod['pod_number']} should finish its own bracket");
            self::assertNotNull($completedPod['winner_participant_id']);
        }

        // Both regular pods are done -- one new FINAL pod should now
        // exist, seating exactly the two regular pods' own winners, and
        // the tournament should still be 'drafting' (the finals' own
        // draft hasn't even started playing yet).
        $allPods = $podRepo->listPodsForTournament($tournamentId);
        self::assertCount(3, $allPods, 'two regular pods plus one final pod');
        $finalPod = current(array_filter($allPods, static fn (array $p): bool => $p['kind'] === 'final'));
        self::assertNotFalse($finalPod, 'the final pod should have been created once both regular pods finished');
        self::assertSame('drafting', $finalPod['status']);

        $finalPodParticipants = $podRepo->listPodParticipants((int) $finalPod['id']);
        self::assertCount(2, $finalPodParticipants, 'exactly the two regular pods\' own winners');
        $finalUserIds = array_map(
            fn (array $pp): int => $this->participantUserId((int) $pp['participant_id']),
            $finalPodParticipants,
        );
        $regularPodsAfter = array_values(array_filter($podRepo->listPodsForTournament($tournamentId), static fn (array $p): bool => $p['kind'] === 'regular'));
        $expectedFinalistUserIds = array_map(
            fn (array $p): int => $this->participantUserId((int) $p['winner_participant_id']),
            $regularPodsAfter,
        );
        self::assertEqualsCanonicalizing($expectedFinalistUserIds, $finalUserIds);

        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('drafting', $state['tournament']['status'], 'still drafting the finals -- never reached in_progress at all for this option');

        // Drive the final pod's own draft to deck submission -- its own
        // bracket (a single 2-player match) decides the tournament
        // outright.
        $this->driveGridDraftPodToDeckSubmission((int) $finalPod['game_id'], $finalUserIds);

        $playingFinalPod = $podRepo->findPod((int) $finalPod['id']);
        self::assertSame('playing', $playingFinalPod['status']);

        $finalRound = $this->matchRepo->listRoundsForPod((int) $finalPod['id'])[0];
        $finalMatch = $this->matchRepo->listForRound((int) $finalRound['id'])[0];
        self::assertSame('custom_duel', $this->fetchGame((int) $finalMatch['game_id'])['deck_type'], 'the finals\' own match is an ordinary custom_duel game restricted to the finalists\' own fresh pool');
        $this->submitPodBracketDecksAndStart((int) $finalMatch['game_id'], (int) $finalMatch['participant1_id'], (int) $finalMatch['participant2_id']);
        $finalWinnerUserId = $this->participantUserId((int) $finalMatch['participant1_id']);
        $finalLoserUserId = $this->participantUserId((int) $finalMatch['participant2_id']);
        $this->decidePodBracketMatch((int) $finalMatch['game_id'], (int) $finalMatch['participant1_id'], (int) $finalMatch['participant2_id'], $finalLoserUserId);

        $finalState = $this->tournaments->getState($tournamentId, $creator);
        self::assertSame('completed', $finalState['tournament']['status']);
        self::assertSame($finalWinnerUserId, (int) $finalState['tournament']['winner_user_id']);
        self::assertContains($finalWinnerUserId, $finalUserIds, 'the champion must be one of the two pod winners who reached the finals');

        $completedFinalPod = $podRepo->findPod((int) $finalPod['id']);
        self::assertSame('completed', $completedFinalPod['status']);
        self::assertSame($this->participantUserId((int) $completedFinalPod['winner_participant_id']), $finalWinnerUserId);
    }

    /** cancelTournament() records when, not just that -- deleteStaleTournaments() below needs it to judge how long a cancelled tournament has been sitting around. */
    public function testCancelTournamentRecordsCancelledAt(): void
    {
        $creator = $this->insertUser('cancel_p1');
        $p2 = $this->insertUser('cancel_p2');
        $p3 = $this->insertUser('cancel_p3');
        $p4 = $this->insertUser('cancel_p4');
        $tournamentId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'single_elimination');

        $this->tournaments->cancelTournament($tournamentId, $creator);

        $tournament = (new TournamentRepository())->find($tournamentId);
        self::assertSame('cancelled', $tournament['status']);
        self::assertNotNull($tournament['cancelled_at']);
    }

    /**
     * Reported live: "make sure tournaments get cleaned up after being
     * cancelled/completed for a week" -- folded into the existing game/
     * match cleanup cron (bin/expire_and_delete_stale_games.php) as
     * TournamentService::deleteStaleTournaments(). Backdates
     * completed_at/cancelled_at directly (there's no waiting a real week
     * in a test) to prove: a tournament stale by either route is
     * deleted, along with everything that cascades from it
     * (tournament_participants/tournament_rounds/tournament_matches);
     * one of each NOT yet 7 days stale is left alone; and a tournament
     * that's merely 'in_progress' (however old) is never touched at all,
     * matching TournamentRepository::deleteStale()'s own docblock.
     */
    public function testDeleteStaleTournamentsDeletesOldCancelledAndCompletedTournamentsOnly(): void
    {
        $creator = $this->insertUser('stale_p1');
        $p2 = $this->insertUser('stale_p2');
        $p3 = $this->insertUser('stale_p3');
        $p4 = $this->insertUser('stale_p4');

        $staleCancelledId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'single_elimination');
        $this->tournaments->cancelTournament($staleCancelledId, $creator);
        $this->pdo->prepare("UPDATE tournaments SET cancelled_at = NOW() - INTERVAL 8 DAY WHERE id = :id")->execute(['id' => $staleCancelledId]);

        $recentCancelledId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'single_elimination');
        $this->tournaments->cancelTournament($recentCancelledId, $creator);
        $this->pdo->prepare("UPDATE tournaments SET cancelled_at = NOW() - INTERVAL 1 DAY WHERE id = :id")->execute(['id' => $recentCancelledId]);

        $staleCompletedId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'single_elimination');
        (new TournamentRepository())->markCompleted($staleCompletedId, $creator);
        $this->pdo->prepare("UPDATE tournaments SET completed_at = NOW() - INTERVAL 8 DAY WHERE id = :id")->execute(['id' => $staleCompletedId]);

        $inProgressId = $this->createStandardTournament($creator, [$p2, $p3, $p4], 'single_elimination');
        foreach ([$p2, $p3, $p4] as $invitee) {
            $this->tournaments->acceptInvite($inProgressId, $invitee);
        }
        $this->tournaments->startTournament($inProgressId, $creator);
        // Fabricate an implausibly old created_at -- proves age alone,
        // absent a terminal status, is never enough to delete a
        // tournament, however long it's been running.
        $this->pdo->prepare("UPDATE tournaments SET created_at = NOW() - INTERVAL 30 DAY WHERE id = :id")->execute(['id' => $inProgressId]);

        $deletedCount = $this->tournaments->deleteStaleTournaments();

        self::assertSame(2, $deletedCount);
        self::assertNull((new TournamentRepository())->find($staleCancelledId));
        self::assertNull((new TournamentRepository())->find($staleCompletedId));
        self::assertNotNull((new TournamentRepository())->find($recentCancelledId));
        self::assertNotNull((new TournamentRepository())->find($inProgressId));

        // Cascade check: the stale, now-deleted tournaments' own
        // participants/rounds/matches must be gone too, not left
        // dangling with no parent tournament row.
        $participantCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = :id');
        $participantCountStmt->execute(['id' => $staleCancelledId]);
        self::assertSame(0, (int) $participantCountStmt->fetchColumn());

        $roundCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM tournament_rounds WHERE tournament_id = :id');
        $roundCountStmt->execute(['id' => $staleCompletedId]);
        self::assertSame(0, (int) $roundCountStmt->fetchColumn());

        // The surviving in-progress tournament's own bracket must still
        // be fully intact -- proves deleteStaleTournaments() didn't
        // cascade-delete anything it shouldn't have.
        $survivingParticipantCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = :id');
        $survivingParticipantCountStmt->execute(['id' => $inProgressId]);
        self::assertSame(4, (int) $survivingParticipantCountStmt->fetchColumn());
    }

    /**
     * Drives one Grid Draft pod's own backing game from drafting through
     * every seat's own deck submission -- shared by every regular pod
     * AND the final pod of a "Pods with playoffs" tournament (mirrors
     * testGridDraftPodFormsAPodDraftsToCompletionAndPlaysBracketMatches()'s
     * own inline version of the same loop, extracted here since the
     * playoff test above needs it three separate times).
     *
     * @param int[] $userIds every seat's own user id
     */
    private function driveGridDraftPodToDeckSubmission(int $podGameId, array $userIds): void
    {
        $podGame = $this->fetchGame($podGameId);
        $draftMatchId = (int) $podGame['draft_match_id'];
        for ($i = 0; $i < 300; $i++) {
            if ($this->fetchDraftMatch($draftMatchId)['status'] !== 'drafting') {
                break;
            }
            $gridState = $this->fetchGridState($draftMatchId);
            $currentUserId = (int) $gridState['current_turn_user_id'];
            $grid = json_decode((string) $gridState['grid_card_ids'], true);
            [$axis, $index] = $this->firstAvailableGridLine($grid);
            $this->games->submitGridDraftPick($podGameId, $currentUserId, $axis, $index);
        }
        self::assertSame('deck_building', $this->fetchDraftMatch($draftMatchId)['status'], 'Grid Draft did not reach deck-building within 300 picks -- possible infinite loop');

        foreach ($userIds as $userId) {
            $playerState = $this->games->getState($podGameId, $userId);
            $cardIds = array_column($playerState['grid_draft']['deck_building']['drafted_cards'], 'card_id');
            $this->games->submitDraftDeck($podGameId, $userId, $cardIds);
        }
    }

    /**
     * A Grid Draft "Pods with playoffs" pod-bracket match plays as an
     * ordinary custom_duel game restricted to each side's own
     * tournament_participants.draft_pool_card_ids (see startMatchGame()'s
     * own docblock) -- left 'waiting' until both seats submit a legal
     * deck, exactly like an ordinary Booster Draft/"Pod draft (once)"
     * bracket match. Submits a trivially-legal 12-card deck for each
     * side (a prefix of their own drafted pool) and starts the game.
     */
    private function submitPodBracketDecksAndStart(int $gameId, int $participant1Id, int $participant2Id): void
    {
        $participants = new TournamentParticipantRepository();
        $p1Pool = $participants->find($participant1Id)['draft_pool_card_ids'];
        $p2Pool = $participants->find($participant2Id)['draft_pool_card_ids'];

        $p1UserId = $this->participantUserId($participant1Id);
        $p2UserId = $this->participantUserId($participant2Id);
        $this->games->submitCustomDuelDeck($gameId, $this->games->gamePlayerIdFor($gameId, $p1UserId), $this->decklistTextForCardIds(array_slice($p1Pool, 0, 12)));
        $this->games->submitCustomDuelDeck($gameId, $this->games->gamePlayerIdFor($gameId, $p2UserId), $this->decklistTextForCardIds(array_slice($p2Pool, 0, 12)));
        $this->games->startGame($gameId);
    }

    private function fetchDraftMatch(int $draftMatchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM draft_matches WHERE id = :id');
        $stmt->execute(['id' => $draftMatchId]);

        return $stmt->fetch();
    }

    private function fetchGridState(int $draftMatchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM draft_grid_state WHERE draft_match_id = :id');
        $stmt->execute(['id' => $draftMatchId]);

        return $stmt->fetch();
    }

    /**
     * @param array<int, int|null> $grid a row-major grid, gridSize^2 cells
     * @return array{0:string, 1:int}
     */
    private function firstAvailableGridLine(array $grid): array
    {
        $gridSize = (int) sqrt(count($grid));

        for ($row = 0; $row < $gridSize; $row++) {
            for ($column = 0; $column < $gridSize; $column++) {
                if ($grid[$row * $gridSize + $column] !== null) {
                    return ['row', $row];
                }
            }
        }
        for ($column = 0; $column < $gridSize; $column++) {
            for ($row = 0; $row < $gridSize; $row++) {
                if ($grid[$row * $gridSize + $column] !== null) {
                    return ['column', $column];
                }
            }
        }

        throw new \LogicException('grid is fully empty -- draft should already be in deck_building by now');
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

    private function fetchGamePlayer(int $gameId, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_players WHERE game_id = :game_id AND user_id = :user_id');
        $stmt->execute(['game_id' => $gameId, 'user_id' => $userId]);

        return $stmt->fetch();
    }

    private function cardName(int $cardId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM cards WHERE id = :id');
        $stmt->execute(['id' => $cardId]);

        return (string) $stmt->fetchColumn();
    }

    /** @return string[] */
    private function fetchNonMythicCardNames(int $count): array
    {
        $stmt = $this->pdo->prepare("SELECT name FROM cards WHERE rarity != 'mythic' ORDER BY id LIMIT :count");
        $stmt->bindValue('count', $count, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * A plain-text decklist satisfying DuelDeckRules::forPreset('power')
     * (15 singleton non-mythic cards) -- every Power Duel tournament
     * test needs one of these now that a decklist is required at join
     * time (issue reported live: "the deck submission should happen
     * when the player joins the tournament").
     */
    private function powerDuelDecklistText(): string
    {
        return implode("\n", array_map(static fn (string $name): string => "1 {$name}", $this->fetchNonMythicCardNames(15)));
    }

    // -- Tournament spectator mode (issue #238) --------------------------

    public function testCreatorCanGrantAndRevokeCastAccess(): void
    {
        $creator = $this->insertUser('cast_creator1');
        $caster = $this->insertUser('cast_caster1');
        $tournamentId = $this->createStandardTournament($creator, [], 'single_elimination');

        self::assertFalse($this->tournaments->hasCastAccess($tournamentId, $caster));

        $grantee = $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_caster1');
        self::assertSame($caster, $grantee['id']);
        self::assertSame('cast_caster1', $grantee['username']);
        self::assertTrue($this->tournaments->hasCastAccess($tournamentId, $caster));

        $grants = $this->tournaments->listCastGrants($tournamentId, $creator);
        self::assertCount(1, $grants);
        self::assertSame($caster, (int) $grants[0]['user_id']);
        self::assertSame('cast_caster1', $grants[0]['username']);

        $this->tournaments->revokeCastAccess($tournamentId, $creator, $caster);
        self::assertFalse($this->tournaments->hasCastAccess($tournamentId, $caster));
        self::assertCount(0, $this->tournaments->listCastGrants($tournamentId, $creator));
    }

    /** The creator is always implicitly trusted -- never needs (or can hold) a redundant grant of their own. */
    public function testCreatorAlwaysHasCastAccessWithoutAGrant(): void
    {
        $creator = $this->insertUser('cast_creator2');
        $tournamentId = $this->createStandardTournament($creator, [], 'single_elimination');

        self::assertTrue($this->tournaments->hasCastAccess($tournamentId, $creator));

        $this->expectException(TournamentStateException::class);
        $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_creator2');
    }

    /**
     * Granting/revoking cast access stays creator-only -- it's not a
     * general tournament-management permission a mere participant gets.
     * Listing, though, is deliberately NOT creator-only any more
     * (reported live: "all users in the tournament can see who is
     * allowed to cast") -- see testCastGrantsAreVisibleToEveryoneWhoCanViewTheTournamentNotJustTheCreator()
     * for that broadened access itself.
     */
    public function testNonCreatorCannotGrantOrRevokeCastAccess(): void
    {
        $creator = $this->insertUser('cast_creator3');
        $p2 = $this->insertUser('cast_p2');
        $caster = $this->insertUser('cast_caster3');
        $tournamentId = $this->createStandardTournament($creator, [$p2], 'single_elimination');
        $this->tournaments->acceptInvite($tournamentId, $p2);

        try {
            $this->tournaments->grantCastAccess($tournamentId, $p2, 'cast_caster3');
            self::fail('Expected NotAuthorizedForTournamentException');
        } catch (NotAuthorizedForTournamentException) {
        }

        // The creator grants it properly, then a non-creator participant
        // still can't revoke it.
        $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_caster3');

        $this->expectException(NotAuthorizedForTournamentException::class);
        $this->tournaments->revokeCastAccess($tournamentId, $p2, $caster);
    }

    public function testGrantCastAccessRejectsAnUnknownUsernameOrADuplicateGrant(): void
    {
        $creator = $this->insertUser('cast_creator4');
        $caster = $this->insertUser('cast_caster4');
        $tournamentId = $this->createStandardTournament($creator, [], 'single_elimination');

        try {
            $this->tournaments->grantCastAccess($tournamentId, $creator, 'no_such_user_at_all');
            self::fail('Expected TournamentStateException');
        } catch (TournamentStateException) {
        }

        $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_caster4');
        $this->expectException(TournamentStateException::class);
        $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_caster4');
    }

    /**
     * A granted caster (not otherwise a participant) can still load this
     * invite-only tournament's own bracket -- getState()'s access check
     * now bypasses the "creator, participant, or open registration"
     * requirement for anyone hasCastAccess() trusts (see its own
     * docblock). viewer_has_cast_access lets the frontend show the
     * caster their own "Cast" buttons without them having to already be
     * the creator.
     */
    public function testGrantedCasterCanLoadAnInviteOnlyTournamentsState(): void
    {
        $creator = $this->insertUser('cast_creator5');
        $p2 = $this->insertUser('cast_p2b');
        $caster = $this->insertUser('cast_caster5');
        $tournamentId = $this->createStandardTournament($creator, [$p2], 'single_elimination');

        try {
            $this->tournaments->getState($tournamentId, $caster);
            self::fail('Expected NotAuthorizedForTournamentException before being granted cast access');
        } catch (NotAuthorizedForTournamentException) {
        }

        $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_caster5');

        $state = $this->tournaments->getState($tournamentId, $caster);
        self::assertTrue($state['viewer_has_cast_access']);

        $creatorState = $this->tournaments->getState($tournamentId, $creator);
        self::assertTrue($creatorState['viewer_has_cast_access']);

        $participantState = $this->tournaments->getState($tournamentId, $p2);
        self::assertFalse($participantState['viewer_has_cast_access']);

        // Also findable via the ordinary "mine" tournament list -- a
        // caster with no participant row of their own would otherwise
        // have no way to discover this tournament in the frontend's
        // "Tournaments" dialog at all (see TournamentRepository::
        // listForUser()'s own docblock).
        $mine = $this->tournaments->listMine($caster);
        self::assertCount(1, array_filter($mine, static fn (array $t): bool => (int) $t['id'] === $tournamentId));
    }

    /**
     * Reported live: a "no hands" cast option -- a caster granted with
     * reveal_hands=false still passes hasCastAccess() (they're still
     * allowed to watch the still-in_progress match), but castRevealsHands()
     * reports false for them specifically, unlike a full grant or the
     * creator (always true regardless of any grant).
     */
    public function testGrantCastAccessSupportsANoHandsGrant(): void
    {
        $creator = $this->insertUser('cast_nohands_creator');
        $fullCaster = $this->insertUser('cast_nohands_full');
        $noHandsCaster = $this->insertUser('cast_nohands_none');
        $tournamentId = $this->createStandardTournament($creator, [], 'single_elimination');

        $fullGrant = $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_nohands_full', true);
        self::assertTrue($fullGrant['reveal_hands']);
        $noHandsGrant = $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_nohands_none', false);
        self::assertFalse($noHandsGrant['reveal_hands']);

        self::assertTrue($this->tournaments->hasCastAccess($tournamentId, $fullCaster));
        self::assertTrue($this->tournaments->hasCastAccess($tournamentId, $noHandsCaster));
        self::assertTrue($this->tournaments->castRevealsHands($tournamentId, $fullCaster));
        self::assertFalse($this->tournaments->castRevealsHands($tournamentId, $noHandsCaster));
        self::assertTrue($this->tournaments->castRevealsHands($tournamentId, $creator), 'the creator is always fully trusted regardless of any grant');

        // A user with no grant at all has no cast access, so
        // castRevealsHands() defensively reports false for them too
        // (callers are expected to check hasCastAccess() first).
        $stranger = $this->insertUser('cast_nohands_stranger');
        self::assertFalse($this->tournaments->hasCastAccess($tournamentId, $stranger));
        self::assertFalse($this->tournaments->castRevealsHands($tournamentId, $stranger));
    }

    /**
     * Reported live: "user(s) with spectator mode access need to be
     * selected when the tournament is created" -- createTournament()'s
     * own $castGrants param grants access the moment the tournament
     * exists, same rules as grantCastAccess() (resolve by username, a
     * per-entry reveal_hands flag, the creator's own username silently
     * skipped since they're always already trusted, and duplicate
     * grantees -- by resolved user id, not raw string -- collapsed to one
     * grant rather than colliding against the unique (tournament_id,
     * user_id) constraint).
     */
    public function testCreateTournamentAcceptsCastGrantsAtCreationTime(): void
    {
        $creator = $this->insertUser('cast_create_creator');
        $fullCaster = $this->insertUser('cast_create_full');
        $noHandsCaster = $this->insertUser('cast_create_none');

        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Cast Grants At Creation',
            'single_elimination',
            'invite_only',
            ['format' => 'standard'],
            null,
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: [],
            castGrants: [
                ['username' => 'cast_create_full', 'reveal_hands' => true],
                ['username' => 'cast_create_none', 'reveal_hands' => false],
                // Duplicate of the first entry -- must not throw a
                // duplicate-key error, just collapse to the one grant.
                ['username' => 'cast_create_full', 'reveal_hands' => true],
                // The creator's own username -- silently skipped, same
                // as $inviteUserIds already does for the creator.
                ['username' => 'cast_create_creator', 'reveal_hands' => true],
            ],
        );

        self::assertTrue($this->tournaments->hasCastAccess($tournamentId, $fullCaster));
        self::assertTrue($this->tournaments->castRevealsHands($tournamentId, $fullCaster));
        self::assertTrue($this->tournaments->hasCastAccess($tournamentId, $noHandsCaster));
        self::assertFalse($this->tournaments->castRevealsHands($tournamentId, $noHandsCaster));

        $grants = $this->tournaments->listCastGrants($tournamentId, $creator);
        self::assertCount(2, $grants, 'the duplicate entry and the creator-username entry should not have produced extra rows');
    }

    public function testCreateTournamentRejectsAnUnknownCastGranteeUsername(): void
    {
        $creator = $this->insertUser('cast_create_bad_creator');

        $this->expectException(TournamentStateException::class);
        $this->tournaments->createTournament(
            $creator,
            'Cast Grants Bad Username',
            'single_elimination',
            'invite_only',
            ['format' => 'standard'],
            null,
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: [],
            castGrants: [['username' => 'no_such_user_at_all_here', 'reveal_hands' => true]],
        );
    }

    /**
     * Reported live: "all users in the tournament can see who is allowed
     * to cast" -- getState() embeds the roster (cast_grants) and the
     * viewer's own reveal_hands status (viewer_cast_reveals_hands) for
     * everyone allowed to load the tournament at all (creator,
     * participant, or a caster themselves), while listCastGrants() on
     * its own is no longer creator-only. An uninvolved stranger (no
     * participant row, not a caster, invite-only) still can't load
     * either at all.
     */
    public function testCastGrantsAreVisibleToEveryoneWhoCanViewTheTournamentNotJustTheCreator(): void
    {
        $creator = $this->insertUser('cast_visible_creator');
        $p2 = $this->insertUser('cast_visible_p2');
        $caster = $this->insertUser('cast_visible_caster');
        $stranger = $this->insertUser('cast_visible_stranger');
        $tournamentId = $this->createStandardTournament($creator, [$p2], 'single_elimination');
        $this->tournaments->acceptInvite($tournamentId, $p2);
        $this->tournaments->grantCastAccess($tournamentId, $creator, 'cast_visible_caster', false);

        foreach ([$creator, $p2, $caster] as $viewerUserId) {
            $state = $this->tournaments->getState($tournamentId, $viewerUserId);
            self::assertCount(1, $state['cast_grants']);
            self::assertSame('cast_visible_caster', $state['cast_grants'][0]['username']);
            self::assertFalse($state['cast_grants'][0]['reveal_hands']);

            $grants = $this->tournaments->listCastGrants($tournamentId, $viewerUserId);
            self::assertCount(1, $grants);
        }

        self::assertTrue($this->tournaments->getState($tournamentId, $creator)['viewer_cast_reveals_hands']);
        self::assertFalse($this->tournaments->getState($tournamentId, $caster)['viewer_cast_reveals_hands']);
        self::assertNull($this->tournaments->getState($tournamentId, $p2)['viewer_cast_reveals_hands'], 'a plain participant with no cast access of their own gets null, not false');

        try {
            $this->tournaments->getState($tournamentId, $stranger);
            self::fail('Expected NotAuthorizedForTournamentException');
        } catch (NotAuthorizedForTournamentException) {
        }

        $this->expectException(NotAuthorizedForTournamentException::class);
        $this->tournaments->listCastGrants($tournamentId, $stranger);
    }

    /**
     * Reported live: "casters with access to private information (both
     * user's hands) can't play in the tournament" -- a hands-revealed
     * grant sees every match's hands, not just whoever they're
     * personally seated against, so letting them also be an active
     * participant would hand them a scouting advantage. A "no hands"
     * grant is exempt since it never reveals more than a plain
     * spectator would.
     */
    public function testGrantCastAccessRejectsFullHandsGrantToAnExistingParticipant(): void
    {
        $creator = $this->insertUser('excl_grant_creator');
        $p2 = $this->insertUser('excl_grant_p2');
        $tournamentId = $this->createStandardTournament($creator, [$p2], 'single_elimination');
        $this->tournaments->acceptInvite($tournamentId, $p2);

        try {
            $this->tournaments->grantCastAccess($tournamentId, $creator, 'excl_grant_p2', true);
            self::fail('Expected TournamentStateException');
        } catch (TournamentStateException) {
        }

        // A "no hands" grant to the same participant is fine.
        $grant = $this->tournaments->grantCastAccess($tournamentId, $creator, 'excl_grant_p2', false);
        self::assertFalse($grant['reveal_hands']);
    }

    /** Same rule, the other direction: invite() rejects someone who already holds a hands-revealed cast grant. */
    public function testInviteRejectsAnExistingFullHandsCaster(): void
    {
        $creator = $this->insertUser('excl_invite_creator');
        $fullCaster = $this->insertUser('excl_invite_full');
        $noHandsCaster = $this->insertUser('excl_invite_nohands');
        $tournamentId = $this->createStandardTournament($creator, [], 'single_elimination');
        $this->tournaments->grantCastAccess($tournamentId, $creator, 'excl_invite_full', true);
        $this->tournaments->grantCastAccess($tournamentId, $creator, 'excl_invite_nohands', false);

        try {
            $this->tournaments->invite($tournamentId, $creator, $fullCaster);
            self::fail('Expected TournamentStateException');
        } catch (TournamentStateException) {
        }

        // A "no hands" caster can still be invited to play.
        $this->tournaments->invite($tournamentId, $creator, $noHandsCaster);
        $state = $this->tournaments->getState($tournamentId, $creator);
        self::assertNotNull($state['tournament']);
    }

    /** Same rule for open-registration tournaments' own join path. */
    public function testJoinOpenTournamentRejectsAnExistingFullHandsCaster(): void
    {
        $creator = $this->insertUser('excl_open_creator');
        (new UserRepository())->setMatchmakingDiscoverable($creator, true);
        $fullCaster = $this->insertUser('excl_open_full');
        $noHandsCaster = $this->insertUser('excl_open_nohands');
        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Excl Open Cup',
            'single_elimination',
            'open',
            ['format' => 'standard'],
            null,
            minParticipants: 4,
            maxParticipants: 4,
        );
        $this->tournaments->grantCastAccess($tournamentId, $creator, 'excl_open_full', true);
        $this->tournaments->grantCastAccess($tournamentId, $creator, 'excl_open_nohands', false);

        try {
            $this->tournaments->joinOpenTournament($tournamentId, $fullCaster);
            self::fail('Expected TournamentStateException');
        } catch (TournamentStateException) {
        }

        $this->tournaments->joinOpenTournament($tournamentId, $noHandsCaster);
        self::assertTrue($this->tournaments->hasCastAccess($tournamentId, $noHandsCaster));
    }

    /** A participant who's no longer in contention for a match (declined or withdrawn) is still eligible for a full-hands cast grant. */
    public function testDeclinedOrWithdrawnParticipantCanStillBeGrantedFullHandsCastAccess(): void
    {
        $creator = $this->insertUser('excl_declined_creator');
        $declinedUser = $this->insertUser('excl_declined_user');
        $withdrawnUser = $this->insertUser('excl_withdrawn_user');
        $tournamentId = $this->createStandardTournament($creator, [$declinedUser, $withdrawnUser], 'single_elimination');
        $this->tournaments->declineInvite($tournamentId, $declinedUser);
        $this->tournaments->acceptInvite($tournamentId, $withdrawnUser);
        $this->tournaments->withdraw($tournamentId, $withdrawnUser);

        $declinedGrant = $this->tournaments->grantCastAccess($tournamentId, $creator, 'excl_declined_user', true);
        self::assertTrue($declinedGrant['reveal_hands']);
        $withdrawnGrant = $this->tournaments->grantCastAccess($tournamentId, $creator, 'excl_withdrawn_user', true);
        self::assertTrue($withdrawnGrant['reveal_hands']);
    }

    /** createTournament()'s own $castGrants param enforces the same rule against its own $inviteUserIds in the same call. */
    public function testCreateTournamentRejectsOverlappingInviteAndFullHandsCastGrant(): void
    {
        $creator = $this->insertUser('excl_create_creator');
        $overlapUser = $this->insertUser('excl_create_overlap');

        try {
            $this->tournaments->createTournament(
                $creator,
                'Excl Overlap Cup',
                'single_elimination',
                'invite_only',
                ['format' => 'standard'],
                null,
                minParticipants: 4,
                maxParticipants: 16,
                inviteUserIds: [$overlapUser],
                castGrants: [['username' => 'excl_create_overlap', 'reveal_hands' => true]],
            );
            self::fail('Expected TournamentStateException');
        } catch (TournamentStateException) {
        }

        // The same overlap with a "no hands" grant is fine.
        $tournamentId = $this->tournaments->createTournament(
            $creator,
            'Excl Overlap Cup 2',
            'single_elimination',
            'invite_only',
            ['format' => 'standard'],
            null,
            minParticipants: 4,
            maxParticipants: 16,
            inviteUserIds: [$overlapUser],
            castGrants: [['username' => 'excl_create_overlap', 'reveal_hands' => false]],
        );
        self::assertTrue($this->tournaments->hasCastAccess($tournamentId, $overlapUser));
        self::assertFalse($this->tournaments->castRevealsHands($tournamentId, $overlapUser));
    }
}
