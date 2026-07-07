<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Arrogance: "After playing this mood, you may choose an opponent. If
 * you do, they choose one of their white or blue moods and it becomes
 * yours. After this mood is no longer in play, give the mood you took
 * back to them if you still have it." The opponent's own choice among
 * their qualifying moods is public board information with no hidden
 * asymmetry, so it's resolved the same way Instability resolves an
 * opponent's informed choice: a genuine random pick from the qualifying
 * set, since the engine doesn't support pausing mid-play for another
 * player's input. The taken mood is tagged with the well-known
 * 'returnsToOwnerIfCardLeavesPlay' effectState key -- see
 * BoardState's leave-play cascade -- which also records who currently
 * holds it, so "if you still have it" is honored even if the acting
 * player gives the mood away again before Arrogance itself leaves play.
 */
final class ArroganceEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['white', 'blue'];

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->has('opponent_player_id')) {
            return;
        }

        $opponentId = $choices->requireInt('opponent_player_id');
        if (!in_array($opponentId, $state->playerOrder(), true)) {
            throw new InvalidChoiceException("Player {$opponentId} is not a valid player");
        }
        if ($opponentId === $playerId) {
            throw new InvalidChoiceException('Arrogance must target an opponent');
        }

        $qualifying = array_values(array_filter(
            $state->moodsOwnedBy($opponentId),
            fn ($mood) => in_array($state->colorOf($mood->cardId), self::QUALIFYING_COLORS, true),
        ));
        if ($qualifying === []) {
            return;
        }

        $chosen = $qualifying[array_rand($qualifying)];
        $state->giveInPlayToPlayer($chosen->cardId, $playerId);
        $state->setEffectState($chosen->cardId, 'returnsToOwnerIfCardLeavesPlay', [
            'sourceCardId' => $cardId,
            'ownerId' => $opponentId,
            'heldByPlayerId' => $playerId,
        ]);
    }
}
