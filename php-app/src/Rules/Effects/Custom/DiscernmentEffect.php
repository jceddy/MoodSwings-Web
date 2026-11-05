<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Discernment: "After playing this mood -- Choose odd or even. You may reveal any number of cards from your
 * hand with a number of the chosen type in their top right corners and put them on the bottom of the deck,
 * then draw that many cards. (0 is even.)" The parity is required, the cards optional; every card named
 * must really be in your hand and of that type.
 */
final class DiscernmentEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $parity = CustomEffectSupport::requireParity($choices->requireString('parity'));
        $revealedIds = array_values(array_unique($choices->ints('reveal_card_ids')));

        foreach ($revealedIds as $revealedId) {
            if (!$state->isInHand($playerId, $revealedId)) {
                throw new InvalidChoiceException("Card {$revealedId} is not in player {$playerId}'s hand");
            }
            if (!CustomEffectSupport::hasParity($state, $revealedId, $parity)) {
                throw new InvalidChoiceException("Card {$revealedId} does not have an {$parity} number in its top right corner");
            }
        }

        foreach ($revealedIds as $revealedId) {
            $state->recordRevealedCard($revealedId);
            $state->moveHandToBottomOfDeck($playerId, $revealedId);
        }
        foreach ($revealedIds as $ignored) {
            $state->drawCard($playerId);
        }
    }
}
