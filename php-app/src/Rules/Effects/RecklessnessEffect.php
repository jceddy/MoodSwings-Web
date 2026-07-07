<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Recklessness: "After playing this mood, you may take one of your
 * opponents' moods. If you do, after scoring, give the mood you took back
 * to them if you still have it. While in play, after scoring, put this
 * mood on the bottom of the deck and draw a card." The unconditional
 * "while in play" hook is tagged on itself via the well-known
 * 'afterScoring' effectState key at play time (rather than needing a
 * separate "for every mood currently in play" scan each round); the
 * optional taken mood is tagged with 'returnsToOwnerAfterScoring' the same
 * way Betrayal's is -- see GameService::applyAfterScoringHooks().
 */
final class RecklessnessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->setEffectState($cardId, 'afterScoring', ['action' => 'bottom_and_draw', 'condition' => 'always']);

        if (!$choices->has('target_mood_id')) {
            return;
        }

        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }

        $originalOwnerId = $state->ownerOf($targetCardId);
        if ($originalOwnerId === $playerId) {
            throw new InvalidChoiceException('Recklessness can only target an opponent\'s mood');
        }

        $state->giveInPlayToPlayer($targetCardId, $playerId);
        $state->setEffectState($targetCardId, 'returnsToOwnerAfterScoring', $originalOwnerId);
    }
}
