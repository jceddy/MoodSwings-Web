<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Paranoia: "After playing this mood, you may choose a player with one
 * or more cards in their hand. If you do, that player reveals a random
 * card from their hand and puts it on the bottom of the deck, then you
 * draw a card." The rules specify a genuinely random card, so this
 * resolves fully here -- see Cruelty/Indecisiveness for the same idea
 * applied to moods in play instead of a hand. The reveal itself only
 * happens within this one request/response, and the card then goes
 * somewhere nobody -- not even the acting player -- can see again (the
 * bottom of the deck), so recordRevealedCard() logs it for GameService to
 * fold into this play's own game_events row; otherwise every player other
 * than whoever answered the "which player" choice would have no way to
 * ever find out what got revealed.
 */
final class ParanoiaEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->int('target_player_id');
        if ($targetPlayerId === null) {
            return;
        }

        $hand = $state->hand($targetPlayerId);
        if ($hand === []) {
            throw new InvalidChoiceException("Player {$targetPlayerId} has no cards in hand");
        }

        $randomCardId = $hand[array_rand($hand)];
        $state->recordRevealedCard($randomCardId);
        $state->moveHandToBottomOfDeck($targetPlayerId, $randomCardId);
        $state->drawCard($playerId);
    }
}
