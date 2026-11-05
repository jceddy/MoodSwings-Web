<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Reverie: "After playing this mood -- You may put a card from the discard pile on the bottom of the deck. If you
 * do, draw a card. While in play -- Each time you play another mood, you may do this." The after-playing half is
 * Reminiscence's ('discard_card_id'); the while-in-play half reacts to the owner playing another mood
 * ('reverie_discard_card_id', riding with that play), and skips a card that has already left the discard pile.
 */
final class ReverieEffect extends AbstractMoodEffect
{
    private const REACTION_KEY = 'reverie_discard_card_id';

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->int('discard_card_id');
        if ($discardCardId === null) {
            return;
        }
        if (!in_array($discardCardId, $state->discardPile(), true)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in the discard pile");
        }

        $this->cycle($state, $playerId, $discardCardId);
    }

    public function reactToAnotherPlay(BoardState $state, int $reactorCardId, int $playedCardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->has(self::REACTION_KEY)) {
            return;
        }

        $discardCardId = $choices->requireInt(self::REACTION_KEY);
        if (in_array($discardCardId, $state->discardPile(), true)) {
            $this->cycle($state, $playerId, $discardCardId);
        }
    }

    private function cycle(BoardState $state, int $playerId, int $discardCardId): void
    {
        $state->moveDiscardToBottomOfDeck($discardCardId);
        $state->drawCard($playerId);
    }
}
