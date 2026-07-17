<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RequiresOpponentDecision;

/**
 * Avoidance: "After playing this mood, choose left or right. Each player
 * chooses one of their moods and gives it to the next player in the
 * chosen direction." The text says each player "chooses" their own mood
 * -- not "at random" (contrast Cruelty/Paranoia/Indecisiveness, which
 * do) -- so every player with at least one mood in play gets their own
 * queued decision (see RequiresOpponentDecision, and Confusion's
 * identical mechanic applied to hand cards instead), including the
 * acting player themselves. 'right' is defined as moving forward through
 * seat order (wrapping), 'left' as backward; all transfers are computed
 * against everyone's ORIGINAL moods and only applied once every answer is
 * in, matching the printed text's simultaneous exchange -- nobody's
 * choice is affected by a mood they're about to receive from this same
 * resolution.
 */
final class AvoidanceEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY_PREFIX = 'given_mood_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $direction = $choices->requireString('direction');
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new InvalidChoiceException("Avoidance's direction must be 'left' or 'right'");
        }

        $requests = [];
        foreach ($state->activePlayerOrder() as $giverId) {
            if ($state->moodsOwnedBy($giverId) === []) {
                continue;
            }

            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $giverId,
                targetPlayerId: $giverId,
                decisionType: 'avoidance_give_mood',
                field: [
                    'key' => self::KEY_PREFIX . $giverId,
                    'type' => 'mood',
                    'scope' => 'own',
                    'required' => true,
                    'label' => "Avoidance: choose one of your moods to give to your {$direction}-hand neighbor",
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        $direction = $choices->requireString('direction');

        $transfers = [];
        foreach ($state->activePlayerOrder() as $giverId) {
            $key = self::KEY_PREFIX . $giverId;
            if (!isset($answers[$key])) {
                continue;
            }

            $givenMoodId = $answers[$key]->requireInt($key);
            if (!$state->isInPlay($givenMoodId) || $state->ownerOf($givenMoodId) !== $giverId) {
                throw new InvalidChoiceException("Mood {$givenMoodId} is not one of player {$giverId}'s moods in play");
            }

            $recipientId = $state->activeNeighbor($giverId, $direction);
            if ($recipientId === null) {
                continue;
            }
            $transfers[$givenMoodId] = $recipientId;
        }

        foreach ($transfers as $moodCardId => $recipientId) {
            $state->giveInPlayToPlayer($moodCardId, $recipientId);
        }
    }
}
