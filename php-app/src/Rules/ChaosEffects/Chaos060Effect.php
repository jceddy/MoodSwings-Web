<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_060 (rare, after_playing): "You may choose one: Put up to two
 * cards from the discard pile on the bottom of the deck, then draw that
 * many cards. -- The winner of the current round wins two rounds instead
 * of one (each losing player still draws only one card)." The second
 * option reuses the exact 'awardsExtraWin' effectState tag Corruption's
 * own identical printed text already relies on -- see
 * GameService::hasExtraWinMarker()/consumeExtraWinMarker() -- no
 * chaos-specific scoring hook needed.
 */
final class Chaos060Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->string('mode');
        if ($mode === null) {
            return;
        }

        if ($mode === 'extra_win') {
            $state->setEffectState($cardId, 'awardsExtraWin', true);

            return;
        }

        if ($mode !== 'recycle') {
            throw new InvalidChoiceException("'{$mode}' is not a valid mode");
        }

        $chosenCardIds = array_unique($choices->ints('discard_card_ids'));
        if (count($chosenCardIds) > 2) {
            throw new InvalidChoiceException('Choose at most two discard pile cards');
        }
        foreach ($chosenCardIds as $chosenCardId) {
            if (!$state->isInDiscardPile($chosenCardId)) {
                throw new InvalidChoiceException("Card {$chosenCardId} is not in the discard pile");
            }
        }
        foreach ($chosenCardIds as $chosenCardId) {
            $state->moveDiscardToBottomOfDeck($chosenCardId);
        }
        foreach ($chosenCardIds as $ignored) {
            $state->drawCard($playerId);
        }
    }
}
