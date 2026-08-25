<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_036 (uncommon, after_playing): "You may reveal any number of
 * cards from your hand and put them on the bottom of the deck, then draw
 * that many cards. During the next round, players can't play moods that
 * share a color with any of the revealed cards." Reuses the exact same
 * 'bannedColors'/BoardState::bannedColorsThisRound() mechanism Doubt's own
 * identically-worded printed ability already relies on -- see
 * BoardState::bannedColorsThisRound()'s own docblock.
 */
final class Chaos036Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $revealedCardIds = array_unique($choices->ints('hand_card_ids'));
        if ($revealedCardIds === []) {
            return;
        }

        $colors = [];
        foreach ($revealedCardIds as $revealedCardId) {
            if (!$state->isInHand($playerId, $revealedCardId)) {
                throw new InvalidChoiceException("Card {$revealedCardId} is not in your hand");
            }
            $state->recordRevealedCard($revealedCardId);
            $colors[] = $state->colorOf($revealedCardId);
        }

        foreach ($revealedCardIds as $revealedCardId) {
            $state->moveHandToBottomOfDeck($playerId, $revealedCardId);
        }
        foreach ($revealedCardIds as $ignored) {
            $state->drawCard($playerId);
        }

        $state->setEffectState($cardId, 'bannedColors', array_values(array_unique($colors)));
    }
}
