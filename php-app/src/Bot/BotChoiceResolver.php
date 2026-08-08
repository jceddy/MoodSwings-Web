<?php

declare(strict_types=1);

namespace MoodSwings\Bot;

use MoodSwings\Rules\BoardState;

/**
 * A server-side equivalent of web-static/js/game.js's own fieldOptions()/
 * matchesCardFilter()/matchesPlayerFilter() -- enumerates the legal
 * candidate value(s) for one CardChoiceSchema/PendingDecisionRequest field
 * against a live BoardState, and picks one via a simple, deliberately
 * non-strategic policy (see resolve()'s own docblock), so a practice bot
 * (issue #140) can always construct a fully legal answer without a human
 * in the loop.
 *
 * Only ever called for a REQUIRED field -- resolve() returns null outright
 * for an optional one, since a bot never volunteers for an optional
 * bonus/cost the way a human might; see BotPlayerService for how that
 * plays out at the whole-card/whole-decision level. This mirrors issue
 * #274's own "leave it blank" bias for a risky/optional field, just
 * pushed all the way to "never even consider it" rather than "leave a
 * pre-fill blank for a human to fill in themselves" -- there's no human
 * here to fill anything in. ALWAYS_FILLED_OPTIONAL_FIELDS below is the
 * one deliberate, narrow exception to this rule -- see its own docblock.
 */
final class BotChoiceResolver
{
    /**
     * A required 'mode' field whose first option would leave some OTHER
     * field conditionally required (via CardChoiceSchema's own "required
     * if mode is X" phrasing, which the schema can't statically encode as
     * a real 'required' flag) is hand-overridden to whichever option
     * needs no companion field at all -- currently every card with this
     * shape (Guilt/Contempt/Redemption's own mode+target pair) uses the
     * same options ['single', 'all'], where 'all' alone needs nothing
     * else filled in. A bot that only ever fills REQUIRED fields (see
     * this class's own docblock) would otherwise pick 'single' -- schema
     * options[0] -- and then leave the conditionally-required target
     * blank, since the target field's own 'required' is statically
     * false.
     */
    private const MODE_FIELD_OVERRIDES = [
        'guilt' => 'all',
        'contempt' => 'all',
        'redemption' => 'all',
    ];

    /**
     * A hand-picked set of OPTIONAL fields a bot fills in anyway, keyed by
     * effect key => the field key(s) to force -- a narrow, deliberate
     * exception to this class's own "never volunteer for an optional
     * bonus/cost" bias (see this class's own docblock), reserved for the
     * rare card whose optional target has NO real cost to the acting
     * player at all: Curiosity's "you may choose a player" (a free
     * reveal -- at best a value boost, nothing given up if it whiffs) and
     * Suspicion's "choose any number of players" (forces a discard from
     * each -- again nothing the acting player gives up, and choosing more
     * targets is strictly better than choosing fewer). Contrast Malice's
     * own similarly-shaped optional `target_player_id` (deliberately NOT
     * here): it grants the target extra plays too, a real cost-benefit
     * trade-off this class still leaves for a human to judge.
     *
     * Two behaviors both flow from being in this list, applied by
     * resolve()/resolvePlayerField()/pickIdCandidates() below:
     * - The field is resolved at all despite `required` being false.
     * - The acting player is excluded from its own candidate pool even
     *   though the field's own `scope` is `'any'` (which would otherwise
     *   allow self-targeting) -- targeting yourself is never the intent
     *   here, just something the schema permits for a human who might
     *   have an obscure reason to.
     * - For a MULTI field specifically, every legal candidate is taken
     *   rather than just `count.min` -- Suspicion's whole point is
     *   "choose any number," and taking fewer than every legal opponent
     *   would leave free value on the table the same way never filling
     *   the field at all would.
     */
    private const ALWAYS_FILLED_OPTIONAL_FIELDS = [
        'curiosity' => ['target_player_id'],
        'suspicion' => ['player_ids'],
    ];

    /**
     * Resolves one value for $field on behalf of $actingPlayerId, or null
     * if $field isn't required (a bot never fills an optional field) or
     * -- for a required one -- no legal value could be found at all (the
     * caller should then treat the whole play/decision this field belongs
     * to as unusable right now, same as a human who's simply out of legal
     * options).
     *
     * $ownCardId is the card currently being played (for a 'mood' field's
     * own includes_self, and to exclude that card from its own candidate
     * pool) -- 0 for a pending-decision answer, where the triggering
     * card is already safely in play and needs no such exclusion (see
     * this method's own 'mood' branch).
     *
     * The policy per field type, uniformly non-strategic and driven only
     * by the field's own shape (never a per-card special case, other than
     * MODE_FIELD_OVERRIDES above):
     * - 'mode': its first option (or MODE_FIELD_OVERRIDES's own pick).
     * - 'value': its own minimum -- always in range by construction, and
     *   there is currently no required 'value' field with any board-state
     *   constraint narrower than min/max to respect.
     * - 'mood'/'player' with scope 'own' (or a 'hand_card'/'discard_card'
     *   field, always implicitly "your own"): the N LOWEST-value legal
     *   candidates, N = count.min (or 1) -- minimizes whatever's being
     *   given up as a cost or a voluntary sacrifice.
     * - 'mood'/'player' with scope 'other'/'any': the N HIGHEST-value (for
     *   'mood') or simply the first N (for 'player', which has no
     *   intrinsic value to rank by) legal candidates -- a mildly
     *   "better than nothing" choice of target without attempting any
     *   real strategy.
     * - 'card_order': $field['cards']' own ids, in the order already
     *   given -- accepting the server's own default ordering.
     * - anything else (currently just 'bool'/'value' with no required use
     *   found in the schema, and 'grant_choice', which is never actually
     *   required -- see CardChoiceSchema's own docblock): null, since a
     *   bot has nothing that needs to fill these in today.
     */
    public function resolve(BoardState $state, array $field, int $actingPlayerId, int $ownCardId, string $effectKey): mixed
    {
        $forced = $this->isAlwaysFilledOptionalField($effectKey, $field['key'] ?? '');
        if (($field['required'] ?? false) !== true && !$forced) {
            return null;
        }

        return match ($field['type']) {
            'mode' => $this->resolveMode($field, $effectKey),
            'value' => $field['min'] ?? null,
            'mood' => $this->resolveMoodField($state, $field, $actingPlayerId, $ownCardId),
            'player' => $this->resolvePlayerField($state, $field, $actingPlayerId, $forced),
            'hand_card' => $this->resolveOwnResourceField($state, $field, $state->hand($actingPlayerId), $ownCardId),
            'discard_card' => $this->resolveOwnResourceField($state, $field, $state->discardPile(), $ownCardId),
            'card_order' => array_map(static fn (array $card) => $card['card_id'], $field['cards'] ?? []),
            default => null,
        };
    }

    /**
     * Whether $fieldKey on $effectKey is one of ALWAYS_FILLED_OPTIONAL_FIELDS'
     * own hand-picked exceptions -- exposed so BotPlayerService::
     * buildChoicesForCard() can decide up front whether to even attempt
     * resolving an optional field at all, the same up-front check it
     * already does for a required one, without duplicating this class's
     * own override table.
     */
    public function isAlwaysFilledOptionalField(string $effectKey, string $fieldKey): bool
    {
        return in_array($fieldKey, self::ALWAYS_FILLED_OPTIONAL_FIELDS[$effectKey] ?? [], true);
    }

    private function resolveMode(array $field, string $effectKey): ?string
    {
        $options = $field['options'] ?? [];
        $override = self::MODE_FIELD_OVERRIDES[$effectKey] ?? null;
        if ($override !== null && in_array($override, $options, true)) {
            return $override;
        }

        return $options[0] ?? null;
    }

    /** @return int|int[]|null */
    private function resolveMoodField(BoardState $state, array $field, int $actingPlayerId, int $ownCardId): int|array|null
    {
        $candidates = $this->moodCandidates($state, $field, $actingPlayerId, $ownCardId);

        return $this->pickCandidates($state, $field, $candidates, ($field['scope'] ?? null) === 'own', $ownCardId);
    }

    /** @return int[] */
    private function moodCandidates(BoardState $state, array $field, int $actingPlayerId, int $ownCardId): array
    {
        if (isset($field['candidate_card_ids'])) {
            return $field['candidate_card_ids'];
        }

        $scope = $field['scope'] ?? 'any';
        $excludesTeammate = $field['excludes_teammate'] ?? false;
        $candidates = ($field['includes_self'] ?? false) ? [$ownCardId] : [];

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId === $ownCardId) {
                continue;
            }
            if ($scope === 'own' && $mood->ownerId !== $actingPlayerId) {
                continue;
            }
            if ($scope === 'other' && $mood->ownerId === $actingPlayerId) {
                continue;
            }
            if ($excludesTeammate && $state->isTeammate($actingPlayerId, $mood->ownerId)) {
                continue;
            }
            if (!$this->matchesCardFilter($state, $mood->cardId, $field['filter'] ?? null)) {
                continue;
            }
            $candidates[] = $mood->cardId;
        }

        return $candidates;
    }

    /**
     * $forced (see ALWAYS_FILLED_OPTIONAL_FIELDS) both excludes the acting
     * player from its own candidate pool -- even under scope 'any', which
     * would otherwise permit self-targeting -- and, for a multi field,
     * takes every legal candidate rather than just count.min.
     *
     * @return int|int[]|null
     */
    private function resolvePlayerField(BoardState $state, array $field, int $actingPlayerId, bool $forced = false): int|array|null
    {
        $candidates = $this->playerCandidates($state, $field, $actingPlayerId, $forced);

        return $this->pickIdCandidates($candidates, $field, fn (int $id) => 0, false, $forced);
    }

    /** @return int[] */
    private function playerCandidates(BoardState $state, array $field, int $actingPlayerId, bool $excludeSelf = false): array
    {
        if (isset($field['candidate_player_ids'])) {
            return $field['candidate_player_ids'];
        }

        $scope = $field['scope'] ?? 'any';
        $excludesTeammate = $field['excludes_teammate'] ?? false;
        $candidates = [];

        foreach ($state->activePlayerOrder() as $playerId) {
            if (($scope === 'other' || $excludeSelf) && $playerId === $actingPlayerId) {
                continue;
            }
            if ($excludesTeammate && $state->isTeammate($actingPlayerId, $playerId)) {
                continue;
            }
            if (!$this->matchesPlayerFilter($state, $playerId, $field['filter'] ?? null)) {
                continue;
            }
            $candidates[] = $playerId;
        }

        return $candidates;
    }

    /**
     * A required 'hand_card'/'discard_card' field is always implicitly
     * "one of your own" (guile's/bliss's discard cost are the only
     * required examples today) -- so this always prefers the LOWEST-value
     * candidates, the same "minimize the cost" policy resolveMoodField()
     * applies for scope 'own'.
     *
     * @param int[] $candidateCardIds
     * @return int|int[]|null
     */
    private function resolveOwnResourceField(BoardState $state, array $field, array $candidateCardIds, int $ownCardId): int|array|null
    {
        $candidates = array_values(array_filter(
            $candidateCardIds,
            fn (int $cardId) => $cardId !== $ownCardId && $this->matchesCardFilter($state, $cardId, $field['filter'] ?? null),
        ));

        return $this->pickCandidates($state, $field, $candidates, true, $ownCardId);
    }

    /** @param int[] $candidates @return int|int[]|null */
    private function pickCandidates(BoardState $state, array $field, array $candidates, bool $lowestFirst, int $ownCardId): int|array|null
    {
        return $this->pickIdCandidates(
            $candidates,
            $field,
            fn (int $cardId) => $cardId === $ownCardId
                ? $state->catalogRow($state->effectiveCardId($cardId))['baseValue']
                : ($state->isInPlay($cardId) ? $state->valueOf($cardId) : $state->catalogRow($state->effectiveCardId($cardId))['baseValue']),
            $lowestFirst,
        );
    }

    /**
     * Shared "sort by a value function, then take however many are
     * needed" tail for every candidate list above -- $valueFor defaults
     * to a constant (via the closure passed in) for 'player' candidates,
     * which have no intrinsic value to rank by, leaving activePlayerOrder()'s
     * own seat order as the (arbitrary but deterministic) tie-break.
     * $takeAll (see ALWAYS_FILLED_OPTIONAL_FIELDS) takes every candidate
     * for a multi field instead of just count.min -- unused, and always
     * false, for every field type but 'player' today.
     *
     * @param int[] $candidates
     * @return int|int[]|null
     */
    private function pickIdCandidates(array $candidates, array $field, callable $valueFor, bool $lowestFirst = false, bool $takeAll = false): int|array|null
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (int $a, int $b) use ($valueFor, $lowestFirst) {
            $diff = $valueFor($a) <=> $valueFor($b);

            return $lowestFirst ? $diff : -$diff;
        });

        if ($field['multi'] ?? false) {
            $count = $takeAll ? count($candidates) : max(1, (int) ($field['count']['min'] ?? 1));
            if (count($candidates) < $count) {
                return null;
            }

            return array_slice($candidates, 0, $count);
        }

        return $candidates[0];
    }

    private function matchesCardFilter(BoardState $state, int $cardId, ?array $filter): bool
    {
        if ($filter === null) {
            return true;
        }

        $row = $state->catalogRow($state->effectiveCardId($cardId));
        $color = $state->colorOf($cardId);
        $value = $state->isInPlay($cardId) ? $state->valueOf($cardId) : $row['baseValue'];

        if (isset($filter['colors']) && !in_array($color, $filter['colors'], true)) {
            return false;
        }
        if (isset($filter['values']) && !in_array($value, $filter['values'], true)) {
            return false;
        }
        if (isset($filter['min_value']) && $value < $filter['min_value']) {
            return false;
        }
        if (isset($filter['max_value']) && $value > $filter['max_value']) {
            return false;
        }
        if (($filter['parity'] ?? null) === 'odd' && $value % 2 === 0) {
            return false;
        }
        if (($filter['parity'] ?? null) === 'even' && $value % 2 !== 0) {
            return false;
        }
        if (($filter['has_dice_value'] ?? false) && $row['altValue'] === null) {
            return false;
        }

        return true;
    }

    private function matchesPlayerFilter(BoardState $state, int $playerId, ?array $filter): bool
    {
        if ($filter === null) {
            return true;
        }
        if (isset($filter['min_hand_count']) && count($state->hand($playerId)) < $filter['min_hand_count']) {
            return false;
        }
        if (isset($filter['min_mood_count']) && count($state->moodsOwnedBy($playerId)) < $filter['min_mood_count']) {
            return false;
        }

        return true;
    }
}
