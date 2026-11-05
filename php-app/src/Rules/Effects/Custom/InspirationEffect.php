<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RequiresOpponentDecision;

/**
 * Inspiration: "After playing this mood -- Reveal the top three cards of the deck. Put one of them into
 * your hand and the rest on the bottom of the deck in a random order. You may play that card as an
 * additional mood this turn." The reveal is public (it's in the log); the player picks which card they
 * keep, the rest go to the bottom shuffled, and the extra play is restricted to the kept card. With fewer
 * than three cards in the deck it reveals what there is; with an empty deck it does nothing. In a duel it
 * is the owner's own deck.
 */
final class InspirationEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY = 'chosen_card_id';
    private const REVEALED = 3;

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $top = array_slice($state->deck($playerId), 0, self::REVEALED);
        if ($top === []) {
            return [];
        }

        foreach ($top as $revealedId) {
            $state->recordRevealedCard($revealedId);
        }

        return [CustomEffectSupport::revealedCardRequest(
            self::KEY, $playerId, $top,
            'Inspiration: choose one of the revealed cards to put into your hand (the rest go to the bottom of the deck; you may play it as an additional mood)',
        )];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (!isset($answers[self::KEY])) {
            return [];
        }

        $chosenCardId = $answers[self::KEY]->requireInt(self::KEY);
        $top = array_slice($state->deck($playerId), 0, self::REVEALED);
        if (!in_array($chosenCardId, $top, true)) {
            throw new InvalidChoiceException("Card {$chosenCardId} is not one of the revealed cards");
        }

        $state->moveDeckCardToHand($playerId, $chosenCardId);

        $rest = array_values(array_diff($top, [$chosenCardId]));
        shuffle($rest);
        foreach ($rest as $restCardId) {
            $state->moveDeckCardToBottom($playerId, $restCardId);
        }

        $state->grantExtraPlay(1, ['type' => 'specific_card_ids', 'values' => [$chosenCardId]], sourceCardId: $cardId);

        return [];
    }
}
