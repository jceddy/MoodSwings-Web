<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RequiresOpponentDecision;

/**
 * Pride: "After playing this mood, you may choose a player with more
 * moods than you. If you do, you may keep playing additional moods this
 * turn until you have as many moods as the chosen player." The gap
 * between the two players' mood counts (Pride itself already counted, as
 * it's in play by the time this resolves) is computed once and granted as
 * that many unconditional extra plays -- equivalent to "until you match
 * them", since the chosen player can't play anything more this turn to
 * widen the gap further.
 *
 * "More moods than you" can't be evaluated correctly at the moment an
 * ordinary up-front choices panel would be filled out -- Pride is still
 * sitting in hand at that point, one mood short of what its own comparison
 * needs, so a player who currently has strictly more moods but would only
 * tie once Pride itself counts would incorrectly look like a valid target.
 * Modeled as a RequiresOpponentDecision instead, targeting the *acting*
 * player themselves (the same self-targeting shape BetrayalEffect/
 * InstabilityEffect already use for their own "not in play yet" problem) --
 * unlike those two, Pride's own card was never the thing that couldn't be
 * offered; it's the *candidate list of players* that needs Pride already
 * counted to be computed correctly. Deferring lets pendingDecisionsFor()
 * build that list against the real post-play board, with the qualifying
 * players sent down explicitly as `candidate_player_ids` -- so the
 * frontend never has to duplicate this class's own mood-count arithmetic
 * (see fieldOptions() in game.js), the way it previously had to for the
 * static choice_fields entry this replaces.
 */
final class PrideEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY = 'target_player_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $ownMoodCount = count($state->moodsOwnedBy($playerId));
        $candidatePlayerIds = array_values(array_filter(
            $state->activePlayerOrder(),
            fn (int $otherPlayerId) => $otherPlayerId !== $playerId
                && count($state->moodsOwnedBy($otherPlayerId)) > $ownMoodCount,
        ));

        if ($candidatePlayerIds === []) {
            return [];
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $playerId,
                decisionType: 'pride_choose_player',
                field: [
                    'key' => self::KEY,
                    'type' => 'player',
                    'candidate_player_ids' => $candidatePlayerIds,
                    'required' => false,
                    'label' => 'Player with more moods in play than you',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        if (!isset($answers[self::KEY])) {
            return;
        }

        $chosenPlayerId = $answers[self::KEY]->int(self::KEY);
        if ($chosenPlayerId === null) {
            return;
        }

        if (!in_array($chosenPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$chosenPlayerId} is not a valid player");
        }

        $gap = count($state->moodsOwnedBy($chosenPlayerId)) - count($state->moodsOwnedBy($playerId));
        if ($gap <= 0) {
            throw new InvalidChoiceException("Player {$chosenPlayerId} does not have more moods than player {$playerId}");
        }

        $state->grantExtraPlay($gap, sourceCardId: $cardId);
    }
}
