<?php

declare(strict_types=1);

namespace MoodSwings\Bot;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\CardChoiceSchema;

/**
 * The move generator a search engine (SearchBotPlayerService) needs and
 * neither BotPlayerService nor BotChoiceResolver provide on their own:
 * BotChoiceResolver enumerates a field's legal candidates internally, but
 * always collapses them down to its own single, non-strategic pick (see
 * its own docblock); BotPlayerService::buildChoicesForCard() likewise
 * always returns exactly one fully-built choice set for a card, using
 * BotChoiceResolver's picks for the schema-driven majority of cards, plus
 * ~14 hand-written bespoke branches (buildBaseChoicesForCard()) for the
 * handful of cards whose legal targeting needs its own dedicated logic
 * (see BotPlayerService::usesBespokeChoiceBuilding()).
 *
 * Rather than reimplementing full legal-choice enumeration from scratch
 * for every one of those ~14 bespoke branches (each would need its own
 * "every legal option," not just the resolver's one pick -- a project on
 * the scale of BotPlayerService itself), this class takes a narrower,
 * pragmatic approach: for every playable card, buildChoicesForCard()'s
 * own existing, already-tested default choice set is ALWAYS one
 * guaranteed-legal candidate action. On top of that single default, this
 * class additionally varies exactly one thing -- a card's own generic,
 * schema-driven required target field (a 'mood' or 'player' field with
 * scope 'other'/'any' -- the closest thing to "who do I point this at"
 * most targeted cards have) -- into a handful of alternate legal
 * candidates, using BotChoiceResolver's own newly-exposed
 * moodFieldCandidates()/playerFieldCandidates() (the full candidate list
 * it already computes internally before collapsing to one pick).
 *
 * What this deliberately does NOT vary (left exactly as
 * buildChoicesForCard() already builds it, for every card): any of the
 * ~14 bespoke-branch effect keys' own targeting (Fury, Avoidance,
 * Rationalization, Cynicism, Anger, Denial, Contempt, Conviction, Hate,
 * Pacifism, Nostalgia, Sneakiness, Intimidation, Paranoia, Creativity --
 * see BotPlayerService::usesBespokeChoiceBuilding()), any 'mode'/'value'
 * field, any 'card_order' field, or a 'hand_card'/'discard_card'
 * cost-paying field. A search engine built on top of this therefore adds
 * genuine multi-turn lookahead over WHICH card to play and WHEN, plus
 * meaningfully better TARGETING for schema-driven target fields (which
 * cover most targeted cards) -- but reuses the existing heuristic bot's
 * own targeting verbatim for the bespoke special cases. A reasonable,
 * explicitly-scoped v1 limitation rather than an oversight: fully
 * generalizing enumeration to every bespoke branch is future work.
 */
final class LegalChoiceEnumerator
{
    /**
     * Caps how many alternate single-target candidates (and, separately,
     * multi-target subset variants) this class ever generates for one
     * card, purely to keep a search engine's per-turn branching factor
     * bounded -- a card with many legal targets (e.g. "any mood in play"
     * in a 4-player team game) would otherwise generate one action per
     * target, most of which a time-boxed search would never even get to
     * revisit enough to meaningfully compare.
     */
    private const MAX_TARGET_VARIANTS = 6;

    public function __construct(
        private readonly BotPlayerService $heuristic,
        private readonly BotChoiceResolver $resolver = new BotChoiceResolver(),
    ) {
    }

    /**
     * @param int[] $playableCardIds already legality-filtered (MoodPlayService::
     *     isPlayable()) the same way BotPlayerService::chooseAction()'s own
     *     caller filters them -- this class only ever varies WITHIN a
     *     card's own targeting, never decides whether a card itself is
     *     legal to play at all.
     * @return array<int, array{card_id: int, choices: array<string, mixed>}>
     *     every distinct (card, choices) action worth a search branching
     *     over -- always includes each playable card's own default
     *     heuristic-built action; a card with no legal choice set at all
     *     (buildChoicesForCard() returning null) is simply omitted, same
     *     as BotPlayerService::chooseAction() itself already skips it.
     */
    public function enumerate(BoardState $state, array $playableCardIds, int $actingPlayerId): array
    {
        $actions = [];

        foreach ($playableCardIds as $cardId) {
            $defaultChoices = $this->heuristic->buildChoicesForCard($state, $cardId, $actingPlayerId);
            if ($defaultChoices === null) {
                continue;
            }

            foreach ($this->choiceVariantsForCard($state, $cardId, $actingPlayerId, $defaultChoices) as $choices) {
                $actions[] = ['card_id' => $cardId, 'choices' => $choices];
            }
        }

        return $actions;
    }

    /** @param array<string, mixed> $defaultChoices @return array<int, array<string, mixed>> */
    private function choiceVariantsForCard(BoardState $state, int $cardId, int $actingPlayerId, array $defaultChoices): array
    {
        $effectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];
        if ($this->heuristic->usesBespokeChoiceBuilding($effectKey)) {
            return [$defaultChoices];
        }

        $targetField = $this->singleTargetFieldFor($effectKey);
        if ($targetField === null) {
            return [$defaultChoices];
        }

        $candidates = $targetField['type'] === 'mood'
            ? $this->resolver->moodFieldCandidates($state, $targetField, $actingPlayerId, $cardId)
            : $this->resolver->playerFieldCandidates($state, $targetField, $actingPlayerId, false);

        if ($candidates === []) {
            return [$defaultChoices];
        }

        return ($targetField['multi'] ?? false)
            ? $this->multiTargetVariants($targetField, $candidates, $defaultChoices)
            : $this->singleTargetVariants($targetField, $candidates, $defaultChoices);
    }

    /**
     * The one required 'mood'/'player' field on $effectKey's own schema
     * whose scope makes it a genuine "who/what do I point this at"
     * choice (scope 'other'/'any' -- 'own' is always a cost/sacrifice
     * field, already well served by BotChoiceResolver's own "cheapest
     * legal option" policy) -- null if $effectKey has no schema fields at
     * all (e.g. an unconditional card with nothing to choose), or none
     * matching this shape. At most one such field exists per card in the
     * current schema; the first match is returned.
     *
     * @return ?array<string, mixed>
     */
    private function singleTargetFieldFor(string $effectKey): ?array
    {
        foreach (CardChoiceSchema::forEffectKey($effectKey) as $field) {
            if (($field['required'] ?? false) !== true) {
                continue;
            }
            $type = $field['type'] ?? null;
            $scope = $field['scope'] ?? null;
            if (in_array($type, ['mood', 'player'], true) && in_array($scope, ['other', 'any'], true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param int[] $candidates
     * @param array<string, mixed> $defaultChoices
     * @return array<int, array<string, mixed>>
     */
    private function singleTargetVariants(array $field, array $candidates, array $defaultChoices): array
    {
        $variants = [];
        $seen = [];

        foreach (array_slice($candidates, 0, self::MAX_TARGET_VARIANTS) as $candidateId) {
            if (isset($seen[$candidateId])) {
                continue;
            }
            $seen[$candidateId] = true;

            $choices = $defaultChoices;
            $choices[$field['key']] = $candidateId;
            $variants[] = $choices;
        }

        return $variants !== [] ? $variants : [$defaultChoices];
    }

    /**
     * A handful of meaningfully different subset sizes/members for a
     * multi-target field -- NOT full combinatorial (K choose N)
     * enumeration, which could explode for a card offering many legal
     * targets at once. "Every eligible candidate" (up to the field's own
     * max) and "just the field's own minimum count" are the two
     * deliberately simple, opposite-ends-of-the-range variants offered
     * alongside the default -- a time-boxed search evaluates each via
     * real simulation, so even this coarse a choice of subsets still lets
     * it discover "targeting fewer/more is actually better here" without
     * needing an exhaustive candidate list to start from.
     *
     * @param int[] $candidates
     * @param array<string, mixed> $defaultChoices
     * @return array<int, array<string, mixed>>
     */
    private function multiTargetVariants(array $field, array $candidates, array $defaultChoices): array
    {
        $variants = [$defaultChoices];

        $min = max(1, (int) ($field['count']['min'] ?? 1));
        $max = min(count($candidates), (int) ($field['count']['max'] ?? count($candidates)));
        if ($max < $min) {
            return $variants;
        }

        $all = array_slice($candidates, 0, $max);
        $maxVariant = $defaultChoices;
        $maxVariant[$field['key']] = $all;
        $variants[] = $maxVariant;

        if ($min < count($all)) {
            $minVariant = $defaultChoices;
            $minVariant[$field['key']] = array_slice($all, 0, $min);
            $variants[] = $minVariant;
        }

        return $variants;
    }
}
