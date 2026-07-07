<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Bliss: "To play this card, discard a card from your hand. If you
 * can't do that, you can't play this card. While in play, score each of
 * your moods that shares a color with the discarded card two extra
 * times." Unconditional, like Exhilaration -- resolved by
 * RoundScorer::score() checking for effect_key 'bliss'. The discarded
 * card's color has to be captured now, since payToPlayCost() always runs
 * before Bliss exists as a MoodInPlay to attach effectState to normally
 * -- see BoardState::stagePrePlayEffectState(). Unlike Exhilaration's
 * cost (a mood already in play, which Exhilaration itself never is at
 * this point), Bliss's cost draws from the same hand Bliss is still
 * sitting in when this runs, so both methods here explicitly exclude
 * Bliss's own card id -- otherwise "discard a card from your hand" could
 * be paid by discarding Bliss itself, which would then crash when
 * MoodPlayService tries to move a card that's no longer in hand into
 * play.
 */
final class BlissEffect extends AbstractMoodEffect
{
    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        return array_diff($state->hand($playerId), [$cardId]) !== [];
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->requireInt('discard_card_id');
        if ($discardCardId === $cardId) {
            throw new InvalidChoiceException('Bliss cannot pay its own cost by discarding itself');
        }
        if (!$state->isInHand($playerId, $discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in player {$playerId}'s hand");
        }

        $color = $state->colorOf($discardCardId);
        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->stagePrePlayEffectState($cardId, 'blissColor', $color);
    }
}
