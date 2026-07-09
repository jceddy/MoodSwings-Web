<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Angst: "After playing this mood, you may put one of your blue or red
 * moods into the discard pile. If you do, you may play an additional
 * mood this turn from the discard pile." Like Bravado/Ambition, the bonus
 * play is gated behind a real cost -- but here the grant is also
 * discard-sourced, like Harmony/Grief.
 */
final class AngstEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['blue', 'red'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardMoodId = $choices->int('discard_mood_id');
        if ($discardMoodId === null) {
            return;
        }
        if (!$state->isInPlay($discardMoodId) || $state->ownerOf($discardMoodId) !== $playerId) {
            throw new InvalidChoiceException("Card {$discardMoodId} is not one of player {$playerId}'s moods in play");
        }
        if (!in_array($state->colorOf($discardMoodId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Angst requires discarding a blue or red mood');
        }

        $state->moveInPlayToDiscard($discardMoodId);
        $state->grantExtraPlay(1, ['source' => 'discard'], sourceCardId: $cardId);
    }
}
