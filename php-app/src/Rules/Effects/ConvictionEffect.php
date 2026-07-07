<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** Conviction: "After playing this mood, choose a mood. Its player puts it on the bottom of the deck and draws a card." Any mood in play is a legal target, including Conviction itself or one of the acting player's own moods. */
final class ConvictionEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }

        $owner = $state->ownerOf($targetCardId);
        $state->moveInPlayToBottomOfDeck($targetCardId);
        $state->drawCard($owner);
    }
}
