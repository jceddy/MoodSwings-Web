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
 * Arrogance: "After playing this mood, you may choose an opponent. If
 * you do, they choose one of their white or blue moods and it becomes
 * yours. After this mood is no longer in play, give the mood you took
 * back to them if you still have it." The opponent's own choice among
 * their qualifying moods is genuinely their own decision -- see
 * RequiresOpponentDecision. The taken mood is tagged with the
 * well-known 'returnsToOwnerIfCardLeavesPlay' effectState key -- see
 * BoardState's leave-play cascade -- which also records who currently
 * holds it, so "if you still have it" is honored even if the acting
 * player gives the mood away again before Arrogance itself leaves play.
 */
final class ArroganceEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const QUALIFYING_COLORS = ['white', 'blue'];
    private const KEY = 'chosen_mood_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        if (!$choices->has('opponent_player_id')) {
            return [];
        }

        $opponentId = $choices->requireInt('opponent_player_id');
        if (!in_array($opponentId, $state->playerOrder(), true)) {
            throw new InvalidChoiceException("Player {$opponentId} is not a valid player");
        }
        if ($opponentId === $playerId) {
            throw new InvalidChoiceException('Arrogance must target an opponent');
        }

        $qualifying = array_filter(
            $state->moodsOwnedBy($opponentId),
            fn ($mood) => in_array($state->colorOf($mood->cardId), self::QUALIFYING_COLORS, true),
        );
        if ($qualifying === []) {
            return [];
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $opponentId,
                decisionType: 'arrogance_give_mood',
                field: [
                    'key' => self::KEY,
                    'type' => 'mood',
                    'scope' => 'own',
                    'filter' => ['colors' => self::QUALIFYING_COLORS],
                    'required' => true,
                    'label' => 'Choose one of your white or blue moods to give up',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        if (!isset($answers[self::KEY])) {
            return;
        }

        $opponentId = $choices->requireInt('opponent_player_id');
        $chosenCardId = $answers[self::KEY]->requireInt(self::KEY);

        if (!$state->isInPlay($chosenCardId) || $state->ownerOf($chosenCardId) !== $opponentId) {
            throw new InvalidChoiceException("Card {$chosenCardId} is not one of player {$opponentId}'s moods in play");
        }
        if (!in_array($state->colorOf($chosenCardId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Arrogance can only take a white or blue mood');
        }

        $state->giveInPlayToPlayer($chosenCardId, $playerId);
        $state->setEffectState($chosenCardId, 'returnsToOwnerIfCardLeavesPlay', [
            'sourceCardId' => $cardId,
            'ownerId' => $opponentId,
            'heldByPlayerId' => $playerId,
        ]);
    }
}
