<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Longing: "After playing this mood -- You may discard a card from your hand. If you do, put up to two cards from
 * the discard pile into your hand." The discard is the "you may"; the picks come with it (a discard with no pile
 * card named is allowed -- it just gets nothing back). The discarded card is in the pile by the time you pick, so
 * it can be one of the two; every named card must be a different pile card.
 */
final class LongingEffect extends AbstractMoodEffect
{
    private const MAX_PICKS = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $handCardId = $choices->int('hand_card_id');
        if ($handCardId === null) {
            return;
        }
        if (!$state->isInHand($playerId, $handCardId)) {
            throw new InvalidChoiceException("Card {$handCardId} is not in player {$playerId}'s hand");
        }

        $pickIds = array_values(array_unique($choices->ints('discard_card_ids')));
        if (count($pickIds) > self::MAX_PICKS) {
            throw new InvalidChoiceException('Longing puts up to two cards from the discard pile into your hand');
        }
        foreach ($pickIds as $pickId) {
            if ($pickId !== $handCardId && !in_array($pickId, $state->discardPile(), true)) {
                throw new InvalidChoiceException("Card {$pickId} is not in the discard pile");
            }
        }

        $state->moveHandToDiscard($playerId, $handCardId);

        foreach ($pickIds as $pickId) {
            $state->moveDiscardToHand($playerId, $pickId);
        }
    }
}
