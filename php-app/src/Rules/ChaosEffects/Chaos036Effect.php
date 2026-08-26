<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosRequiresOpponentDecision;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_036 (uncommon, after_playing): "You may reveal any number of
 * cards from your hand and put them on the bottom of the deck, then draw
 * that many cards. During the next round, players can't play moods that
 * share a color with any of the revealed cards." Reuses the exact same
 * 'bannedColors'/BoardState::bannedColorsThisRound() mechanism Doubt's own
 * identically-worded printed ability already relies on -- see
 * BoardState::bannedColorsThisRound()'s own docblock.
 *
 * Deferred as a self-targeted `ChaosRequiresOpponentDecision` -- see
 * ChaosDiscardValueToBoostSelfEffect's own docblock for the full "why"
 * (issue #405 follow-up: hand cards chosen up front can go stale if the
 * host card's own printed effect changes the acting player's hand first).
 */
final class Chaos036Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'hand_card_ids';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $playerId,
                decisionType: 'chaos_036_reveal_cards',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'multi' => true,
                    'required' => false,
                    'count' => ['zero_ok' => true],
                    'label' => 'Hand cards to reveal, bottom-deck and redraw (bans their colors next round)',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $revealedCardIds = array_unique(($answers[self::KEY] ?? null)?->ints(self::KEY) ?? []);
        if ($revealedCardIds === []) {
            return [];
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

        return [];
    }
}
