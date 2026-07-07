<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Suspicion: "After playing this mood, choose any number of players.
 * Each chosen player discards a card from their hand." Which specific
 * card gets discarded isn't specified as either player's informed choice
 * (unlike e.g. Compulsion, where the target deliberately picks what to
 * hand over) -- resolved here as a random card from each chosen player's
 * hand, consistent with Paranoia/Cruelty/Indecisiveness.
 */
final class SuspicionEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenPlayers = array_unique($choices->ints('player_ids'));

        foreach ($chosenPlayers as $chosenPlayerId) {
            if ($state->hand($chosenPlayerId) === []) {
                throw new InvalidChoiceException("Player {$chosenPlayerId} has no cards in hand");
            }
        }

        foreach ($chosenPlayers as $chosenPlayerId) {
            $hand = $state->hand($chosenPlayerId);
            $randomCardId = $hand[array_rand($hand)];
            $state->moveHandToDiscard($chosenPlayerId, $randomCardId);
        }
    }
}
