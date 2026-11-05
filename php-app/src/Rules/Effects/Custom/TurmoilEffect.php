<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Turmoil: "After playing this mood -- Choose odd or even and choose a player. That player reveals their
 * hand, puts each card from it with a number of the chosen type in its top right corner on the bottom of
 * the deck, then draws that many cards. (0 is even.)" Forced on the chosen player (yourself included):
 * the whole hand is revealed, every matching card is cycled, no decision is needed from them.
 */
final class TurmoilEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $parity = CustomEffectSupport::requireParity($choices->requireString('parity'));
        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }

        $hand = $state->hand($targetPlayerId);
        foreach ($hand as $handCardId) {
            $state->recordRevealedCard($handCardId);
        }

        $cycled = array_values(array_filter($hand, static fn (int $id): bool => CustomEffectSupport::hasParity($state, $id, $parity)));
        foreach ($cycled as $handCardId) {
            $state->moveHandToBottomOfDeck($targetPlayerId, $handCardId);
        }
        foreach ($cycled as $ignored) {
            $state->drawCard($targetPlayerId);
        }
    }
}
