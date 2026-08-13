<?php

declare(strict_types=1);

namespace MoodSwings\Bot;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\CardChoiceSchema;
use MoodSwings\Rules\RoundScorer;

/**
 * Decides a practice bot's (issue #140) own action -- what to play (and
 * with what choices) or, if nothing's worth playing, that it should pass;
 * how to answer a pending decision targeting it; and, for Open/Closed
 * Team Play (issue #360), its own turn-order/draw-recipient team-decision
 * proposal and Closed Team Play's blind pregame card pass. Deliberately
 * "legal, not strategic" -- see BotChoiceResolver's own docblock for the
 * field-filling policy this builds on -- with one deliberate exception:
 * shouldAttemptValueBoostDiscard() below, a scoring-aware, partly
 * probabilistic policy for Dignity/Embarrassment/Cheer/Delight's own
 * "you may discard a card to boost this mood's value" choice.
 * GameService is the only caller
 * (see its own "Practice bots" section in php-app/README.md for how this
 * fits into the request lifecycle) -- legality itself
 * (MoodPlayService::isPlayable()) is GameService's own call to make, not
 * this class's, since GameService already holds that dependency;
 * $playableCardIds below is expected to already be filtered down to
 * cards $botGamePlayerId could legally play right now.
 */
final class BotPlayerService
{
    /**
     * Dignity/Embarrassment/Cheer/Delight (every card in the catalog
     * shaped "after playing this mood, you may discard a card from your
     * hand with [a qualifying value]. If you do, this mood's value
     * becomes [X]" -- HandDiscardValueBoostEffect's own family, plus
     * Dignity's bespoke-but-identically-shaped equivalent) all boost to
     * 5 today, hand-picked here the same way BotChoiceResolver's own
     * MODE_FIELD_OVERRIDES/ALWAYS_FILLED_OPTIONAL_FIELDS hand-pick their
     * own card-specific exceptions, rather than exposed off
     * BoardState/EffectRegistry just for this.
     *
     * @var array<string, int> effect key => boosted value
     */
    private const HAND_DISCARD_VALUE_BOOST_EFFECT_BOOSTED_VALUES = [
        'dignity' => 5,
        'embarrassment' => 5,
        'cheer' => 5,
        'delight' => 5,
    ];

    public function __construct(
        private readonly BotChoiceResolver $resolver,
    ) {
    }

    /**
     * The highest-printed-value card in $playableCardIds (a plain-and-
     * simple stand-in for "which play matters most" -- see this class's
     * own docblock), with only that card's own REQUIRED choice_fields
     * filled in -- optional ones are left alone (the same "don't
     * volunteer for a bonus/cost nobody asked for" bias BotChoiceResolver
     * itself applies per field), except for BotChoiceResolver's own small
     * ALWAYS_FILLED_OPTIONAL_FIELDS list (Curiosity/Suspicion today),
     * which get filled in anyway via buildChoicesForCard()'s own
     * required-or-forced check. A card isWorthPlaying() vetoes (Fury
     * today -- see its own docblock) is skipped outright, same as one
     * whose required fields can't all be legally filled (rare -- would
     * mean isPlayable() said yes but some required field still came up
     * empty); either way, the next-highest-value card is tried instead,
     * all the way down to passing if truly nothing works.
     *
     * @param int[] $playableCardIds
     * @return ?array{card_id: int, choices: array<string, mixed>} null means pass.
     */
    public function chooseAction(BoardState $state, array $playableCardIds, int $botGamePlayerId): ?array
    {
        usort(
            $playableCardIds,
            fn (int $a, int $b) => $this->baseValue($state, $b) <=> $this->baseValue($state, $a),
        );

        foreach ($playableCardIds as $cardId) {
            $effectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];
            if (!$this->isWorthPlaying($state, $effectKey, $botGamePlayerId)) {
                continue;
            }

            $choices = $this->buildChoicesForCard($state, $cardId, $botGamePlayerId);
            if ($choices !== null) {
                return ['card_id' => $cardId, 'choices' => $choices];
            }
        }

        return null;
    }

    /**
     * A small set of additional per-card "is this actually worth
     * playing" vetoes, layered on top of chooseAction()'s own highest-
     * printed-value ordering -- unlike BotChoiceResolver's own field-
     * shape-driven policy (which only ever decides HOW to fill a
     * choice_field), these look at the whole-board CONSEQUENCE of an
     * effect that has no choice_field of its own for the acting player
     * to fill at all, so there's no field-level hook to veto it through
     * (Fury's own "each player chooses..." decisions are all
     * RequiresOpponentDecision, resolved after the card is already
     * played -- see CardChoiceSchema's own docblock for why an effect
     * like that carries no play-time choice_fields). Keyed by effect
     * key; everything not listed here is always worth playing (the
     * default, unconditional "yes" every other effect already got
     * before this method existed).
     */
    private function isWorthPlaying(BoardState $state, string $effectKey, int $botGamePlayerId): bool
    {
        return match ($effectKey) {
            'fury' => $this->furyIsWorthPlaying($state, $botGamePlayerId),
            default => true,
        };
    }

    /**
     * Fury costs the bot its own highest-value mood too -- "each player
     * chooses one of their highest value moods and puts it into the
     * discard pile" (FuryEffect's own docblock) targets every player in
     * the game, including whoever plays it. Only worth it if at least
     * one opponent's own highest-value mood is worth MORE than the
     * bot's own highest-value mood -- otherwise Fury just trades the
     * bot's own best mood for something equal or worse, a pure loss no
     * opponent's own equally-forced discard makes up for. A player with
     * no moods in play at all has an effective highest value of -1 (see
     * FuryEffect's own identical sentinel), so they never on their own
     * make Fury worth playing -- there'd be nothing for them to lose.
     */
    private function furyIsWorthPlaying(BoardState $state, int $botGamePlayerId): bool
    {
        $ownHighestValue = $this->highestMoodValueOwnedBy($state, $botGamePlayerId);

        foreach ($state->activePlayerOrder() as $playerId) {
            if ($playerId !== $botGamePlayerId && $this->highestMoodValueOwnedBy($state, $playerId) > $ownHighestValue) {
                return true;
            }
        }

        return false;
    }

    private function highestMoodValueOwnedBy(BoardState $state, int $playerId): int
    {
        $highestValue = -1;
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            $highestValue = max($highestValue, $state->valueOf($mood->cardId));
        }

        return $highestValue;
    }

    /** @return array<string, mixed> */
    public function chooseDecisionAnswer(BoardState $state, array $field, int $botGamePlayerId): array
    {
        $value = $this->resolver->resolve($state, $field, $botGamePlayerId, 0, '');

        return $value === null ? [] : [$field['key'] => $value];
    }

    /**
     * Which of a team's own two candidates a bot proposes for Open/Closed
     * Team Play's own turn-order/draw-recipient decision (issue #360; see
     * "Open Team Play"/"Closed Team Play" team decisions in
     * php-app/README.md) -- deliberately arbitrary and deterministic
     * ("legal, not strategic", this class's own philosophy throughout):
     * always the first of $candidateGamePlayerIds, regardless of which of
     * the two members is proposing or whether either is itself a bot.
     * There's nothing about "who goes first" or "who gets the extra draw"
     * this bot ever weighs differently, so a human confirmer who rejects
     * a bot's proposal just sees the exact same one proposed again.
     *
     * @param int[] $candidateGamePlayerIds always exactly the deciding
     *     team's own two members
     */
    public function chooseTeamDecisionProposal(array $candidateGamePlayerIds): int
    {
        return $candidateGamePlayerIds[0];
    }

    /**
     * Closed Team Play's own blind pregame card pass (issue #360; see
     * "Closed Team Play" in php-app/README.md) -- the 2 LOWEST-value
     * cards in the bot's own opening hand, the same "give up the least"
     * bias BotChoiceResolver already applies to every other mandatory
     * discard/cost-shaped choice (see its own docblock).
     *
     * @return int[] exactly 2 card ids
     */
    public function chooseInitialCardPass(BoardState $state, int $botGamePlayerId): array
    {
        $hand = $state->hand($botGamePlayerId);
        usort($hand, fn (int $a, int $b) => $this->baseValue($state, $a) <=> $this->baseValue($state, $b));

        return array_slice($hand, 0, 2);
    }

    private function baseValue(BoardState $state, int $cardId): int
    {
        return $state->catalogRow($state->effectiveCardId($cardId))['baseValue'];
    }

    /** @return ?array<string, mixed> */
    private function buildChoicesForCard(BoardState $state, int $cardId, int $botGamePlayerId): ?array
    {
        $effectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];
        $choices = [];

        foreach (CardChoiceSchema::forEffectKey($effectKey) as $field) {
            $required = ($field['required'] ?? false) === true;
            $forced = !$required && (
                $this->resolver->isAlwaysFilledOptionalField($effectKey, $field['key'])
                || $this->shouldAttemptValueBoostDiscard($state, $effectKey, $field['key'], $cardId, $botGamePlayerId)
            );
            if (!$required && !$forced) {
                continue;
            }

            $value = $this->resolver->resolve($state, $field, $botGamePlayerId, $cardId, $effectKey, $forced);
            if ($value === null) {
                // A required field with no legal value makes the whole
                // card unplayable this way (existing behavior). A forced
                // OPTIONAL field (see ALWAYS_FILLED_OPTIONAL_FIELDS) with
                // no legal candidate -- e.g. Suspicion when every other
                // player's hand is empty -- just stays unfilled instead;
                // the card is still perfectly playable without it, same
                // as if it had never been forced at all.
                if ($required) {
                    return null;
                }

                continue;
            }

            $choices[$field['key']] = $value;
        }

        return $choices;
    }

    /**
     * Whether the bot should volunteer for one of
     * HAND_DISCARD_VALUE_BOOST_EFFECT_BOOSTED_VALUES' own effect keys'
     * optional discard_card_id field at all -- unlike every other
     * optional field (never filled) or ALWAYS_FILLED_OPTIONAL_FIELDS
     * (always filled), this one scales with both the value of actually
     * winning and the cost of giving up a spare card:
     * - Always yes if discarding would make the difference between NOT
     *   currently having the highest score in the game and having it
     *   (see wouldBecomeHighestScore()) -- worth spending down to the
     *   bot's very last spare card for an actual win.
     * - Otherwise (not decisive either way), a policy driven purely by
     *   how many OTHER cards are in hand (hand size minus the card being
     *   played, which is still sitting in hand at this point, same as
     *   BotChoiceResolver's own $ownCardId exclusion): never with only 1
     *   spare, always with 4+ spare, and linearly in between --
     *   (otherCards - 1) / 3, i.e. 1/3 at 2 spare, 2/3 at 3 spare. The 1-
     *   and 4+-spare cases are handled as flat returns rather than folded
     *   into the same formula so they're genuinely unconditional (no
     *   floating-point roll at all), matching "should always"/"only if"
     *   in the literal sense rather than "almost always"/"almost never".
     */
    private function shouldAttemptValueBoostDiscard(BoardState $state, string $effectKey, string $fieldKey, int $cardId, int $botGamePlayerId): bool
    {
        $boostedValue = self::HAND_DISCARD_VALUE_BOOST_EFFECT_BOOSTED_VALUES[$effectKey] ?? null;
        if ($boostedValue === null || $fieldKey !== 'discard_card_id') {
            return false;
        }

        if ($this->wouldBecomeHighestScore($state, $botGamePlayerId, $this->baseValue($state, $cardId), $boostedValue)) {
            return true;
        }

        $otherCardsInHand = count($state->hand($botGamePlayerId)) - 1;
        if ($otherCardsInHand >= 4) {
            return true;
        }
        if ($otherCardsInHand <= 1) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < ($otherCardsInHand - 1) / 3;
    }

    /**
     * Whether boosting $cardId's own value from $unboostedValue to
     * $boostedValue would move the bot's own group (itself, plus a
     * teammate in Open/Closed Team Play -- see BoardState::isTeammate())
     * from BELOW the best rival group's current round score to AT OR
     * ABOVE it. Uses RoundScorer::score() against $state as it stands
     * right now -- $cardId is still in hand at this point (chooseAction()
     * builds this whole request before submitting it), so neither its
     * unboosted nor boosted value is reflected in anyone's total yet;
     * both are added in here by hand instead of read back off the board.
     *
     * A "group" is the acting player plus any isTeammate() of theirs;
     * every remaining player is grouped among THEMSELVES the same way
     * (so a genuine opposing team's combined total is compared as one
     * number, not two separately), and the highest such rival group
     * total is the bar to clear. Reduces to a plain highest-individual-
     * score comparison for every non-team format, since isTeammate() is
     * always false there.
     */
    private function wouldBecomeHighestScore(BoardState $state, int $botGamePlayerId, int $unboostedValue, int $boostedValue): bool
    {
        $scores = (new RoundScorer())->score($state);
        $activeIds = $state->activePlayerOrder();

        $myGroupIds = array_values(array_filter(
            $activeIds,
            fn (int $id): bool => $id === $botGamePlayerId || $state->isTeammate($botGamePlayerId, $id),
        ));
        $myTotal = array_sum(array_map(fn (int $id) => $scores[$id] ?? 0, $myGroupIds));

        $rivalIds = array_values(array_diff($activeIds, $myGroupIds));
        $groupedRivalIds = [];
        $rivalBest = 0;
        foreach ($rivalIds as $id) {
            if (in_array($id, $groupedRivalIds, true)) {
                continue;
            }
            $group = array_values(array_filter(
                $rivalIds,
                fn (int $other): bool => $other === $id || $state->isTeammate($id, $other),
            ));
            $groupedRivalIds = array_merge($groupedRivalIds, $group);
            $rivalBest = max($rivalBest, array_sum(array_map(fn (int $gid) => $scores[$gid] ?? 0, $group)));
        }

        return $myTotal + $unboostedValue < $rivalBest && $myTotal + $boostedValue >= $rivalBest;
    }
}
