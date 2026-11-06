<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Haunting: "After playing this mood -- You may discard any number of cards from your hand. If you do, you may play that
 * many additional moods from the discard pile on your next turn." Joy's banked play, one per discarded card, each only
 * spendable on a mood from the discard pile (BoardState::bankExtraPlay() with a 'source' => 'discard' restriction) --
 * any mood in the pile, other players' included. The banked plays are turned into that turn's grants when it starts (so
 * Restraint or Rudeness leave them banked) -- but only if Haunting is still its player's mood at that moment
 * ('requiresSourceAtTurnStart', checked by GameService::computeFreshGrants()); once the turn has begun they are spent
 * normally even if Haunting leaves play during it.
 */
final class HauntingEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardIds = array_values(array_unique($choices->ints('discard_card_ids')));
        foreach ($discardIds as $discardId) {
            if (!$state->isInHand($playerId, $discardId)) {
                throw new InvalidChoiceException("Card {$discardId} is not in player {$playerId}'s hand");
            }
        }

        foreach ($discardIds as $discardId) {
            $state->moveHandToDiscard($playerId, $discardId);
            $state->bankExtraPlay($playerId, $cardId, ['source' => 'discard', 'requiresSourceAtTurnStart' => true]);
        }
    }
}
