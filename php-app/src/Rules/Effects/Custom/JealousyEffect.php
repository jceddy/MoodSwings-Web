<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\TurnStartEffect;

/**
 * Jealousy: "At the start of each of your turns, each opponent with more moods than you puts one of
 * their moods into the discard pile." Which opponents qualify is fixed when the trigger fires; each one
 * chooses their own mood to lose.
 */
final class JealousyEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const KEY_PREFIX = 'discard_mood_id_';

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        $requests = [];
        $myCount = count($state->moodsOwnedBy($playerId));
        foreach (CustomEffectSupport::opponentIds($state, $playerId) as $opponentId) {
            if (count($state->moodsOwnedBy($opponentId)) > $myCount) {
                $requests[] = TurnStartSupport::ownMoodRequest(
                    $state, self::KEY_PREFIX . $opponentId, $opponentId, true,
                    'Jealousy: put one of your moods into the discard pile',
                );
            }
        }

        return $requests;
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        TurnStartSupport::discardChosenOwnMoods($state, $answers, self::KEY_PREFIX);

        return [];
    }
}
