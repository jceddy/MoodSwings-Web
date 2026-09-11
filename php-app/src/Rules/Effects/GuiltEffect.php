<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Guilt: "After playing this mood, you may choose one: suppress a black
 * or red mood for as long as you have this mood, or suppress all black
 * and red moods for as long as you have this mood." The first modal
 * choice between a single source-tied suppression and a mass one --
 * optional, the same shape Contempt/Hesitation copy later in the catalog
 * (see their own docblocks).
 *
 * Reported live: "Guilt should actually have the same pattern as
 * Hesitation and Contempt" -- the 0003 catalog seed dropped "you may"
 * from Guilt's own rules_text (the same transcription mistake migrations
 * 0030/0055 already caught and fixed for Rationalization/Hesitation),
 * which made the effect look mandatory and this class was implemented to
 * match that wrongly-mandatory reading (`requireString('mode')`),
 * forcing a mode choice on every play instead of letting the player
 * decline. Corrected here to the same optional `string('mode')` +
 * early-return pattern Contempt/Hesitation already use; the catalog's own
 * rules_text is corrected in the same migration that bumps schema_version
 * for this change.
 */
final class GuiltEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['black', 'red'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->string('mode');
        if ($mode === null) {
            return;
        }

        $targets = match ($mode) {
            'single' => [$this->validatedSingleTarget($state, $choices)],
            'all' => $this->allQualifyingMoods($state),
            default => throw new InvalidChoiceException("Guilt's mode must be 'single' or 'all'"),
        };

        foreach ($targets as $targetCardId) {
            $state->suppress($targetCardId, 'while_source_in_play', $cardId);
        }
    }

    private function validatedSingleTarget(BoardState $state, PlayerChoices $choices): int
    {
        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        if (!in_array($state->colorOf($targetCardId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Guilt can only target a black or red mood');
        }

        return $targetCardId;
    }

    /** @return int[] */
    private function allQualifyingMoods(BoardState $state): array
    {
        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($state->colorOf($mood->cardId), self::QUALIFYING_COLORS, true)) {
                $targets[] = $mood->cardId;
            }
        }

        return $targets;
    }
}
