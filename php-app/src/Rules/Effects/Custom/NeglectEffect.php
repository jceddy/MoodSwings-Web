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
 * Neglect: "At the start of each of your turns, each opponent with no cards in their hand puts one of
 * their moods into the discard pile." Which opponents qualify is fixed when the trigger fires.
 */
final class NeglectEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const KEY_PREFIX = 'discard_mood_id_';

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        $requests = [];
        foreach (CustomEffectSupport::opponentIds($state, $playerId) as $opponentId) {
            if ($state->hand($opponentId) === [] && $state->moodsOwnedBy($opponentId) !== []) {
                $requests[] = TurnStartSupport::ownMoodRequest(
                    $state, self::KEY_PREFIX . $opponentId, $opponentId, true,
                    'Neglect: put one of your moods into the discard pile',
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
