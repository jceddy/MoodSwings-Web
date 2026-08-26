<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_006 (uncommon, after_playing): "Choose a mood. Its player puts it on the bottom of the deck and draws a card." Any mood is a legal target, including this card's own or the active player's own. */
final class Chaos006Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->requireInt('mood_card_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }

        $ownerId = $state->ownerOf($targetCardId);
        $state->moveInPlayToBottomOfDeck($targetCardId);
        $state->drawCard($ownerId);
    }
}
