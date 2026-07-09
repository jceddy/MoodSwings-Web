<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Pride: "After playing this mood, you may choose a player with more
 * moods than you. If you do, you may keep playing additional moods this
 * turn until you have as many moods as the chosen player." The gap
 * between the two players' mood counts (Pride itself already counted, as
 * it's in play by the time afterPlaying runs) is computed once, up
 * front, and granted as that many unconditional extra plays --
 * equivalent to "until you match them", since the chosen player can't
 * play anything more this turn to widen the gap further.
 */
final class PrideEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->has('target_player_id')) {
            return;
        }

        $chosenPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($chosenPlayerId, $state->playerOrder(), true)) {
            throw new InvalidChoiceException("Player {$chosenPlayerId} is not a valid player");
        }

        $gap = count($state->moodsOwnedBy($chosenPlayerId)) - count($state->moodsOwnedBy($playerId));
        if ($gap <= 0) {
            throw new InvalidChoiceException("Player {$chosenPlayerId} does not have more moods than player {$playerId}");
        }

        $state->grantExtraPlay($gap, sourceCardId: $cardId);
    }
}
