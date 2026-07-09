<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * One decision that needs a specific player's real input before a play
 * can finish resolving -- usually a specific OTHER player, e.g.
 * Compulsion's target choosing which hand card to give up (returned by
 * RequiresOpponentDecision::pendingDecisionsFor()), but $targetPlayerId
 * can also be the ACTING player themselves -- e.g. Duplicity's own "repeat
 * this mood's effect again?" offer (see MoodPlayService::
 * continueAfterPlayingChain()), which still needs the same durable
 * pause-across-requests treatment even though it's not a different
 * physical person being asked. GameService persists one
 * game_pending_decisions row per request, computed at batch-creation time
 * from the target's own perspective.
 *
 * $field mirrors CardChoiceSchema's field shape (type/scope/filter/multi/
 * count/required/label) so the client can render it with the same
 * buildFieldRow()/fieldOptions() machinery already used for choice_fields.
 * $key is both this field's own key in the field description and the key
 * the resolved answer is stored/looked up under (see
 * RequiresOpponentDecision::resolveDecisions()'s $answers parameter).
 */
final class PendingDecisionRequest
{
    /** @param array<string, mixed> $field */
    public function __construct(
        public readonly string $key,
        public readonly int $targetPlayerId,
        public readonly string $decisionType,
        public readonly array $field,
    ) {
    }
}
