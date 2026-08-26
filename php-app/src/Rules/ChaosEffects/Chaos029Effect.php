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
 * chaos_029 (rare, after_playing): "Choose left or right. Each player
 * chooses one of their moods and gives it to the next player in the
 * chosen direction." Identical printed text to Effects/AvoidanceEffect.php
 * -- the text says each player "chooses" their own mood, not "at random",
 * so every player with at least one mood in play gets their own queued
 * decision (see ChaosRequiresOpponentDecision), including the acting
 * player themselves -- a maintainer ruling reversing this class's own
 * original design (issue #405 follow-up, reported live for chaos_086's
 * identically-shaped case: "the other player should choose the card from
 * their hand to give to me -- it should not be random"). Direction is
 * resolved via BoardState::activeNeighbor(); all transfers are computed
 * against everyone's ORIGINAL moods and only applied once every answer is
 * in, matching the printed text's simultaneous exchange -- nobody's
 * choice is affected by a mood they're about to receive from this same
 * resolution.
 */
final class Chaos029Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY_PREFIX = 'given_mood_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $direction = $choices->requireString('direction');
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new InvalidChoiceException("'{$direction}' is not a valid direction");
        }

        $requests = [];
        foreach ($state->activePlayerOrder() as $giverId) {
            if ($state->moodsOwnedBy($giverId) === []) {
                continue;
            }

            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $giverId,
                targetPlayerId: $giverId,
                decisionType: 'chaos_029_give_mood',
                field: [
                    'key' => self::KEY_PREFIX . $giverId,
                    'type' => 'mood',
                    'scope' => 'own',
                    'required' => true,
                    'label' => "Choose one of your moods to give to your {$direction}-hand neighbor",
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
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

        return [];
    }
}
