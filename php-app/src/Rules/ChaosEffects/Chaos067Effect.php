<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_067 (rare, after_playing): "You may choose another player. If you
 * do, that player reveals a card from their hand and puts it into your
 * hand. You may play it as an additional mood this turn." The other
 * player's own "reveals a card" is simplified to a uniformly-random hand
 * card (see ChaosMoodEffect's own docblock); the resulting grant is
 * restricted to that specific card, mirroring Effects/IntimidationEffect.php's
 * own 'specific_card_ids' restriction shape.
 */
final class Chaos067Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->int('target_player_id');
        if ($targetPlayerId === null) {
            return;
        }
        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true) || $targetPlayerId === $playerId) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }

        $hand = $state->hand($targetPlayerId);
        if ($hand === []) {
            return;
        }

        $revealedCardId = $hand[array_rand($hand)];
        $state->giveHandCardToPlayer($targetPlayerId, $playerId, $revealedCardId);
        $state->grantExtraPlay(1, ['type' => 'specific_card_ids', 'values' => [$revealedCardId]], sourceCardId: $cardId);
    }
}
