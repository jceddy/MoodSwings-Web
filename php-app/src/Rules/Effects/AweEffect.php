<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Awe: "After playing this mood, there is no scoring this round. No one
 * wins or loses this round. You choose which player goes first next
 * round. (No one draws a card or gets Hurt Feelings for this round, and
 * after-scoring effects don't happen.)" Marks the round via
 * BoardState::markSkipScoringThisRound() -- see
 * GameService::hasSkipScoringMarker()/skipScoringAndAdvance() and that
 * method's own docblock for why this is round-level state rather than
 * effectState tagged on Awe's own card: this choice is already fully
 * locked in the instant Awe resolves (an "after playing" trigger, not a
 * "while in play" ability like Honor's own similarly-named
 * firstPlayerOverride), so it needs to survive Awe itself leaving play
 * before the round it was played in actually finishes scoring.
 */
final class AweEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($chosenPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$chosenPlayerId} is not a valid player");
        }

        $state->markSkipScoringThisRound($chosenPlayerId);
    }
}
