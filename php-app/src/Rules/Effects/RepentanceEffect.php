<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Repentance: "After playing this mood, you may choose a number. If you
 * do, suppress all other moods with the chosen value. They remain
 * suppressed until the end of this round." The first effect to use
 * 'end_of_round' suppression (see BoardState::clearEndOfRoundSuppressions())
 * rather than a source-tied one.
 *
 * The printed text has no upper bound of its own -- MAX_VALUE is only a
 * practical default matching the highest printed base value in the
 * catalog (see CardChoiceSchema's own 'allow_extra_values' docblock), not
 * a real rule. A mood whose value scales with some count (Euphoria's "+1
 * per mood in play, including itself," Vanity/Sloth/Sadness/Envy, etc.)
 * can genuinely exceed 12 given enough moods/hand/discard cards -- and
 * since Repentance is already in play by the time this runs, $qualifying
 * below already reflects that. So a value above MAX_VALUE is legal
 * exactly when it's not just a made-up number: some mood currently in
 * play actually has it.
 */
final class RepentanceEffect extends AbstractMoodEffect
{
    private const MIN_VALUE = 0;
    private const MAX_VALUE = 12;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $value = $choices->int('value');
        if ($value === null) {
            return;
        }

        $qualifying = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId !== $cardId && $state->valueOf($mood->cardId) === $value) {
                $qualifying[] = $mood->cardId;
            }
        }

        if ($value < self::MIN_VALUE || ($value > self::MAX_VALUE && $qualifying === [])) {
            throw new InvalidChoiceException('Repentance requires choosing a value between 0 and 12, or a value some mood currently in play actually has');
        }

        foreach ($qualifying as $targetCardId) {
            $state->suppress($targetCardId, 'end_of_round');
        }
    }
}
