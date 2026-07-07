<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Guile: "To play this card, discard two cards from your hand. If you
 * can't do that, you can't play this card. After playing this mood,
 * choose one of your opponents' moods. It becomes yours." The first card
 * in the engine with a mandatory "to play" cost -- canPayToPlayCost()
 * checks feasibility (are there two *other* hand cards to discard, since
 * Guile itself is still in hand at this point); payToPlayCost() then
 * validates the actual choice.
 */
final class GuileEffect extends AbstractMoodEffect
{
    private const DISCARD_COUNT = 2;

    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        $otherHandCards = array_filter($state->hand($playerId), static fn (int $id) => $id !== $cardId);

        return count($otherHandCards) >= self::DISCARD_COUNT;
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardIds = array_unique($choices->ints('discard_card_ids'));
        if (count($discardCardIds) !== self::DISCARD_COUNT) {
            throw new InvalidChoiceException('Guile requires choosing exactly two distinct cards to discard');
        }

        foreach ($discardCardIds as $discardCardId) {
            if ($discardCardId === $cardId || !$state->isInHand($playerId, $discardCardId)) {
                throw new InvalidChoiceException("Card {$discardCardId} is not a valid card to discard for Guile's cost");
            }
        }

        foreach ($discardCardIds as $discardCardId) {
            $state->moveHandToDiscard($playerId, $discardCardId);
        }
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        if ($state->ownerOf($targetCardId) === $playerId) {
            throw new InvalidChoiceException("Guile can only target an opponent's mood");
        }

        $state->giveInPlayToPlayer($targetCardId, $playerId);
    }
}
