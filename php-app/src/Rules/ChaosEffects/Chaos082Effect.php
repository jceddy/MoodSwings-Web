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
 * chaos_082 (uncommon, after_playing): "You may choose an opponent. If
 * you do, they choose one of their white or blue moods and it becomes
 * yours. After this mood is no longer in play, give the mood you took
 * back to them (if you still have it)." Identical printed text to
 * Effects/ArroganceEffect.php -- the opponent's own choice among their
 * qualifying moods is genuinely their own decision (see
 * ChaosRequiresOpponentDecision), reusing the exact same
 * 'returnsToOwnerIfCardLeavesPlay' effectState tag and
 * BoardState::cascadeMoodLeavingPlay() mechanism -- a maintainer ruling
 * reversing this class's own original design (issue #405 follow-up,
 * reported live for chaos_086's identically-shaped case: "the other
 * player should choose the card from their hand to give to me -- it
 * should not be random").
 */
final class Chaos082Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const QUALIFYING_COLORS = ['white', 'blue'];
    private const KEY = 'chosen_mood_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        if (!$choices->has('opponent_player_id')) {
            return [];
        }

        $opponentId = $choices->requireInt('opponent_player_id');
        if (!in_array($opponentId, $state->activePlayerOrder(), true) || $opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
            throw new InvalidChoiceException("Player {$opponentId} is not a valid opponent");
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
                decisionType: 'chaos_082_give_mood',
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

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (!isset($answers[self::KEY])) {
            return [];
        }

        $opponentId = $choices->requireInt('opponent_player_id');
        $chosenCardId = $answers[self::KEY]->requireInt(self::KEY);

        if (!$state->isInPlay($chosenCardId) || $state->ownerOf($chosenCardId) !== $opponentId) {
            throw new InvalidChoiceException("Card {$chosenCardId} is not one of player {$opponentId}'s moods in play");
        }
        if (!in_array($state->colorOf($chosenCardId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Only a white or blue mood can be given up');
        }

        $state->giveInPlayToPlayer($chosenCardId, $playerId);
        $state->setEffectState($chosenCardId, 'returnsToOwnerIfCardLeavesPlay', [
            'sourceCardId' => $cardId,
            'ownerId' => $opponentId,
            'heldByPlayerId' => $playerId,
        ]);

        return [];
    }
}
