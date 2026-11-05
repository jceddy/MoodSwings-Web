<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * "After playing this mood -- You may put all [of your opponents' | other] moods with a value of
 * N or less/more into the discard pile | their players' hands." A yes/no "you may" over a fixed
 * target set, like Rage (the qualifying set is judged on current values, before anything moves).
 * Belligerence: opponents' moods <= 2 to the discard pile. Chagrin: every other mood >= 4 to its
 * player's hand.
 */
final class BulkMoodMoveEffect extends AbstractMoodEffect
{
    /**
     * @param string $choiceKey the PlayerChoices bool that opts in
     * @param bool $opponentsOnly true: only moods owned by an opponent; false: every other mood
     * @param bool $atMost true: value <= $threshold; false: value >= $threshold
     * @param string $destination 'discard' or 'hand'
     */
    public function __construct(
        private readonly string $choiceKey,
        private readonly bool $opponentsOnly,
        private readonly bool $atMost,
        private readonly int $threshold,
        private readonly string $destination,
    ) {
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->bool($this->choiceKey)) {
            return;
        }

        $opponents = $this->opponentsOnly ? CustomEffectSupport::opponentIds($state, $playerId) : [];
        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId === $cardId) {
                continue;
            }
            if ($this->opponentsOnly && !in_array($mood->ownerId, $opponents, true)) {
                continue;
            }
            $value = $state->valueOf($mood->cardId);
            if ($this->atMost ? $value <= $this->threshold : $value >= $this->threshold) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            if ($this->destination === 'hand') {
                $state->moveInPlayToHand($targetCardId);
            } else {
                $state->moveInPlayToDiscard($targetCardId);
            }
        }
    }
}
