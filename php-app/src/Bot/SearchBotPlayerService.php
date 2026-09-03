<?php

declare(strict_types=1);

namespace MoodSwings\Bot;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RoundScorer;
use Throwable;

/**
 * The "Tactical Bot" tier (issue #419): a search-based alternative to
 * BotPlayerService's own fixed heuristic for the single highest-value
 * decision a practice bot makes -- which card (and which targeting) to
 * play on its own turn. Everything else (decision answers, team-decision
 * proposals, draft picks) is deliberately left on the existing heuristic
 * bot -- see this class's own scope notes below.
 *
 * The approach: flat Monte Carlo action evaluation with UCB1 bandit
 * allocation over the ROOT decision's own candidate actions (from
 * LegalChoiceEnumerator), NOT a full growing-tree MCTS with expansion at
 * every subsequent decision point. For each candidate root action, a
 * rollout: (1) determinizes the hidden information (Determinizer) --
 * opponent hands and every deck's undrawn cards get reshuffled into a
 * fresh, fair sample rather than read from the real, actually-hidden
 * board; (2) applies the candidate action (fully resolving any pending
 * decisions it triggers via the EXISTING heuristic bot, used here purely
 * as an opponent/self-continuation model, not a strategy choice); (3)
 * plays out the rest of the current round -- the acting player's own
 * remaining plays this turn, then every other player's entire turn, in
 * order -- entirely via that same heuristic bot; (4) scores the resulting
 * position with RoundScorer. UCB1 spends more of the time budget
 * re-sampling the actions that look most promising so far, same idea a
 * multi-armed bandit uses, and simply returns whichever action has the
 * best average outcome once the time budget runs out (an "anytime"
 * design -- always has SOME valid, already-legal answer ready the moment
 * it's asked for one, even if the budget is tiny).
 *
 * Deliberate v1 scope cuts, each safe (never illegal, never a crash) but
 * worth knowing about when judging how "smart" this actually is:
 * - Only the ROOT decision is searched. Every subsequent decision within
 *   the SAME rollout -- including the acting player's own further grants
 *   this same turn, and every other player's/team's entire turn -- is
 *   answered by the existing heuristic bot, not recursively searched.
 * - Scoring a rollout's resulting position always treats every
 *   Enthusiasm/Passion "would you like the bonus" decision as declined
 *   (RoundScorer::score()'s own well-supported default for "no entry
 *   yet"), rather than modeling that end-of-round negotiation -- which
 *   lives entirely in GameService, not the pure Rules layer this class
 *   operates in. This slightly UNDER-values a board with Enthusiasm/
 *   Passion in play; it never over-values one.
 * - A simulated FUTURE turn (any player besides the one taking the ROOT
 *   action) always starts with just the ordinary base allowance --
 *   Hurt Feelings' extra play and any already-banked Generosity/Joy play
 *   are not folded in, since computing "who currently holds Hurt
 *   Feelings this round" isn't reconstructable from a live BoardState
 *   alone (it's carried over from the PREVIOUS round's own final scores
 *   -- see GameService's own computeFreshGrants() callers). The acting
 *   player's OWN root turn is unaffected by this -- its real grants are
 *   already loaded from the actual game before this class is even asked
 *   to choose.
 * - Team format's own turn-order propose/confirm negotiation
 *   (BotPlayerService::chooseTeamDecisionProposal()) is untouched --
 *   still the existing simple heuristic. A simulated team-format rollout
 *   approximates turn order as simply activePlayerOrder()'s own seat
 *   order rather than replicating that negotiation.
 * - Reward is "my side's total score minus the single highest-scoring
 *   OTHER side's total score" (teammates' scores pooled via isTeammate()
 *   for a team format; every other player treated as their own
 *   1-player "side" otherwise) -- a reasonable relative-value proxy for
 *   guiding action comparisons, not a claim of exact multi-agent
 *   game-theoretic optimality.
 */
final class SearchBotPlayerService
{
    /** UCB1's own exploration-vs-exploitation balance -- sqrt(2) is the textbook default, and there's no evidence yet this game's own reward scale needs a different one. */
    private const UCB1_EXPLORATION_CONSTANT = 1.4142135623730951;

    /** Never let a single simulate() call run unchecked -- a genuine engine bug (an infinite pending-decision loop) should surface as a bounded, recoverable failure for this one rollout, not a hung request. */
    private const MAX_PLAYS_PER_ROLLOUT = 60;

    /** How often (in iterations) the deadline is actually checked -- microtime() on every single iteration would itself become measurable overhead across many thousands of cheap rollouts. */
    private const DEADLINE_CHECK_INTERVAL = 4;

    /** See playAndFullyResolve()'s own docblock. */
    private const MAX_PENDING_DECISION_ROUNDS = 30;

    /**
     * $enumerator has no default (unlike $determinizer) since it must
     * share the exact same $heuristic instance passed here -- PHP
     * doesn't allow a promoted constructor property's own default value
     * to reference another parameter, so a caller with no reason to
     * inject its own can build one via defaultEnumeratorFor($heuristic)
     * below.
     */
    public function __construct(
        private readonly MoodPlayService $plays,
        private readonly RoundScorer $scorer,
        private readonly BotPlayerService $heuristic,
        private readonly LegalChoiceEnumerator $enumerator,
        private readonly Determinizer $determinizer = new Determinizer(),
    ) {
    }

    public static function defaultEnumeratorFor(BotPlayerService $heuristic): LegalChoiceEnumerator
    {
        return new LegalChoiceEnumerator($heuristic);
    }

    /**
     * Same shape as BotPlayerService::chooseAction() (null = pass) plus a
     * wall-clock time budget -- always returns SOME already-legal answer
     * before the budget expires (falling back to the plain heuristic bot
     * outright if there's nothing worth comparing, or if every single
     * rollout attempt somehow failed), so a caller never has to treat
     * this any differently than the existing heuristic bot's own
     * synchronous chooseAction() -- see BotGameServiceIntegration's own
     * background-job wiring for why the CALLER is what actually makes
     * this asynchronous, not this method itself.
     *
     * @param int[] $playableCardIds
     * @return ?array{card_id: int, choices: array<string, mixed>}
     */
    public function chooseAction(BoardState $state, array $playableCardIds, int $botGamePlayerId, float $timeBudgetSeconds): ?array
    {
        $deadline = microtime(true) + max(0.0, $timeBudgetSeconds);

        $rootActions = $this->enumerator->enumerate($state, $playableCardIds, $botGamePlayerId);
        $rootActions[] = null; // "pass" is always itself a candidate

        if (count($rootActions) <= 1) {
            return $rootActions[0] ?? null;
        }

        $visits = array_fill(0, count($rootActions), 0);
        $totals = array_fill(0, count($rootActions), 0.0);

        $iteration = 0;
        do {
            $index = $this->selectArm($visits, $totals);
            $reward = $this->simulate($state, $botGamePlayerId, $rootActions[$index]);
            $visits[$index]++;
            $totals[$index] += $reward;
            $iteration++;
        } while ($iteration % self::DEADLINE_CHECK_INTERVAL !== 0 || microtime(true) < $deadline);

        return $rootActions[$this->bestArmByAverage($visits, $totals)];
    }

    /** @param int[] $visits @param float[] $totals */
    private function selectArm(array $visits, array $totals): int
    {
        foreach ($visits as $index => $count) {
            if ($count === 0) {
                return $index; // UCB1 always tries every arm once before comparing averages
            }
        }

        $totalVisits = array_sum($visits);
        $bestIndex = 0;
        $bestScore = -INF;
        foreach ($visits as $index => $count) {
            $average = $totals[$index] / $count;
            $score = $average + self::UCB1_EXPLORATION_CONSTANT * sqrt(log($totalVisits) / $count);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    /** @param int[] $visits @param float[] $totals */
    private function bestArmByAverage(array $visits, array $totals): int
    {
        $bestIndex = 0;
        $bestAverage = -INF;
        foreach ($visits as $index => $count) {
            if ($count === 0) {
                continue; // never actually sampled (budget expired mid-round-robin) -- can't trust it over one that was
            }
            $average = $totals[$index] / $count;
            if ($average > $bestAverage) {
                $bestAverage = $average;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    /** @param ?array{card_id: int, choices: array<string, mixed>} $rootAction */
    private function simulate(BoardState $state, int $botGamePlayerId, ?array $rootAction): float
    {
        $sim = $this->determinizer->determinize($state, $botGamePlayerId);

        try {
            $playsUsed = 0;
            if ($rootAction !== null) {
                $this->playAndFullyResolve($sim, $botGamePlayerId, $rootAction['card_id'], $rootAction['choices']);
                $playsUsed += $this->finishTurnViaHeuristic($sim, $botGamePlayerId, $playsUsed);
            }

            $this->playoutRemainingPlayers($sim, $botGamePlayerId, $playsUsed);
        } catch (Throwable) {
            // An illegal/broken variant (or a genuine engine bug tripped
            // by a hypothetical position no real game would ever reach)
            // -- never let one bad rollout crash the whole search; just
            // make sure it never LOOKS like a winning line either.
            return -INF;
        }

        return $this->rewardFor($sim, $botGamePlayerId, $this->scorer->score($sim));
    }

    /**
     * Finishes $playerId's own turn (after their first play of it has
     * already happened, whether that was the root action under
     * evaluation or an earlier iteration of this same loop) by
     * repeatedly asking the heuristic bot for another play until it
     * passes or runs out of legal ones -- exactly mirroring
     * GameService::advanceAutomatedTurns()'s own single-player-turn loop,
     * just against this rollout's own cloned/determinized BoardState
     * instead of a live game.
     */
    private function finishTurnViaHeuristic(BoardState $sim, int $playerId, int $playsAlreadyUsedThisRollout): int
    {
        $playsUsed = $playsAlreadyUsedThisRollout;
        while ($sim->playsRemaining() > 0) {
            if ($playsUsed >= self::MAX_PLAYS_PER_ROLLOUT) {
                break;
            }

            $playableCardIds = $this->candidatePlayCardIds($sim, $playerId);
            $action = $playableCardIds !== [] ? $this->heuristic->chooseAction($sim, $playableCardIds, $playerId) : null;
            if ($action === null) {
                break; // passes -- forfeits whatever's left of this turn, same as a real pass() would
            }

            $this->playAndFullyResolve($sim, $playerId, $action['card_id'], $action['choices']);
            $playsUsed++;
        }

        return $playsUsed;
    }

    /**
     * Every player still to come this round AFTER $botGamePlayerId's own
     * turn -- activePlayerOrder() from $botGamePlayerId's own position
     * onward, WITHOUT wrapping back to whoever already went earlier this
     * round, exactly mirroring GameService::advanceTurn()'s own "walk
     * forward, round ends once nobody's left" algorithm. Each one starts
     * with just the ordinary base allowance -- see this class's own
     * docblock for why Hurt Feelings/banked plays aren't folded in for a
     * simulated FUTURE turn.
     */
    private function playoutRemainingPlayers(BoardState $sim, int $botGamePlayerId, int $playsUsedSoFar): void
    {
        $order = $sim->activePlayerOrder();
        $ownIndex = array_search($botGamePlayerId, $order, true);
        if ($ownIndex === false) {
            return; // $botGamePlayerId already resigned/inactive by the time this rollout got here
        }

        $playsUsed = $playsUsedSoFar;
        foreach (array_slice($order, $ownIndex + 1) as $playerId) {
            if ($playsUsed >= self::MAX_PLAYS_PER_ROLLOUT) {
                break;
            }
            $sim->startTurn($playerId, hasHurtFeelings: false);
            $playsUsed = $this->finishTurnViaHeuristic($sim, $playerId, $playsUsed);
        }
    }

    /**
     * Applies one play and fully drains whatever pending-decision chain
     * it triggers (opponent decisions, Duplicity's own "repeat again?"
     * offer, chained follow-up rounds) via the heuristic bot's own
     * chooseDecisionAnswer() -- the exact same dispatch
     * GameService::advanceAutomatedTurns() already uses for a real,
     * live pending decision targeting a bot, just threaded through in
     * one call instead of pausing across separate requests (this
     * simulation has no "separate request" to pause across -- see
     * PlayResult's own docblock on why every field resolvePendingDecisions()
     * needs is already sitting right there on the PlayResult it's
     * resolving).
     */
    private function playAndFullyResolve(BoardState $sim, int $playerId, int $cardId, array $choices): void
    {
        $topLevelChoices = new PlayerChoices($choices);
        $result = $this->plays->playMood($sim, $playerId, $cardId, $topLevelChoices);

        // A real pending chain is always finite (bounded by opponent
        // count for an opponent decision, by how many Duplicity-effective
        // sources are in play for a repeat offer) -- this cap exists
        // purely so a genuine engine bug reachable only from some
        // hypothetical, determinized position a real game would never
        // actually produce fails ONE rollout instead of hanging the
        // whole search.
        for ($round = 0; $result->isPending; $round++) {
            if ($round >= self::MAX_PENDING_DECISION_ROUNDS) {
                throw new \RuntimeException('Pending-decision chain exceeded the safety cap -- treating this rollout as broken');
            }

            $answers = [];
            foreach ($result->pendingDecisions as $decision) {
                $answer = $this->heuristic->chooseDecisionAnswer($sim, $decision->field, $decision->targetPlayerId, $decision->decisionType);
                $answers[$decision->key] = new PlayerChoices($answer);
            }

            $result = $this->plays->resolvePendingDecisions(
                $sim,
                $result->playedCardId,
                $playerId,
                $topLevelChoices,
                $result->invocationChoices,
                $result->invocationSeq,
                $answers,
                $result->duplicityEligibleSources,
                $result->reactorCandidateCardIds,
                $result->pendingSource,
            );
        }
    }

    /** @return int[] */
    private function candidatePlayCardIds(BoardState $state, int $playerId): array
    {
        $candidates = [...$state->hand($playerId), ...$state->discardPile()];

        return array_values(array_filter($candidates, fn (int $cardId) => $this->plays->isPlayable($state, $playerId, $cardId)));
    }

    /** @param array<int, int> $scores playerId => this round's score so far */
    private function rewardFor(BoardState $sim, int $botGamePlayerId, array $scores): float
    {
        $myScore = 0;
        $bestOpponentScore = 0;
        $opponentTotals = [];

        foreach ($scores as $playerId => $score) {
            if ($playerId === $botGamePlayerId || $sim->isTeammate($botGamePlayerId, $playerId)) {
                $myScore += $score;
                continue;
            }

            // Every non-teammate is its own "side" (a duel opponent, or --
            // in a 3-4 player standard game -- each individual rival), so
            // a team format's own two-sided score naturally collapses to
            // one $opponentTotals entry, while free-for-all correctly
            // tracks each rival separately below.
            $opponentTotals[$playerId] = ($opponentTotals[$playerId] ?? 0) + $score;
        }

        foreach ($opponentTotals as $total) {
            $bestOpponentScore = max($bestOpponentScore, $total);
        }

        return (float) ($myScore - $bestOpponentScore);
    }
}
