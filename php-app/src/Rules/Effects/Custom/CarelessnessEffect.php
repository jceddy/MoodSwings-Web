<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/**
 * Carelessness: "After playing this mood -- Put the top two cards of the deck into the discard pile." Public
 * and mandatory; with fewer than two cards left it moves what there is. In a game with separate decks
 * (a duel) it is the owner's own deck.
 */
final class CarelessnessEffect extends AbstractMoodEffect
{
    private const CARDS = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        for ($i = 0; $i < self::CARDS; $i++) {
            if ($state->moveDeckTopToDiscard($playerId) === null) {
                return;
            }
        }
    }
}
