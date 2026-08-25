<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_050 (rare, after_playing): "You may choose an opponent's mood. If you do, reveal the top card of your deck - if that card's color matches the chosen mood's color, put the chosen mood into your hand, otherwise put that mood into your opponent's hand and draw a card." */
final class Chaos050Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->int('mood_card_id');
        if ($targetCardId === null) {
            return;
        }
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        $opponentId = $state->ownerOf($targetCardId);
        if ($opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
            throw new InvalidChoiceException('Only an opponent\'s mood can be chosen');
        }

        $deck = $state->deck($playerId);
        if ($deck === []) {
            return;
        }
        $topCardId = $deck[0];
        $state->recordRevealedCard($topCardId);

        if ($state->colorOf($topCardId) === $state->colorOf($targetCardId)) {
            $state->moveInPlayToPlayersHand($targetCardId, $playerId);

            return;
        }

        $state->moveInPlayToPlayersHand($targetCardId, $opponentId);
        $state->drawCard($playerId);
    }
}
