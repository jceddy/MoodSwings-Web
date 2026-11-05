<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Dominance: "While in play -- Each time you play another red mood, you may put one of your opponents' moods with
 * a value lower than that mood's into the discard pile." The optional target rides with the triggering play
 * ('dominance_target_mood_id', see CardChoiceSchema::REACTIONS). "Lower than that mood's" is compared when the
 * trigger resolves -- after the played mood's own after-playing effect -- using both current values. If the
 * target is already gone (a second Dominance sharing the same choice), there is nothing left to do.
 */
final class DominanceEffect extends AbstractMoodEffect
{
    private const KEY = 'dominance_target_mood_id';

    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->has(self::KEY) || $state->colorOf($playedCardId) !== 'red') {
            return;
        }

        $targetCardId = $choices->requireInt(self::KEY);
        if (!$state->isInPlay($targetCardId)) {
            return;
        }
        if (!in_array($state->ownerOf($targetCardId), CustomEffectSupport::opponentIds($state, $playerId), true)) {
            throw new InvalidChoiceException("Dominance can only target one of your opponents' moods");
        }
        if ($state->isInPlay($playedCardId) && $state->valueOf($targetCardId) >= $state->valueOf($playedCardId)) {
            throw new InvalidChoiceException("Dominance can only target a mood with a value lower than the red mood you played");
        }

        $state->moveInPlayToDiscard($targetCardId);
    }
}
