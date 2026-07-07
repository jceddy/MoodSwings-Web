<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Corruption: "After playing this mood, you may choose one: put up to two
 * cards from the discard pile on the bottom of the deck then draw that
 * many cards, or the winner of the current round wins two rounds instead
 * of one (each losing player still draws only one card)." The second
 * option is unconditional on the round itself (not on who played
 * Corruption or who wins), tagged via the well-known 'awardsExtraWin'
 * effectState key -- see GameService::consumeExtraWinMarker(), which
 * doubles game_rounds.wins_awarded for this round regardless of who ends
 * up winning it.
 */
final class CorruptionEffect extends AbstractMoodEffect
{
    private const MAX_CYCLED = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->has('mode')) {
            return;
        }

        match ($choices->requireString('mode')) {
            'cycle' => $this->cycleDiscardCards($state, $playerId, $choices),
            'double_win' => $state->setEffectState($cardId, 'awardsExtraWin', true),
            default => throw new InvalidChoiceException("Corruption's mode must be 'cycle' or 'double_win'"),
        };
    }

    private function cycleDiscardCards(BoardState $state, int $playerId, PlayerChoices $choices): void
    {
        $targets = array_unique($choices->ints('discard_card_ids'));
        if (count($targets) > self::MAX_CYCLED) {
            throw new InvalidChoiceException('Corruption can cycle at most two cards from the discard pile');
        }

        foreach ($targets as $targetCardId) {
            if (!$state->isInDiscardPile($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in the discard pile");
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveDiscardToBottomOfDeck($targetCardId);
        }
        foreach ($targets as $ignored) {
            $state->drawCard($playerId);
        }
    }
}
