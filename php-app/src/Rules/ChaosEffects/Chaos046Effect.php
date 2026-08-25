<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_046 (common, after_playing): "You may put one of your moods into
 * your hand. If you do, look at the top card of your deck - you may put
 * that card on the bottom of your deck." The "put it on the bottom"
 * choice is implemented as a draw immediately followed by a bottom-of-
 * deck move (there's no bare "peek and reorder without drawing" primitive
 * -- see BoardState's own zone-movement methods) -- functionally
 * identical to a genuine peek-and-reorder since both happen within this
 * one synchronous step, before any state is ever serialized back out.
 */
final class Chaos046Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $returnCardId = $choices->int('return_mood_card_id');
        if ($returnCardId === null) {
            return;
        }
        if (!in_array($returnCardId, array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($playerId)), true)) {
            throw new InvalidChoiceException("Card {$returnCardId} is not one of your moods");
        }
        $state->moveInPlayToHand($returnCardId);

        if (!$choices->bool('bottom_top_card')) {
            return;
        }
        $topCardId = $state->drawCard($playerId);
        if ($topCardId !== null) {
            $state->moveHandToBottomOfDeck($playerId, $topCardId);
        }
    }
}
