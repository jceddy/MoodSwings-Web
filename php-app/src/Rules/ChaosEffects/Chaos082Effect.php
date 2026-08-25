<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_082 (uncommon, after_playing): "You may choose an opponent. If
 * you do, they choose one of their white or blue moods and it becomes
 * yours. After this mood is no longer in play, give the mood you took
 * back to them (if you still have it)." Identical printed text to
 * Effects/ArroganceEffect.php -- reuses the exact same
 * 'returnsToOwnerIfCardLeavesPlay' effectState tag and
 * BoardState::cascadeMoodLeavingPlay() mechanism, with the opponent's own
 * choice among their qualifying moods simplified to a uniformly-random
 * one (see ChaosMoodEffect's own docblock).
 */
final class Chaos082Effect extends AbstractChaosMoodEffect
{
    private const QUALIFYING_COLORS = ['white', 'blue'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $opponentId = $choices->int('opponent_player_id');
        if ($opponentId === null) {
            return;
        }
        if (!in_array($opponentId, $state->activePlayerOrder(), true) || $opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
            throw new InvalidChoiceException("Player {$opponentId} is not a valid opponent");
        }

        $qualifying = array_filter(
            $state->moodsOwnedBy($opponentId),
            fn ($mood) => in_array($state->colorOf($mood->cardId), self::QUALIFYING_COLORS, true),
        );
        if ($qualifying === []) {
            return;
        }

        $chosenCardId = array_rand($qualifying);
        $state->giveInPlayToPlayer($chosenCardId, $playerId);
        $state->setEffectState($chosenCardId, 'returnsToOwnerIfCardLeavesPlay', [
            'sourceCardId' => $cardId,
            'ownerId' => $opponentId,
            'heldByPlayerId' => $playerId,
        ]);
    }
}
