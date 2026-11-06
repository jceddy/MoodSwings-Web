<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\CardChoiceSchema;
use MoodSwings\Rules\DelegatesAfterPlaying;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Optimism: "After playing this mood -- You may choose one of your other moods. If you do, that mood's after-playing effect
 * happens an additional time." The chosen mood is the optional 'target_mood_id' (one of your other moods in play, any round's);
 * the repeat runs that mood's effect as that mood, with fresh choices from you (MoodPlayService, see DelegatesAfterPlaying).
 * A mood with no after-playing effect gives nothing.
 */
final class OptimismEffect extends AbstractMoodEffect implements DelegatesAfterPlaying
{
    public function delegatedInvocation(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): ?array
    {
        $targetCardId = $choices->int('target_mood_id');
        if ($targetCardId === null) {
            return null;
        }
        if ($targetCardId === $cardId || !$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
            throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s other moods in play");
        }

        $row = $state->catalogRow($state->effectiveCardId($targetCardId));
        if (!$row['hasAfterPlaying']) {
            return null;
        }

        return [
            'effectKey' => $row['effectKey'],
            'sourceCardId' => $targetCardId,
            'fields' => CardChoiceSchema::afterPlayingFields($row['effectKey']),
        ];
    }
}
