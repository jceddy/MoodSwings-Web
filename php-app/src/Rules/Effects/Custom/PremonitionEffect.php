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
 * Premonition: "After playing this mood -- Draw up to three cards, then put that many cards from your hand on the
 * bottom of the deck. You may play an additional mood this turn." How many to draw (0-3) is the play-time choice;
 * the draw happens, then a follow-up decision asks which cards to put back -- as many as were actually drawn (a
 * short deck draws fewer, and puts back that many) -- chosen from the whole hand, so the new cards can go straight
 * back. Always a 1-for-1. The extra play is granted outright (the "may" covers whether it is used).
 */
final class PremonitionEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const MAX_DRAW = 3;
    private const KEY = 'bottom_card_ids';
    private const DRAWN = 'premonitionDrawn';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        // Nothing to ask up front -- resolveDecisions() draws first, then asks which cards to put back.
        return [];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (isset($answers[self::KEY])) {
            return $this->putBack($state, $cardId, $playerId, $answers[self::KEY]->ints(self::KEY));
        }

        $wanted = $choices->requireInt('draw_count');
        if ($wanted < 0 || $wanted > self::MAX_DRAW) {
            throw new InvalidChoiceException('Premonition draws up to three cards');
        }

        $drawn = 0;
        for ($i = 0; $i < $wanted; $i++) {
            if ($state->drawCard($playerId) !== null) {
                $drawn++;
            }
        }

        $state->grantExtraPlay(1, [], sourceCardId: $cardId);

        if ($drawn === 0) {
            return [];
        }

        if ($state->isInPlay($cardId)) {
            $state->setEffectState($cardId, self::DRAWN, $drawn);
        }

        return [new PendingDecisionRequest(
            key: self::KEY,
            targetPlayerId: $playerId,
            decisionType: 'premonition_put_back',
            field: [
                'key' => self::KEY,
                'type' => 'hand_card',
                'multi' => true,
                'required' => true,
                'count' => ['min' => $drawn, 'max' => $drawn],
                'label' => "Premonition: put exactly {$drawn} card" . ($drawn === 1 ? '' : 's') . ' from your hand on the bottom of the deck',
            ],
        )];
    }

    /**
     * @param int[] $cardIds
     * @return PendingDecisionRequest[]
     */
    private function putBack(BoardState $state, int $cardId, int $playerId, array $cardIds): array
    {
        $expected = $state->isInPlay($cardId) ? $state->effectState($cardId, self::DRAWN) : null;
        if (!is_int($expected)) {
            throw new InvalidChoiceException('Premonition has no pending put-back to resolve');
        }
        if (count($cardIds) !== $expected || count(array_unique($cardIds)) !== $expected) {
            throw new InvalidChoiceException("Premonition requires choosing exactly {$expected} different cards");
        }
        foreach ($cardIds as $handCardId) {
            if (!$state->isInHand($playerId, $handCardId)) {
                throw new InvalidChoiceException("Card {$handCardId} is not in player {$playerId}'s hand");
            }
        }

        foreach ($cardIds as $handCardId) {
            $state->moveHandToBottomOfDeck($playerId, $handCardId);
        }
        $state->clearEffectState($cardId, self::DRAWN);

        return [];
    }
}
