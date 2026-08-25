<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_071 (uncommon, after_playing): "You may choose a player with one or more cards in their hand. If you do, that player reveals a random card from their hand and puts it on the bottom of the deck, then you draw a card." */
final class Chaos071Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->int('target_player_id');
        if ($targetPlayerId === null) {
            return;
        }
        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }

        $hand = $state->hand($targetPlayerId);
        if ($hand === []) {
            throw new InvalidChoiceException("Player {$targetPlayerId} has no cards in hand");
        }

        $revealedCardId = $hand[array_rand($hand)];
        $state->recordRevealedCard($revealedCardId);
        $state->moveHandToBottomOfDeck($targetPlayerId, $revealedCardId);
        $state->drawCard($playerId);
    }
}
