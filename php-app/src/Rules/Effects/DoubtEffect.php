<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Doubt: "After playing this mood, you may reveal any number of cards
 * from your hand and put them on the bottom of the deck, then draw that
 * many cards. During the next round, players can't play moods that share
 * a color with any of the revealed cards." The revealed cards' colors are
 * tagged on Doubt itself via the well-known 'bannedColors' effectState
 * key; MoodPlayService checks BoardState::bannedColorsThisRound(), which
 * only counts a mood's 'bannedColors' for the single round immediately
 * after its own 'playedInRound' (the same tag every mood gets stamped
 * with when it enters play), so the ban naturally expires after that one
 * round without needing anything to explicitly clear it.
 */
final class DoubtEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $revealedIds = array_unique($choices->ints('reveal_card_ids'));
        if ($revealedIds === []) {
            return;
        }

        foreach ($revealedIds as $revealedId) {
            if (!$state->isInHand($playerId, $revealedId)) {
                throw new InvalidChoiceException("Card {$revealedId} is not in player {$playerId}'s hand");
            }
        }

        $colors = [];
        foreach ($revealedIds as $revealedId) {
            $colors[] = $state->colorOf($revealedId);
            $state->moveHandToBottomOfDeck($playerId, $revealedId);
        }
        foreach ($revealedIds as $ignored) {
            $state->drawCard($playerId);
        }

        $state->setEffectState($cardId, 'bannedColors', array_values(array_unique($colors)));
    }
}
