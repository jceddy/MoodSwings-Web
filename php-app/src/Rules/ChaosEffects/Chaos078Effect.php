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
 * chaos_078 (common, after_playing): "Choose any number of players. Each
 * chosen player discards a card from their hand." Which players get
 * chosen is the ACTING player's own choice (`target_player_ids`,
 * unchanged, read synchronously like any ordinary field); which specific
 * card each chosen player then discards is THEIR OWN real hidden-
 * information decision (see ChaosRequiresOpponentDecision) -- a maintainer
 * ruling reversing this class's own original design (issue #405
 * follow-up, reported live for chaos_086's identically-shaped case: "the
 * other player should choose the card from their hand to give to me -- it
 * should not be random"), mirroring Effects/ConfusionEffect.php's own
 * "every targeted player answers their own field" shape, scoped to just
 * the chosen subset rather than every active player. A chosen player with
 * an empty hand simply discards nothing.
 */
final class Chaos078Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY_PREFIX = 'discarded_card_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $chosenPlayerIds = array_unique($choices->ints('target_player_ids'));
        foreach ($chosenPlayerIds as $targetPlayerId) {
            if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
                throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
            }
        }

        $requests = [];
        foreach ($chosenPlayerIds as $targetPlayerId) {
            if ($state->hand($targetPlayerId) === []) {
                continue;
            }

            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $targetPlayerId,
                targetPlayerId: $targetPlayerId,
                decisionType: 'chaos_078_discard_card',
                field: [
                    'key' => self::KEY_PREFIX . $targetPlayerId,
                    'type' => 'hand_card',
                    'required' => true,
                    'label' => 'Choose a card from your hand to discard',
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $chosenPlayerIds = array_unique($choices->ints('target_player_ids'));

        foreach ($chosenPlayerIds as $targetPlayerId) {
            $key = self::KEY_PREFIX . $targetPlayerId;
            if (!isset($answers[$key])) {
                continue;
            }

            $discardedCardId = $answers[$key]->requireInt($key);
            if (!$state->isInHand($targetPlayerId, $discardedCardId)) {
                throw new InvalidChoiceException("Card {$discardedCardId} is not in player {$targetPlayerId}'s hand");
            }

            $state->moveHandToDiscard($targetPlayerId, $discardedCardId);
        }

        return [];
    }
}
