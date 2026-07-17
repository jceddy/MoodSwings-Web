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
 * after-scoring effects don't happen.)" Sets the well-known
 * 'skipScoringThisRound' marker -- see
 * GameService::hasSkipScoringMarker()/skipScoringAndAdvance() -- alongside
 * 'oneTimeFirstPlayerOverride' (see BoardState::firstPlayerOverride()) to
 * record who goes first. This is a distinct key from Honor's
 * 'firstPlayerOverride', since Awe's choice only covers the very next
 * round rather than persisting for as long as it stays in play --
 * skipScoringAndAdvance() clears it once consumed.
 */
final class AweEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($chosenPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$chosenPlayerId} is not a valid player");
        }

        $state->setEffectState($cardId, 'oneTimeFirstPlayerOverride', $chosenPlayerId);
        $state->setEffectState($cardId, 'skipScoringThisRound', true);
    }
}
