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
 * away is a legal (and thematic) answer. Both the mood and the recipient
 * are ordinary up-front choices (`target_mood_id`, `recipient_player_id`),
 * like every other card's targets: the mood field's `includes_self` flag
 * makes clients offer the card being played as a "[self]" candidate even
 * though it is still in hand while the panel is filled out, and by the time
 * this effect runs MoodPlayService has already moved Betrayal into play, so
 * its own id is a perfectly good in-play target.
 *
 * (Betrayal used to ask for the mood only AFTER entering play, as a
 * self-targeted RequiresOpponentDecision -- the one card in the simulator
 * that worked that way. The interface is kept purely so a game that was
 * mid-way through that old decision when this changed can still finish it:
 * a play submitted WITHOUT `target_mood_id` still pauses for it, and
 * resolveDecisions() honors that answer.)
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

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $recipientPlayerId = $choices->requireInt('recipient_player_id');
        if (!in_array($recipientPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$recipientPlayerId} is not a valid player");
        }
        if ($recipientPlayerId === $playerId) {
            throw new InvalidChoiceException('Betrayal must give the mood to another player');
        }

        if ($choices->has(self::KEY)) {
            return []; // the mood was chosen up front -- resolveDecisions() gives it away right now
        }

        // Legacy path: no mood submitted, so ask for it now that Betrayal is in play.
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

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $targetCardId = isset($answers[self::KEY])
            ? $answers[self::KEY]->requireInt(self::KEY)
            : $choices->requireInt(self::KEY);
        if (!$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
            throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s moods in play");
        }

        $recipientPlayerId = $choices->requireInt('recipient_player_id');
        $state->giveInPlayToPlayer($targetCardId, $recipientPlayerId);
        $state->setEffectState($targetCardId, 'returnsToOwnerAfterScoring', ['sourceCardId' => $cardId, 'ownerId' => $playerId]);

        return [];
    }
}
