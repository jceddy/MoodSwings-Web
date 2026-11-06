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
 * Admiration: "After playing this mood -- You may choose a mood in the discard pile. If you do, that mood's after-playing effect
 * happens as though you had just played it." The pile mood is the optional 'target_discard_card_id'; its effect runs with fresh
 * choices from you (MoodPlayService, see DelegatesAfterPlaying), as an effect of the Admiration just played -- the card's ruling
 * for a pile mood that refers to itself ("this mood" is the mood you just played). The pile mood stays in the pile. A mood with
 * no after-playing effect gives nothing.
 */
final class AdmirationEffect extends AbstractMoodEffect implements DelegatesAfterPlaying
{
    public function delegatedInvocation(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): ?array
    {
        $pileCardId = $choices->int('target_discard_card_id');
        if ($pileCardId === null) {
            return null;
        }
        if (!in_array($pileCardId, $state->discardPile(), true)) {
            throw new InvalidChoiceException("Card {$pileCardId} is not in the discard pile");
        }

        $row = $state->catalogRow($pileCardId);
        if (!$row['hasAfterPlaying']) {
            return null;
        }

        return [
            'effectKey' => $row['effectKey'],
            'sourceCardId' => $cardId,
            'fields' => CardChoiceSchema::afterPlayingFields($row['effectKey']),
        ];
    }
}
