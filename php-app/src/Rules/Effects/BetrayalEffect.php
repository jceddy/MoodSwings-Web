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
 * Betrayal: "After playing this mood, give one of your moods to another
 * player. After scoring, that mood becomes yours again if it's still in
 * play." Nothing in that text excludes Betrayal itself -- giving itself
 * away is a legal (and thematic) answer -- but "one of your moods" can't
 * be offered as an ordinary up-front choice_fields entry the way it is for
 * almost every other "target your own mood" effect: at the moment the
 * choices panel is filled out, Betrayal is still sitting in hand, not yet
 * in play, so a field sourced from the current board could never legally
 * include it. Modeled as a RequiresOpponentDecision instead -- not because
 * anyone OTHER than the acting player answers it (targetPlayerId is always
 * $playerId here), but because that's what already gets a decision
 * deferred until after the played card has actually entered play, which is
 * the one thing this choice genuinely needs. recipient_player_id has no
 * such problem (any other player is already choosable up front, regardless
 * of what happens to Betrayal itself), so it stays an ordinary submitted
 * choice, validated immediately rather than deferred.
 *
 * The given-away mood is tagged with the well-known
 * 'returnsToOwnerAfterScoring' effectState key ({sourceCardId, ownerId} --
 * sourceCardId names which card is responsible, purely so a card's own
 * detail view can explain a temporary ownership change; ownerId is the
 * original owner's id, the only part GameService::applyAfterScoringHooks()
 * itself actually reads to resolve it after every round), matching
 * RecklessnessEffect's own identical tag shape -- "if it's still in play"
 * is automatic, since the tag is simply never consulted for a mood that's
 * left play by then.
 */
final class BetrayalEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY = 'target_mood_id';

    /**
     * Unlike every other RequiresOpponentDecision implementer, this never
     * returns [] -- Betrayal's own printed text has no "may", so the
     * decision is always asked, never declined.
     */
    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $recipientPlayerId = $choices->requireInt('recipient_player_id');
        if (!in_array($recipientPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$recipientPlayerId} is not a valid player");
        }
        if ($recipientPlayerId === $playerId) {
            throw new InvalidChoiceException('Betrayal must give the mood to another player');
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $playerId,
                decisionType: 'betrayal_give_mood',
                field: [
                    'key' => self::KEY,
                    'type' => 'mood',
                    'scope' => 'own',
                    'required' => true,
                    'label' => 'Choose one of your moods to give away (Betrayal itself is a valid choice)',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        $targetCardId = $answers[self::KEY]->requireInt(self::KEY);
        if (!$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
            throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s moods in play");
        }

        $recipientPlayerId = $choices->requireInt('recipient_player_id');

        $state->giveInPlayToPlayer($targetCardId, $recipientPlayerId);
        $state->setEffectState($targetCardId, 'returnsToOwnerAfterScoring', ['sourceCardId' => $cardId, 'ownerId' => $playerId]);
    }
}
