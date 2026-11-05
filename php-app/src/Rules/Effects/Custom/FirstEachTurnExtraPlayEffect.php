<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Adrenaline ("The first time you play a red mood each turn, you may play an additional mood this turn") and
 * Affinity ("The first time each turn you play a mood with the same number in its top right corner as another of
 * your moods, you may play an additional mood this turn"): a while-in-play reaction to the owner playing ANOTHER
 * mood (the same hook as Validation; the mood played isn't itself the reactor). The extra play is granted
 * outright; once granted, the reactor remembers it fired this turn ('reactionUsedThisTurn', lifted when a turn or
 * round begins) so a second matching play doesn't grant another. $trigger is 'red_mood' or 'matching_number'.
 */
final class FirstEachTurnExtraPlayEffect extends AbstractMoodEffect
{
    public const RED_MOOD = 'red_mood';
    public const MATCHING_NUMBER = 'matching_number';

    public function __construct(private readonly string $trigger)
    {
    }

    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void
    {
        if ($state->effectState($reactorCardId, 'reactionUsedThisTurn') === true || !$this->triggers($state, $playedCardId, $playerId)) {
            return;
        }

        $state->setEffectState($reactorCardId, 'reactionUsedThisTurn', true);
        $state->grantExtraPlay(sourceCardId: $reactorCardId);
    }

    private function triggers(BoardState $state, int $playedCardId, int $playerId): bool
    {
        if ($this->trigger === self::RED_MOOD) {
            return $state->colorOf($playedCardId) === 'red';
        }

        $number = CustomEffectSupport::printedNumber($state, $playedCardId);
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($mood->cardId !== $playedCardId && CustomEffectSupport::printedNumber($state, $mood->cardId) === $number) {
                return true;
            }
        }

        return false;
    }
}
