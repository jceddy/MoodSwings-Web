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
 * Reckoning: "After playing this mood -- You may have each player choose one of their moods. If you do, put all moods that weren't
 * chosen into the discard pile." The "may" is the optional 'reckon' flag; if set, every active player with a mood in play -- the
 * acting player too, Reckoning itself being one of their moods they may choose or not -- is asked which of their own to keep, and
 * nothing is discarded until every answer is in (the choices are simultaneous). A player with no moods chooses nothing.
 */
final class ReckoningEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY_PREFIX = 'chosen_mood_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        if (!$choices->bool('reckon')) {
            return [];
        }

        $requests = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $moodIds = array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($ownerId));
            if ($moodIds === []) {
                continue;
            }

            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $ownerId,
                targetPlayerId: $ownerId,
                decisionType: 'reckoning_keep_mood',
                field: [
                    'key' => self::KEY_PREFIX . $ownerId,
                    'type' => 'mood',
                    'candidate_card_ids' => array_values($moodIds),
                    'required' => true,
                    'label' => 'Reckoning: choose the one mood you keep (every other mood in play goes into the discard pile)',
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (!$choices->bool('reckon')) {
            return [];
        }

        $kept = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $key = self::KEY_PREFIX . $ownerId;
            if (!isset($answers[$key])) {
                continue;
            }

            $chosenCardId = $answers[$key]->requireInt($key);
            if (!$state->isInPlay($chosenCardId) || $state->ownerOf($chosenCardId) !== $ownerId) {
                throw new InvalidChoiceException("Card {$chosenCardId} is not one of player {$ownerId}'s moods in play");
            }
            $kept[] = $chosenCardId;
        }

        foreach ($state->moodsInPlay() as $mood) {
            if (!in_array($mood->cardId, $kept, true)) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }

        return [];
    }
}
