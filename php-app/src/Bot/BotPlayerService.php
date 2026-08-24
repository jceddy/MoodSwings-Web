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
 * field-filling policy this builds on -- with sixteen deliberate
 * exceptions: shouldAttemptValueBoostDiscard() below, a scoring-aware,
 * partly probabilistic policy for Dignity/Embarrassment/Cheer/Delight's
 * own "you may discard a card to boost this mood's value" choice; the
 * draft-pick family (chooseDraftCards()/chooseWinstonAction()/
 * chooseGridLine()/chooseDraftDeck(), issue #359) below, which reads
 * externally-curated data (CardCatalog's own draft_priority_score/
 * synergy partners, see migration 0143) plus CardStatsService's deck
 * win rate instead of just a card's own printed value -- there's no
 * BoardState yet during drafting for the "highest printed value" bias
 * every other method here uses to even read from;
 * rationalizationChoices()/sortPriorityValue() (confirmed by the
 * maintainer), which both decide WHICH of Rationalization's two optional
 * modes to commit to (never leaving it unchosen -- a no-op play the way
 * every other unforced-optional-field card here would default to) and
 * deprioritize playing it at all except when doing so pays off (a weak
 * remaining hand, or an overstuffed seat neighbor worth taking cards
 * from), rather than leading with it purely by printed value;
 * cynicismChoices()/sortPriorityValue() again (confirmed by the
 * maintainer), which similarly deprioritize Cynicism unless a cheap
 * discard-pile card is available to boost it for free, the round's own
 * score makes playing it (even unboosted) the deciding difference with
 * nothing else able to swing it as much, or (an addendum, also
 * confirmed by the maintainer) nothing else playable offers a 3+-point
 * swing OR interacts with an opponent's hand either -- see
 * cynicismHasAGoodReasonToPlayNow()'s own docblock; and
 * EARLY_PRIORITY_EFFECT_KEYS/sortPriorityValue() once more (confirmed
 * by the maintainer, the same addendum), a flat priority bonus for
 * every card that steals from an opponent's hand, forces one or more
 * opponents to discard from their own hand, or grants the acting player
 * an extra play -- see EARLY_PRIORITY_EFFECT_KEYS's own docblock for the
 * full list and why each card qualifies; shouldAttemptZealCycle()
 * (confirmed by the maintainer), which volunteers Zeal's own optional
 * "bottom a hand card, then draw a replacement" field whenever the
 * bot's own cheapest OTHER hand card is cheap enough to gamble on a
 * random replacement for -- see that method's own docblock; and
 * intimidationTargetPlayerId()/sortPriorityValue() once more (confirmed
 * by the maintainer), which always targets the first active, non-
 * teammate opponent with a card in hand when playing Intimidation, and
 * deprioritizes it (the same PHP_INT_MIN treatment as Rationalization/
 * Cynicism) whenever no such opponent currently exists -- see that
 * method's own docblock; paranoiaTargetPlayerId()/sortPriorityValue()
 * once more (confirmed by the maintainer, the identical policy), doing
 * the exact same thing for Paranoia -- see that method's own docblock;
 * and pacifismTargetMoodIds()/sortPriorityValue() once more (confirmed
 * by the maintainer), which targets up to two non-teammate opponents'
 * own highest-value in-play moods (preferring two different opponents
 * over a single opponent's own two moods, which CardChoiceSchema's own
 * distinct_owners constraint forbids anyway) when playing Pacifism, and
 * deprioritizes it (the same PHP_INT_MIN treatment) whenever no
 * non-teammate opponent currently has any mood in play -- see that
 * method's own docblock; and disillusionmentSafeColor()/
 * chooseDecisionAnswer() (confirmed by the maintainer), which picks the
 * first color that matches none of the responding bot's own (or a
 * teammate's) moods currently in play when answering Disillusionment's
 * own per-player "choose a color" decision, rather than this class's
 * usual "never volunteer for an optional field" default -- see that
 * method's own docblock; and creativityBestCopyTargetId() (confirmed by
 * the maintainer), which targets generally the highest-value mood
 * currently in play (any owner) when playing Creativity, skipping any
 * candidate whose own printed ability has a "to play" cost the bot might
 * not actually be able to pay -- see that method's own docblock; and
 * sortPriorityValue() once more for Harmony (confirmed by the
 * maintainer), which deprioritizes it (the same PHP_INT_MIN treatment)
 * whenever the discard pile is completely empty -- its own extra-play
 * grant is restricted to a card FROM the discard pile, so with nothing
 * there to take advantage of, playing it accomplishes nothing; and
 * angerTargetMoodIds() (confirmed by the maintainer), which targets the
 * highest-total-value subset of non-teammate opponents' own in-play
 * moods that still fits Anger's own 5-point combined-value ceiling
 * (angerSwingMaximizingTargets()/maxValueSubsetWithinBudget()), PLUS
 * Anger's own just-played card id whenever the bot's own deck has more
 * discard-recursion capacity than every active non-teammate opponent's
 * own deck AND none of them currently has Grace in play
 * (angerShouldAlsoTargetItself()/recursionCardCount()) -- since Anger's
 * own printed value is 0, self-targeting never costs anything against
 * that budget, so it's simply added on top rather than traded off
 * against the swing-maximizing targets; see angerTargetMoodIds()'s own
 * docblock for the full policy; and sneakinessTargetPlayerId()/
 * isWorthPlaying() once more (confirmed by the maintainer), which vetoes
 * Sneakiness outright (the same treatment Fury/Avoidance get above)
 * unless either a non-teammate opponent's own current round score is
 * already MORE than Sneakiness's own printed value (5) ahead of the
 * bot's -- swapping wins the round outright, the whole point of playing
 * it, rather than playing it blind as just another high-value card the
 * way it would be without this exception -- or the round's own scoring
 * is already being skipped entirely (Awe or similar,
 * BoardState::skipScoringOwnerId()), in which case the swap can never
 * even apply this round, so it's played purely for the value it'll keep
 * contributing every round after this one instead; see that method's
 * own docblock for the full policy; and sortPriorityValue() once more
 * for Nostalgia (confirmed by the maintainer), the same PHP_INT_MIN
 * treatment as Harmony above, whenever the discard pile is completely
 * empty -- its own "you may put a card from the discard pile into your
 * hand" half of the effect has nothing to take from, and playing it
 * purely for its own separate (unconditional, unrestricted) extra-play
 * grant is exactly what leading with it via EARLY_PRIORITY_EFFECT_KEYS
 * already does whenever the pile ISN'T empty, so deprioritizing it here
 * only ever gives up the discard-pickup half, never the extra play
 * itself, which chooseAction()'s own "deprioritized WHEN, never skipped
 * outright" ordering still plays if nothing better is available; and
 * denialTargetMoodIds() (confirmed by the maintainer), which targets
 * two same-color-or-same-value in-play moods for Denial, in priority
 * order: first, any pair of non-teammate opponents' own moods (either
 * one opponent's two, or one each from two different opponents) whose
 * combined removal would win the round outright for the bot's own
 * group (denialWinningTargetMoodIds()/denialWouldWinRoundByRemoving(),
 * the same RoundScorer()-based group-scoring approach
 * wouldBecomeHighestScore() above already uses, just subtracting each
 * target's own live value from ITS owner's group instead of adding to
 * the bot's) -- preferring the highest-combined-value qualifying pair
 * over the first one found; failing that, one of the bot's own
 * low-printed-value (0-2) moods with its own "after playing this mood"
 * ability, paired with whatever else qualifies (denialReplayTargetMoodIds(),
 * preferring a SECOND own low-value after-playing mood over any other
 * partner, for a double payoff, then an opponent's mood over sacrificing
 * a different one of the bot's own moods as filler) -- returning both
 * to their owners' hands lets the bot replay its own cheap card for its
 * ability all over again, since MoodPlayService re-runs a mood's own
 * afterPlaying() hook in full every time it's played, with no once-per-
 * game memory of it; see denialTargetMoodIds()'s own docblock for the
 * full policy.
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

    /** AngerEffect::MAX_TOTAL_VALUE, mirrored here the same way HAND_DISCARD_VALUE_BOOST_EFFECT_BOOSTED_VALUES's own 5s already hand-pick a card-specific value rather than exposing the effect class's own private constant just for this. */
    private const ANGER_DISCARD_BUDGET = 5;

    /**
     * Every effect key whose printed ability lets its owner actually PLAY
     * a mood from the discard pile -- Harmony/Grief/Angst (a one-shot
     * discard-sourced extra play granted after playing them), Grace (the
     * same grant, but perpetual, every turn while in play), Melancholy
     * (lets its owner treat the whole discard pile as part of their hand
     * for every play, not just a dedicated bonus one), and Nostalgia
     * (returns a discard-pile card straight to hand, from which it can be
     * played normally). Used only by recursionCardCount() below, for
     * angerShouldAlsoTargetItself()'s own "does this player have enough
     * recursion to plausibly get back to a self-discarded Anger" check
     * (confirmed by the maintainer).
     *
     * @var string[]
     */
    private const RECURSION_EFFECT_KEYS = ['harmony', 'grief', 'angst', 'grace', 'melancholy', 'nostalgia'];

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
            fn (int $a, int $b) => $this->sortPriorityValue($state, $b, $botGamePlayerId, $playableCardIds) <=> $this->sortPriorityValue($state, $a, $botGamePlayerId, $playableCardIds),
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
     * effect no single choice_field value could capture on its own: for
     * Fury, there's no play-time choice_field to hang a veto on at all
     * (its own "each player chooses..." decisions are all
     * RequiresOpponentDecision, resolved only after the card is already
     * played -- see CardChoiceSchema's own docblock for why an effect
     * like that carries no play-time choice_fields); for Avoidance
     * (confirmed by the maintainer), there IS a play-time field
     * ('direction', required -- see avoidanceBestDirection() below for
     * how that one gets chosen), but "is this worth playing AT ALL"
     * depends on every seated player's own moods, not just which
     * direction ends up picked, so it needs the same whole-board view
     * Fury's veto does. Keyed by effect key; everything not listed here
     * is always worth playing (the default, unconditional "yes" every
     * other effect already got before this method existed).
     */
    private function isWorthPlaying(BoardState $state, string $effectKey, int $botGamePlayerId): bool
    {
        return match ($effectKey) {
            'fury' => $this->furyIsWorthPlaying($state, $botGamePlayerId),
            'avoidance' => $this->avoidanceHasAGoodReasonToPlay($state, $botGamePlayerId),
            'sneakiness' => $this->sneakinessTargetPlayerId($state, $botGamePlayerId) !== null,
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

    /**
     * The value of whichever of $playerId's own moods AvoidanceEffect's
     * own required-'own'-scope give decision would pick for them --
     * BotChoiceResolver's own "own scope required field" policy always
     * picks the LOWEST-value legal candidate (minimize what's given up),
     * so this is what $playerId (bot or human -- the same rational
     * "give up the least" assumption either way, since there's no way to
     * know a human's actual answer in advance) is expected to actually
     * give if a direction routes Avoidance's own forced exchange through
     * them. 0 (not -1, unlike highestMoodValueOwnedBy()'s own "nothing
     * to lose" sentinel above) for a player with no moods in play at
     * all -- AvoidanceEffect itself never even asks them for an answer
     * (moodsOwnedBy() === [] is skipped outright), so there's genuinely
     * nothing there, the same "free" value avoidanceReceivedValueFor()
     * below needs it to carry.
     */
    private function lowestMoodValueOwnedBy(BoardState $state, int $playerId): int
    {
        $moods = $state->moodsOwnedBy($playerId);
        if ($moods === []) {
            return 0;
        }

        $lowestValue = PHP_INT_MAX;
        foreach ($moods as $mood) {
            $lowestValue = min($lowestValue, $state->valueOf($mood->cardId));
        }

        return $lowestValue;
    }

    /**
     * How weak the bot's own lowest-value mood needs to be before giving
     * it away (Avoidance forces EVERY seated player with at least one
     * mood in play to give one, including whoever plays it) is a low
     * enough cost to be worth risking regardless of what comes back --
     * roughly the real catalog's own overall average base value (~2.3),
     * the same reasoning (and the same threshold) RATIONALIZATION_LOW_VALUE_HAND_AVERAGE
     * above already uses for an analogous "is what I'd be giving up
     * cheap enough not to matter" question.
     */
    private const AVOIDANCE_LOW_VALUE_MOOD_THRESHOLD = 2;

    /**
     * Avoidance's own "is this worth playing at all" policy (confirmed
     * by the maintainer): worth it if EITHER the bot's own cheapest mood
     * to give up is low-value enough not to matter regardless of what
     * comes back (AVOIDANCE_LOW_VALUE_MOOD_THRESHOLD -- including a
     * player with zero moods in play, who has nothing to give up at all
     * and so trivially qualifies), OR at least one direction would
     * receive a mood worth MORE than what the bot itself gives up
     * (avoidanceReceivedValueFor()'s own "what that neighbor would
     * rationally give" estimate, compared against
     * lowestMoodValueOwnedBy() for the bot's own side of the same
     * exchange) -- a genuinely profitable trade even if the bot's own
     * mood isn't cheap. Otherwise Avoidance just trades the bot's own
     * mood for something equal or worse while also handing every OTHER
     * seated player a free swap they didn't ask for -- a pure loss (or
     * at best a wash) worth skipping.
     */
    private function avoidanceHasAGoodReasonToPlay(BoardState $state, int $botGamePlayerId): bool
    {
        $ownGiveValue = $this->lowestMoodValueOwnedBy($state, $botGamePlayerId);
        if ($ownGiveValue <= self::AVOIDANCE_LOW_VALUE_MOOD_THRESHOLD) {
            return true;
        }

        return max(
            $this->avoidanceReceivedValueFor($state, $botGamePlayerId, 'left'),
            $this->avoidanceReceivedValueFor($state, $botGamePlayerId, 'right'),
        ) > $ownGiveValue;
    }

    /**
     * Avoidance's own "which direction" policy (confirmed by the
     * maintainer) -- unlike most required 'mode' fields (BotChoiceResolver's
     * own "take the first option" default), which direction actually
     * matters here, so this is special-cased in buildChoicesForCard()
     * the same way rationalizationChoices() is. Simply whichever
     * direction routes a more valuable mood onto the bot
     * (avoidanceReceivedValueFor()) -- 'left' wins any tie, matching
     * BotChoiceResolver's own "first option" default for every OTHER
     * required mode field.
     */
    private function avoidanceBestDirection(BoardState $state, int $botGamePlayerId): string
    {
        $receivedIfLeft = $this->avoidanceReceivedValueFor($state, $botGamePlayerId, 'left');
        $receivedIfRight = $this->avoidanceReceivedValueFor($state, $botGamePlayerId, 'right');

        return $receivedIfRight > $receivedIfLeft ? 'right' : 'left';
    }

    /**
     * The value the bot would receive if Avoidance resolved with
     * $direction chosen -- AvoidanceEffect moves every giver's own mood
     * to their neighbor IN $direction (giver gives to
     * activeNeighbor(giver, $direction)), so the seat that gives TO the
     * bot is the one on the OPPOSITE side (same "opposite side" mapping
     * rationalizationStealDirection() above already uses for
     * Rationalization's own 'rotate'). 0 if there's no such neighbor
     * (fewer than 2 active players) or that neighbor currently has no
     * moods in play at all (nothing for them to give -- AvoidanceEffect
     * itself skips asking them).
     */
    private function avoidanceReceivedValueFor(BoardState $state, int $botGamePlayerId, string $direction): int
    {
        $giverDirection = $direction === 'left' ? 'right' : 'left';
        $giverId = $state->activeNeighbor($botGamePlayerId, $giverDirection);
        if ($giverId === null) {
            return 0;
        }

        return $this->lowestMoodValueOwnedBy($state, $giverId);
    }

    /** @return array<string, mixed> */
    public function chooseDecisionAnswer(BoardState $state, array $field, int $botGamePlayerId, string $decisionType = ''): array
    {
        if ($decisionType === 'disillusionment_choose_color') {
            $color = $this->disillusionmentSafeColor($state, $field, $botGamePlayerId);

            return $color === null ? [] : [$field['key'] => $color];
        }

        $value = $this->resolver->resolve($state, $field, $botGamePlayerId, 0, '');

        return $value === null ? [] : [$field['key'] => $value];
    }

    /**
     * Disillusionment's own "which color, if any" policy (confirmed by
     * the maintainer) -- every seated player, not just whoever played
     * Disillusionment, gets asked this once it resolves (see
     * DisillusionmentEffect::pendingDecisionsFor()'s own queueOrder()), so
     * $botGamePlayerId here is whichever bot is currently being asked, not
     * necessarily the one who played the mood. A "safe" color is one that
     * matches none of the responding bot's own moods currently in play,
     * nor any teammate's -- DisillusionmentEffect::resolveDecisions()
     * moves EVERY other mood of a chosen color to the discard pile
     * regardless of owner, so picking an unsafe color would gladly thin
     * out opponents' boards while blowing up the bot's own (or its
     * teammate's) at the same time. The first such safe color in
     * $field['options']' own order wins ties -- this class has no finer
     * basis to prefer one safe color over another over the others (unlike
     * avoidanceBestDirection()'s own value-driven tiebreak, nothing here
     * distinguishes an opponent's mood from another's), matching
     * BotChoiceResolver's own "first option" default for every other
     * non-strategic mode field. Null (decline, this field's own pre-
     * existing default before this policy existed) whenever every color
     * matches something the bot or a teammate owns -- there's no way to
     * participate here without also hurting yourself/your team, so this
     * falls back to never volunteering for it at all, the same as any
     * other optional field with a real cost attached.
     */
    private function disillusionmentSafeColor(BoardState $state, array $field, int $botGamePlayerId): ?string
    {
        $unsafeColors = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->ownerId === $botGamePlayerId || $state->isTeammate($botGamePlayerId, $mood->ownerId)) {
                $unsafeColors[] = $state->colorOf($mood->cardId);
            }
        }

        foreach ($field['options'] ?? [] as $color) {
            if (!in_array($color, $unsafeColors, true)) {
                return $color;
            }
        }

        return null;
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
     * this bot ever weighs differently -- but a human confirmer who
     * REJECTS a bot's proposal never gets this same arbitrary pick handed
     * back a second time; see `GameService::advanceBotTeamDecision()`'s
     * own docblock (confirmed by the maintainer) for why that's handled a
     * level up from this method, not here.
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

    /**
     * chooseAction()'s own sort key -- ordinarily just baseValue(), except
     * for Rationalization: rather than always leading with it purely
     * because of its own printed value (3, unremarkable on its own),
     * it's deliberately held back to the BOTTOM of the candidate order
     * (PHP_INT_MIN, guaranteed lower than any real baseValue()) unless
     * rationalizationHasAGoodReasonToPlayNow() says otherwise -- "save it
     * to play last" per the maintainer, so it only actually gets chosen
     * ahead of something else once nothing higher-value is left to play,
     * UNLESS refreshing a weak hand or stealing an overstuffed neighbor's
     * hand is worth doing right away. Never a reason to skip playing it
     * outright, only to deprioritize WHEN -- buildChoicesForCard()'s own
     * rationalizationChoices() always commits to a mode regardless of
     * this ordering.
     *
     * Cynicism (confirmed by the maintainer) gets the identical PHP_INT_MIN
     * treatment unless cynicismHasAGoodReasonToPlayNow() says otherwise --
     * see that method's own docblock. $playableCardIds is threaded
     * through purely for that check's own "does anything else on offer
     * swing the round as much, or interact with an opponent's hand"
     * comparisons, and for EARLY_PRIORITY_EFFECT_KEYS' own bonus below;
     * every other candidate here ignores it.
     *
     * EARLY_PRIORITY_EFFECT_KEYS (confirmed by the maintainer, an
     * addendum to the Cynicism policy above) adds a flat bonus on top of
     * baseValue() for a card that steals from an opponent's hand, forces
     * an opponent to discard, or grants the acting player an extra play
     * -- see that constant's own docblock for the full list and why.
     * Large enough (EARLY_PRIORITY_BONUS) to always outrank an
     * un-boosted card regardless of either one's own printed value,
     * while still ranking sensibly AMONG themselves by that same
     * printed value (a stronger bonus card doesn't lose to a weaker
     * one) -- the same additive-bonus shape SYNERGY_PARTNER_BONUS
     * already uses for draft-pick scoring, just at a scale that fits
     * this method's own single-digit baseValue() range instead of that
     * one's draft_priority_score range.
     *
     * Intimidation (confirmed by the maintainer) gets the same
     * PHP_INT_MIN treatment as Rationalization/Cynicism above, but the
     * condition is simpler: no OTHER currently active, non-teammate
     * player has any card in hand at all right now
     * (intimidationTargetPlayerId() returns null) -- targeting anyone in
     * that state would ask for an empty-handed opponent's own "reveal a
     * card" decision, which IntimidationEffect's own pendingDecisionsFor()
     * already treats as a legal no-op (an empty hand simply has nothing
     * to reveal), so the whole play accomplishes nothing. The instant at
     * least one non-teammate opponent has a card, this reverts to
     * EARLY_PRIORITY_EFFECT_KEYS' own ordinary boosted treatment above
     * -- no separate "how good is this target" scoring the way Cynicism
     * needs, since ANY opponent with a card in hand makes Intimidation
     * worth its usual priority.
     *
     * Paranoia (confirmed by the maintainer, the same policy as
     * Intimidation) gets the identical PHP_INT_MIN treatment whenever
     * paranoiaTargetPlayerId() returns null -- unlike Intimidation,
     * though, ParanoiaEffect::afterPlaying() doesn't quietly no-op
     * against an untargetable player, it throws (a required
     * $state->hand($targetPlayerId) !== [] precondition), so this
     * doesn't just avoid a wasted play, it avoids an illegal one --
     * paranoiaTargetPlayerId() itself is what keeps buildChoicesForCard()
     * from ever handing that exception a bad candidate in the first
     * place. Paranoia isn't in EARLY_PRIORITY_EFFECT_KEYS (it bottoms
     * the taken card, never gaining the acting player's own hand a card
     * the way Compulsion/Intimidation do, and never forces a discard the
     * way Suspicion does), so it's back to plain baseValue() once a
     * valid target exists -- no boost, just no longer vetoed.
     *
     * Pacifism (confirmed by the maintainer, the same policy again) gets
     * the identical PHP_INT_MIN treatment whenever
     * pacifismTargetMoodIds() returns an empty array -- no non-teammate
     * opponent has any mood in play at all right now, so playing it
     * would suppress nothing. Also absent from EARLY_PRIORITY_EFFECT_KEYS
     * -- it denies an opponent's mood rather than stealing a card,
     * forcing a discard, or granting the acting player an extra play --
     * so it too reverts to plain baseValue() once at least one valid
     * target exists.
     *
     * Harmony (confirmed by the maintainer) gets the same PHP_INT_MIN
     * treatment whenever the discard pile is completely empty --
     * HarmonyEffect grants a single extra play restricted to a card
     * FROM the discard pile (BoardState::grantExtraPlay()'s own
     * ['source' => 'discard'] restriction), so with nothing sitting
     * there to take advantage of, the grant accomplishes nothing and
     * Harmony's own EARLY_PRIORITY_EFFECT_KEYS boost below would be
     * rewarding a wasted play. Unlike Intimidation/Paranoia/Pacifism
     * above, this doesn't need a dedicated "any legal candidate"
     * helper of its own -- Harmony's grant has no color/value
     * restriction of its own (just the discard-pile sourcing), so a
     * plain non-empty check is enough, and there's no choice_field to
     * fill in either (HarmonyEffect::afterPlaying() never reads
     * $choices at all, so buildChoicesForCard() needs no special case
     * for it the way those three do). The instant the discard pile has
     * even one card in it, Harmony reverts to its ordinary
     * EARLY_PRIORITY_EFFECT_KEYS boosted treatment above.
     *
     * Nostalgia (confirmed by the maintainer) gets the identical
     * PHP_INT_MIN treatment as Harmony above, whenever the discard pile
     * is completely empty -- NostalgiaEffect's own "you may put a card
     * from the discard pile into your hand" half of the effect has
     * nothing to take from then, the same "the pickup half is dead"
     * situation Harmony's own discard-sourced grant is in. Unlike
     * Harmony, though, Nostalgia's OWN extra-play grant is unconditional
     * and unrestricted (BoardState::grantExtraPlay(sourceCardId: $cardId)
     * with no ['source' => 'discard'] restriction -- see
     * NostalgiaEffect::afterPlaying()), so deprioritizing it here never
     * gives up anything but the (already worthless) discard pickup --
     * the extra play itself is exactly as good with an empty pile as a
     * full one, and chooseAction()'s own "deprioritized WHEN, never
     * skipped outright" ordering still plays Nostalgia purely for that
     * extra play whenever nothing better is available (see
     * testChooseActionStillPlaysHarmonyWhenNothingElseIsPlayable's own
     * Nostalgia counterpart). The instant the discard pile has even one
     * card in it, Nostalgia reverts to its ordinary
     * EARLY_PRIORITY_EFFECT_KEYS boosted treatment above (it's already
     * listed there).
     */
    private function sortPriorityValue(BoardState $state, int $cardId, int $botGamePlayerId, array $playableCardIds): int
    {
        $effectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];
        if ($effectKey === 'rationalization' && !$this->rationalizationHasAGoodReasonToPlayNow($state, $cardId, $botGamePlayerId)) {
            return PHP_INT_MIN;
        }
        if ($effectKey === 'cynicism' && !$this->cynicismHasAGoodReasonToPlayNow($state, $cardId, $botGamePlayerId, $playableCardIds)) {
            return PHP_INT_MIN;
        }
        if ($effectKey === 'intimidation' && $this->intimidationTargetPlayerId($state, $botGamePlayerId) === null) {
            return PHP_INT_MIN;
        }
        if ($effectKey === 'paranoia' && $this->paranoiaTargetPlayerId($state, $botGamePlayerId) === null) {
            return PHP_INT_MIN;
        }
        if ($effectKey === 'pacifism' && $this->pacifismTargetMoodIds($state, $botGamePlayerId) === []) {
            return PHP_INT_MIN;
        }
        if ($effectKey === 'harmony' && $state->discardPile() === []) {
            return PHP_INT_MIN;
        }
        if ($effectKey === 'nostalgia' && $state->discardPile() === []) {
            return PHP_INT_MIN;
        }

        $priority = $this->baseValue($state, $cardId);
        if (in_array($effectKey, self::EARLY_PRIORITY_EFFECT_KEYS, true)) {
            $priority += self::EARLY_PRIORITY_BONUS;
        }

        return $priority;
    }

    /**
     * Every effect key that steals a card from an opponent's hand into
     * the acting player's own hand (Compulsion, Intimidation -- not
     * Confusion/Rationalization's own 'rotate', which redistribute every
     * seated player's hand symmetrically rather than a one-directional
     * gain, and not Regret/Guile, which take an opponent's MOOD already
     * in play rather than a hand card), forces one or more OTHER players
     * to discard from their own hand (Suspicion -- the only card in the
     * catalog that does; every other forced-discard-shaped effect either
     * targets a mood already in play, or discards from the ACTING
     * player's own hand as a self-paid cost), or grants the acting
     * player (or, while in play, keeps granting every one of their own
     * turns) an extra play -- confirmed by the maintainer as cards worth
     * leading with rather than ordering purely by printed value, the
     * same way a bigger hand/tempo advantage would matter to a human
     * player. Every extra-play grant here is listed regardless of
     * whether it's unconditional (Charity, Duplicity, Idealism,
     * Validation, Ambition, Bravado, Fear, Nostalgia, Gluttony,
     * Insecurity, Angst, Harmony, Grief, Thrill, Joy) or conditional on
     * the NEXT play meeting some restriction (Benevolence, Eagerness,
     * Friendliness, Kindness, Pride, Intimidation's own restriction to
     * the one card just taken) or an ongoing while-in-play grant instead
     * of a one-time one (Hope, Grace, Stubbornness) -- "legal, not
     * strategic" stops short of predicting whether a conditional grant
     * will actually be used. Generosity deliberately excluded: it grants
     * its own extra play to a chosen OPPONENT, not the acting player, so
     * leading with it would help whoever's targeted, not the bot itself.
     *
     * @var string[]
     */
    private const EARLY_PRIORITY_EFFECT_KEYS = [
        // steals from an opponent's hand
        'compulsion', 'intimidation',
        // forces one or more opponents to discard from their own hand
        'suspicion',
        // grants the acting player an extra play
        'charity', 'duplicity', 'idealism', 'validation', 'ambition', 'bravado', 'fear', 'nostalgia',
        'gluttony', 'insecurity', 'angst', 'harmony', 'grief', 'thrill', 'benevolence', 'eagerness',
        'friendliness', 'kindness', 'pride', 'hope', 'grace', 'stubbornness', 'joy',
    ];

    /**
     * @see EARLY_PRIORITY_EFFECT_KEYS's own docblock. Comfortably above
     * any real baseValue() (the catalog's own values top out around
     * 6) so a boosted card always outranks an un-boosted one regardless
     * of either one's own printed value.
     */
    private const EARLY_PRIORITY_BONUS = 10;

    /**
     * Issue #359's own draft practice bots' pick-priority bonus for a
     * card that partners one of the 5 build-around mythics (see
     * CardCatalog::loadSynergyPartnersByMythicId()'s own docblock) the
     * bot has already drafted -- large enough (roughly the very top
     * draft_priority_score tier) that a partner reliably beats an
     * unrelated card of similar raw quality, without letting a
     * genuinely weak filler card (draft_priority_score 1) leapfrog a
     * strong generic card it isn't even close to (e.g. Paranoia's own
     * 20). Stacks once per already-drafted mythic a card partners --
     * rare in practice, since most draft formats only ever award a
     * single mythic total, but a card that supports two drafted
     * build-arounds really is better than one that supports only one.
     */
    private const SYNERGY_PARTNER_BONUS = 40.0;

    /**
     * The fewest recorded deck appearances (CardStatsService::
     * deckWinRatesByCardId()) before a card's own win rate is trusted
     * enough to even nudge a draft pick -- below this, a small sample
     * could read 100% or 0% off a single game, noise indistinguishable
     * from signal.
     */
    private const MIN_DECK_STATS_SAMPLE = 10;

    /**
     * Win rate's own max contribution to a draft candidate's score --
     * kept comfortably under 1.0 (the smallest gap between two distinct
     * draft_priority_score tiers, e.g. 1 vs 2) so it can only ever break
     * a tie between cards that already landed on the exact same rank +
     * synergy score, never override the curated ranking/synergy data
     * itself.
     */
    private const DECK_WIN_RATE_WEIGHT = 0.9;

    /**
     * A draft candidate's own pick priority for issue #359's practice
     * bots, combining the three signals confirmed by the maintainer:
     * CardCatalog's own curated draft_priority_score is the primary,
     * always-present signal; SYNERGY_PARTNER_BONUS is added once for
     * every already-drafted mythic (from $draftedCardIds) $cardId
     * partners with; and the card's own recorded CardStatsService deck
     * win rate nudges the score by up to DECK_WIN_RATE_WEIGHT, but only
     * once it has at least MIN_DECK_STATS_SAMPLE games on record --
     * purely a tiebreaker among cards the curated data alone can't
     * separate, never something that outranks it.
     *
     * @param int[] $draftedCardIds every card the bot has already
     *     drafted this match (checked for synergy partnerships, not
     *     re-scored itself)
     * @param array<int, array{draftPriorityScore: int}> $catalogRowsById
     * @param array<int, int[]> $synergyPartnersByMythicId mythic card id
     *     => that mythic's own partner card ids
     * @param array<int, array{times_in_deck: int, deck_win_rate: ?float}> $deckWinRatesByCardId
     */
    private function draftCardScore(
        int $cardId,
        array $draftedCardIds,
        array $catalogRowsById,
        array $synergyPartnersByMythicId,
        array $deckWinRatesByCardId,
    ): float {
        $score = (float) ($catalogRowsById[$cardId]['draftPriorityScore'] ?? 1);

        foreach ($draftedCardIds as $draftedCardId) {
            if (in_array($cardId, $synergyPartnersByMythicId[$draftedCardId] ?? [], true)) {
                $score += self::SYNERGY_PARTNER_BONUS;
            }
        }

        $stats = $deckWinRatesByCardId[$cardId] ?? null;
        if ($stats !== null && $stats['deck_win_rate'] !== null && $stats['times_in_deck'] >= self::MIN_DECK_STATS_SAMPLE) {
            $score += $stats['deck_win_rate'] * self::DECK_WIN_RATE_WEIGHT;
        }

        return $score;
    }

    /**
     * The $count highest-draftCardScore() candidates in $candidateCardIds
     * -- Quick Draft's per-stage pile (QUICK_DRAFT_KEEP_PER_STAGE > 1),
     * Rotisserie/Tiered Rotisserie Draft's shared pool ($count always 1
     * there), all shaped identically for this purpose: a flat list of
     * legal card ids to pick $count of. $candidateCardIds always has at
     * least $count entries (every caller already confirmed that much
     * legal supply exists before calling this).
     *
     * @param int[] $candidateCardIds
     * @param int[] $draftedCardIds
     * @param array{rowsById: array<int, array{draftPriorityScore: int}>, synergyPartnersByMythicId: array<int, int[]>, deckWinRatesByCardId: array<int, array{times_in_deck: int, deck_win_rate: ?float}>} $draftScoringData
     * @return int[] exactly $count card ids
     */
    public function chooseDraftCards(array $candidateCardIds, int $count, array $draftedCardIds, array $draftScoringData): array
    {
        usort($candidateCardIds, fn (int $a, int $b) => $this->draftCardScore(
            $b,
            $draftedCardIds,
            $draftScoringData['rowsById'],
            $draftScoringData['synergyPartnersByMythicId'],
            $draftScoringData['deckWinRatesByCardId'],
        ) <=> $this->draftCardScore(
            $a,
            $draftedCardIds,
            $draftScoringData['rowsById'],
            $draftScoringData['synergyPartnersByMythicId'],
            $draftScoringData['deckWinRatesByCardId'],
        ));

        return array_slice($candidateCardIds, 0, $count);
    }

    /**
     * Winston Draft's own take/pass decision (submitWinstonDraftPick())
     * on the single currently active pile -- 'take' if the pile's own
     * best card scores above the catalog-wide average draft_priority_score
     * (computed from $draftScoringData itself rather than a hardcoded
     * cutoff, so this stays sensible if the underlying ranking data is
     * ever rebalanced), 'pass' otherwise. A pile only ever grows as it's
     * passed, so this never throws away a pile that's merely small --
     * only one whose cards genuinely aren't worth having yet. Whether
     * 'pass' is even legal right now (Winston Draft forces a take once
     * there's nowhere left to pass to) is GameService's own call, not
     * this method's -- it always answers the pure "is this pile good
     * enough" question and lets the caller downgrade an illegal 'pass'
     * to 'take' itself.
     *
     * @param int[] $pileCardIds never empty
     * @param int[] $draftedCardIds
     * @param array{rowsById: array<int, array{draftPriorityScore: int}>, synergyPartnersByMythicId: array<int, int[]>, deckWinRatesByCardId: array<int, array{times_in_deck: int, deck_win_rate: ?float}>} $draftScoringData
     */
    public function chooseWinstonAction(array $pileCardIds, array $draftedCardIds, array $draftScoringData): string
    {
        $rowsById = $draftScoringData['rowsById'];
        $averageScore = array_sum(array_map(
            static fn (array $row): int => $row['draftPriorityScore'],
            $rowsById,
        )) / count($rowsById);

        $bestPileScore = max(array_map(
            fn (int $cardId) => $this->draftCardScore(
                $cardId,
                $draftedCardIds,
                $rowsById,
                $draftScoringData['synergyPartnersByMythicId'],
                $draftScoringData['deckWinRatesByCardId'],
            ),
            $pileCardIds,
        ));

        return $bestPileScore > $averageScore ? 'take' : 'pass';
    }

    /**
     * Grid Draft's own row/column choice (submitGridDraftPick()) -- the
     * candidate line with the highest TOTAL draftCardScore() across its
     * own non-null cells (a null cell, already taken earlier in the
     * round, contributes nothing), rather than an average -- a longer
     * line of decent cards is worth more than a single great one, the
     * same "every card in the line comes with it" shape a human drafter
     * actually gets.
     *
     * @param array<int, array{axis: string, index: int, cardIds: int[]}> $candidateLines
     *     every legal row/column (at least one non-null cell); $cardIds
     *     already excludes null cells
     * @param int[] $draftedCardIds
     * @param array{rowsById: array<int, array{draftPriorityScore: int}>, synergyPartnersByMythicId: array<int, int[]>, deckWinRatesByCardId: array<int, array{times_in_deck: int, deck_win_rate: ?float}>} $draftScoringData
     * @return array{axis: string, index: int}
     */
    public function chooseGridLine(array $candidateLines, array $draftedCardIds, array $draftScoringData): array
    {
        $lineScore = function (array $line) use ($draftedCardIds, $draftScoringData): float {
            return array_sum(array_map(
                fn (int $cardId) => $this->draftCardScore(
                    $cardId,
                    $draftedCardIds,
                    $draftScoringData['rowsById'],
                    $draftScoringData['synergyPartnersByMythicId'],
                    $draftScoringData['deckWinRatesByCardId'],
                ),
                $line['cardIds'],
            ));
        };

        usort($candidateLines, fn (array $a, array $b) => $lineScore($b) <=> $lineScore($a));

        return ['axis' => $candidateLines[0]['axis'], 'index' => $candidateLines[0]['index']];
    }

    /**
     * A bot's own drafted-pool trim into a legal deck (submitDraftDeck())
     * -- the $minDeckSize highest-draftCardScore() cards in
     * $draftedCardIds, scored against the WHOLE final pool as its own
     * $draftedCardIds context (so a partner card correctly gets credit
     * for whichever mythics ended up in the same pool, exactly the
     * synergy pairing the deck should keep together) -- or every drafted
     * card, if there are fewer than $minDeckSize (array_slice's own
     * "shorter than requested" behavior already handles that with no
     * extra check needed; every real draft format's own pool ends up
     * well above its $minDeckSize regardless). Submitted unchanged for
     * every game of a best-of-three match -- see submitDraftDeck()'s own
     * "no sideboarding" note for bots.
     *
     * @param int[] $draftedCardIds
     * @param array{rowsById: array<int, array{draftPriorityScore: int}>, synergyPartnersByMythicId: array<int, int[]>, deckWinRatesByCardId: array<int, array{times_in_deck: int, deck_win_rate: ?float}>} $draftScoringData
     * @return int[]
     */
    public function chooseDraftDeck(array $draftedCardIds, int $minDeckSize, array $draftScoringData): array
    {
        $sorted = $draftedCardIds;
        usort($sorted, fn (int $a, int $b) => $this->draftCardScore(
            $b,
            $draftedCardIds,
            $draftScoringData['rowsById'],
            $draftScoringData['synergyPartnersByMythicId'],
            $draftScoringData['deckWinRatesByCardId'],
        ) <=> $this->draftCardScore(
            $a,
            $draftedCardIds,
            $draftScoringData['rowsById'],
            $draftScoringData['synergyPartnersByMythicId'],
            $draftScoringData['deckWinRatesByCardId'],
        ));

        return array_slice($sorted, 0, $minDeckSize);
    }

    /** @return ?array<string, mixed> */
    private function buildChoicesForCard(BoardState $state, int $cardId, int $botGamePlayerId): ?array
    {
        $effectKey = $state->catalogRow($state->effectiveCardId($cardId))['effectKey'];

        if ($effectKey === 'rationalization') {
            return $this->rationalizationChoices($state, $cardId, $botGamePlayerId);
        }

        if ($effectKey === 'avoidance') {
            return ['direction' => $this->avoidanceBestDirection($state, $botGamePlayerId)];
        }

        if ($effectKey === 'cynicism') {
            return $this->cynicismChoices($state, $botGamePlayerId);
        }

        if ($effectKey === 'intimidation') {
            $targetPlayerId = $this->intimidationTargetPlayerId($state, $botGamePlayerId);

            return $targetPlayerId !== null ? ['target_player_id' => $targetPlayerId] : [];
        }

        if ($effectKey === 'paranoia') {
            $targetPlayerId = $this->paranoiaTargetPlayerId($state, $botGamePlayerId);

            return $targetPlayerId !== null ? ['target_player_id' => $targetPlayerId] : [];
        }

        if ($effectKey === 'pacifism') {
            $targetMoodIds = $this->pacifismTargetMoodIds($state, $botGamePlayerId);

            return $targetMoodIds !== [] ? ['target_mood_ids' => $targetMoodIds] : [];
        }

        if ($effectKey === 'creativity') {
            $copyTargetCardId = $this->creativityBestCopyTargetId($state);

            return $copyTargetCardId !== null ? ['copy_card_id' => $copyTargetCardId] : [];
        }

        if ($effectKey === 'anger') {
            return ['target_mood_ids' => $this->angerTargetMoodIds($state, $cardId, $botGamePlayerId)];
        }

        if ($effectKey === 'denial') {
            $targetMoodIds = $this->denialTargetMoodIds($state, $cardId, $botGamePlayerId);

            return $targetMoodIds !== [] ? ['target_mood_ids' => $targetMoodIds] : [];
        }

        if ($effectKey === 'sneakiness') {
            $targetPlayerId = $this->sneakinessTargetPlayerId($state, $botGamePlayerId);

            return $targetPlayerId !== null ? ['opponent_player_id' => $targetPlayerId] : null;
        }

        $choices = [];

        foreach (CardChoiceSchema::forEffectKey($effectKey) as $field) {
            $required = ($field['required'] ?? false) === true;
            $forced = !$required && (
                $this->resolver->isAlwaysFilledOptionalField($effectKey, $field['key'])
                || $this->shouldAttemptValueBoostDiscard($state, $effectKey, $field['key'], $cardId, $botGamePlayerId)
                || $this->shouldAttemptZealCycle($state, $effectKey, $field['key'], $cardId, $botGamePlayerId)
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
     * A remaining hand this weak or weaker (average base value, not
     * counting $cardId itself -- Rationalization is still sitting in
     * hand at the point this is evaluated, see chooseAction()'s own
     * pre-play $state) is worth gambling on a fresh draw over. Roughly
     * the real catalog's own overall average base value (~2.3), rounded
     * down -- a hand at or below that is no better than what a random
     * draw would typically look like anyway, so there's little to lose.
     * An empty remaining hand (Rationalization was the bot's only card)
     * also counts as "low" (average of an empty set is defined as 0
     * here), which is exactly right: refreshing an empty hand is free.
     */
    private const RATIONALIZATION_LOW_VALUE_HAND_AVERAGE = 2;

    /**
     * How many MORE cards a seat neighbor needs over the bot's own
     * current hand size before 'rotate' toward them is worth it --
     * below this, giving away the bot's own whole hand (rotate moves
     * EVERY seated player's hand, not just a private trade with one
     * opponent -- see RationalizationEffect's own docblock) isn't
     * clearly a net gain once the bot's own cards are considered lost
     * too.
     */
    private const RATIONALIZATION_STEAL_HAND_SIZE_ADVANTAGE = 3;

    /**
     * Rationalization's own "which mode, if any" policy (confirmed by
     * the maintainer) -- separate from the generic per-field
     * CardChoiceSchema loop above, since its two fields are
     * interdependent ('direction' only means anything once 'mode' is
     * 'rotate') and the choice between them needs real board state, not
     * just "first legal option". "You may choose one" is never left
     * unchosen here -- doing nothing at all (BotChoiceResolver's own
     * default for an unforced optional field) is strictly worse than
     * either real option, so this always commits to one:
     * - 'refresh' (bottom the whole hand, then redraw that many) once
     *   the bot's own remaining hand is weak enough to gamble on a
     *   fresh draw (rationalizationLowValueHand()) -- checked first,
     *   since it's always safe (nothing is ever given away to an
     *   opponent) regardless of what any neighbor's hand size looks
     *   like.
     * - Otherwise 'rotate' toward whichever neighbor
     *   rationalizationStealDirection() finds currently overstuffed
     *   enough to be worth taking their hand at the cost of the bot's
     *   own.
     * - Otherwise, still 'refresh' -- the strictly safer default of the
     *   two whenever neither trigger applies (sortPriorityValue() is
     *   what keeps the bot from reaching for this card at all in that
     *   case, not this method; once it IS being played, refresh never
     *   costs the bot anything an unwarranted rotate could).
     *
     * @return array{mode: string}|array{mode: string, direction: string}
     */
    private function rationalizationChoices(BoardState $state, int $cardId, int $botGamePlayerId): array
    {
        if ($this->rationalizationLowValueHand($state, $cardId, $botGamePlayerId)) {
            return ['mode' => 'refresh'];
        }

        $direction = $this->rationalizationStealDirection($state, $botGamePlayerId);
        if ($direction !== null) {
            return ['mode' => 'rotate', 'direction' => $direction];
        }

        return ['mode' => 'refresh'];
    }

    /** @see RATIONALIZATION_LOW_VALUE_HAND_AVERAGE */
    private function rationalizationLowValueHand(BoardState $state, int $cardId, int $botGamePlayerId): bool
    {
        $remainingHand = array_values(array_diff($state->hand($botGamePlayerId), [$cardId]));
        if ($remainingHand === []) {
            return true;
        }

        $total = array_sum(array_map(fn (int $handCardId) => $this->baseValue($state, $handCardId), $remainingHand));

        return $total / count($remainingHand) <= self::RATIONALIZATION_LOW_VALUE_HAND_AVERAGE;
    }

    /**
     * Which direction, if any, would rotate an overstuffed seat
     * neighbor's hand onto the bot -- 'rotate' passes every seated
     * player's hand to their own neighbor in ONE shared direction (not
     * a private trade with a single opponent), so the neighbor whose
     * hand the bot actually RECEIVES under direction $d is whichever one
     * would give TO the bot under $d, i.e. the neighbor on the OPPOSITE
     * side (see activeNeighbor()'s own "left is index+1" docblock: if a
     * neighbor sits at the bot's own 'right', direction 'left' is what
     * makes THAT neighbor's own 'left' pass land on the bot, and vice
     * versa). Returns whichever of the two qualifies (at least
     * RATIONALIZATION_STEAL_HAND_SIZE_ADVANTAGE more cards than the
     * bot's own current hand) and holds the larger hand, if both do; null
     * if neither seat neighbor currently qualifies (a heads-up duel has
     * only one "neighbor" either direction resolves to the same single
     * opponent, so both checks simply agree there).
     */
    private function rationalizationStealDirection(BoardState $state, int $botGamePlayerId): ?string
    {
        $ownHandSize = count($state->hand($botGamePlayerId));

        $bestDirection = null;
        $bestGiverHandSize = -1;
        foreach (['left', 'right'] as $direction) {
            $giverDirection = $direction === 'left' ? 'right' : 'left';
            $giverId = $state->activeNeighbor($botGamePlayerId, $giverDirection);
            if ($giverId === null) {
                continue;
            }

            $giverHandSize = count($state->hand($giverId));
            if ($giverHandSize >= $ownHandSize + self::RATIONALIZATION_STEAL_HAND_SIZE_ADVANTAGE && $giverHandSize > $bestGiverHandSize) {
                $bestDirection = $direction;
                $bestGiverHandSize = $giverHandSize;
            }
        }

        return $bestDirection;
    }

    /** @see sortPriorityValue()'s own docblock for how this is used. */
    private function rationalizationHasAGoodReasonToPlayNow(BoardState $state, int $cardId, int $botGamePlayerId): bool
    {
        return $this->rationalizationLowValueHand($state, $cardId, $botGamePlayerId)
            || $this->rationalizationStealDirection($state, $botGamePlayerId) !== null;
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
     * How cheap a hand card needs to be before Zeal is worth spending it
     * on ("After playing this mood, you may put a card from your hand on
     * the bottom of the deck. If you do, draw a card.") -- a genuinely
     * low-value card is worth gambling on a random replacement for; a
     * merely mediocre one isn't worth the guaranteed loss of a known
     * quantity for an unknown one. Same threshold, same reasoning, as
     * RATIONALIZATION_LOW_VALUE_HAND_AVERAGE/AVOIDANCE_LOW_VALUE_MOOD_THRESHOLD/
     * CYNICISM_LOW_VALUE_DISCARD_THRESHOLD above.
     */
    private const ZEAL_LOW_VALUE_HAND_CARD_THRESHOLD = 2;

    /**
     * Zeal's own "should this optional field be attempted" policy
     * (confirmed by the maintainer) -- feeds buildChoicesForCard()'s own
     * `$forced` the same way shouldAttemptValueBoostDiscard() does,
     * rather than a bespoke buildChoicesForCard() special case: once
     * forced, BotChoiceResolver's own generic 'hand_card' field policy
     * already picks the LOWEST-value legal candidate on its own (the
     * same "minimize what's given up" bias resolveOwnResourceField()
     * documents), so there's no need to separately pick WHICH card here
     * -- only WHETHER to bother at all. True only if the bot's own
     * cheapest OTHER hand card (excluding $cardId, Zeal itself) is
     * cheap enough (ZEAL_LOW_VALUE_HAND_CARD_THRESHOLD) to be worth
     * cycling for a random replacement; an empty remaining hand (Zeal
     * was the bot's only card) has nothing to cycle at all, so it stays
     * false -- "if it has one to cycle" per the maintainer.
     */
    private function shouldAttemptZealCycle(BoardState $state, string $effectKey, string $fieldKey, int $cardId, int $botGamePlayerId): bool
    {
        if ($effectKey !== 'zeal' || $fieldKey !== 'hand_card_id') {
            return false;
        }

        $cheapestOtherHandCardValue = PHP_INT_MAX;
        foreach ($state->hand($botGamePlayerId) as $handCardId) {
            if ($handCardId !== $cardId) {
                $cheapestOtherHandCardValue = min($cheapestOtherHandCardValue, $this->baseValue($state, $handCardId));
            }
        }

        return $cheapestOtherHandCardValue <= self::ZEAL_LOW_VALUE_HAND_CARD_THRESHOLD;
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

    /**
     * How cheap a discard-pile card needs to be before handing it to an
     * opponent to boost Cynicism is worth it regardless of anything
     * else -- giving an opponent back a card this weak barely helps them
     * at all, so the boost is effectively free. Same threshold, same
     * reasoning, as RATIONALIZATION_LOW_VALUE_HAND_AVERAGE/
     * AVOIDANCE_LOW_VALUE_MOOD_THRESHOLD above.
     */
    private const CYNICISM_LOW_VALUE_DISCARD_THRESHOLD = 2;

    /**
     * Cynicism's own "should this be played at all right now" policy
     * (confirmed by the maintainer) -- unlike Rationalization, there's no
     * unconditionally-safe mode to fall back on here (boosting genuinely
     * costs an opponent's own hand a fresh card), so this is worth
     * playing NOW only if either:
     * - A cheap discard-pile card (cynicismCheapDiscardCardId(), together
     *   with a legal, non-teammate recipient to give it to) is available
     *   -- a near-free +3 (BOOSTED_VALUE 6 minus this card's own printed
     *   3), worth taking whenever it's on offer rather than saving for
     *   later.
     * - OR playing $cardId for its own plain printed value (no boost at
     *   all -- "for no extra value" per the maintainer) would be the
     *   deciding difference between the bot's own group NOT currently
     *   having the highest score this round and having it
     *   (wouldBecomeHighestScore(), reused here with an $unboostedValue
     *   of 0 -- comparing "didn't play this" against "played this
     *   unboosted", rather than that method's usual "unboosted" vs
     *   "boosted" comparison), AND no OTHER currently playable card
     *   offers as big a swing on its own (anotherPlayableCardOffersASufficientSwing())
     *   -- if something else already closes the same gap, there's no
     *   need to reach for Cynicism specifically to do it.
     * - OR (an addendum, also confirmed by the maintainer): Cynicism is
     *   a perfectly fine FIRST play, independent of the round's own
     *   score entirely, whenever nothing else currently playable is
     *   better -- no other card offers a 3+-point swing on its own
     *   (anotherPlayableCardOffersASufficientSwing() again, reusing
     *   Cynicism's own printed value as the bar), AND no other card
     *   interacts with an opponent's hand at all
     *   (anotherPlayableCardInteractsWithOpponentsHand() --
     *   EARLY_PRIORITY_EFFECT_KEYS' own hand-stealing/forced-discard
     *   entries specifically, not its extra-play ones). Unlike the
     *   round-deciding branch above, this one doesn't require Cynicism
     *   to actually decide anything -- just that nothing more useful is
     *   on offer right now.
     * Otherwise PHP_INT_MIN via sortPriorityValue() -- deprioritized
     * behind everything else, the same "save it for when it actually
     * pays off" treatment Rationalization already gets, though
     * cynicismChoices() itself still always plays SOMETHING once
     * chooseAction() does reach it (never a reason to skip it outright,
     * same as Rationalization).
     */
    private function cynicismHasAGoodReasonToPlayNow(BoardState $state, int $cardId, int $botGamePlayerId, array $playableCardIds): bool
    {
        if ($this->cynicismFirstValidRecipient($state, $botGamePlayerId) !== null
            && $this->cynicismCheapDiscardCardId($state) !== null) {
            return true;
        }

        $baseValue = $this->baseValue($state, $cardId);
        $anotherSufficientSwingExists = $this->anotherPlayableCardOffersASufficientSwing($state, $playableCardIds, $cardId, $baseValue);

        if ($this->wouldBecomeHighestScore($state, $botGamePlayerId, 0, $baseValue) && !$anotherSufficientSwingExists) {
            return true;
        }

        return !$anotherSufficientSwingExists
            && !$this->anotherPlayableCardInteractsWithOpponentsHand($state, $playableCardIds, $cardId);
    }

    /**
     * Whether some OTHER currently playable card's own plain printed
     * value already swings the round by at least $neededSwing on its
     * own -- @see cynicismHasAGoodReasonToPlayNow()'s own docblock for
     * why this matters: if something else can already close the same
     * gap, Cynicism doesn't need to be the one that does it.
     *
     * @param int[] $playableCardIds
     */
    private function anotherPlayableCardOffersASufficientSwing(BoardState $state, array $playableCardIds, int $excludeCardId, int $neededSwing): bool
    {
        foreach ($playableCardIds as $otherCardId) {
            if ($otherCardId !== $excludeCardId && $this->baseValue($state, $otherCardId) >= $neededSwing) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether some OTHER currently playable card steals from an
     * opponent's hand or forces one open -- EARLY_PRIORITY_EFFECT_KEYS'
     * own hand-stealing/forced-discard entries (`compulsion`/
     * `intimidation`/`suspicion`), deliberately excluding its
     * extra-play entries, since @see cynicismHasAGoodReasonToPlayNow()'s
     * own addendum only names "a card that interacts with an opponent's
     * hand" specifically.
     *
     * @param int[] $playableCardIds
     */
    private function anotherPlayableCardInteractsWithOpponentsHand(BoardState $state, array $playableCardIds, int $excludeCardId): bool
    {
        foreach ($playableCardIds as $otherCardId) {
            if ($otherCardId === $excludeCardId) {
                continue;
            }
            $effectKey = $state->catalogRow($state->effectiveCardId($otherCardId))['effectKey'];
            if (in_array($effectKey, ['compulsion', 'intimidation', 'suspicion'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cynicism's own "should this actually be boosted, and with what"
     * policy (confirmed by the maintainer), bypassing the generic
     * per-field CardChoiceSchema loop above the same way
     * rationalizationChoices()/avoidanceBestDirection() already do for
     * their own cards -- both of Cynicism's own fields (discard_card_id,
     * recipient_player_id) are optional but genuinely interdependent
     * (CynicismEffect::afterPlaying() throws if one is set without the
     * other), so they're decided together here rather than independently
     * per field. Boosts only when a cheap discard-pile card AND a legal
     * recipient both exist (cynicismCheapDiscardCardId()/
     * cynicismFirstValidRecipient()) -- empty otherwise, playing
     * Cynicism for its own plain printed value with nothing given away,
     * exactly the "for no extra value" case
     * cynicismHasAGoodReasonToPlayNow() already vetted before this was
     * ever reached.
     *
     * @return array{}|array{discard_card_id: int, recipient_player_id: int}
     */
    private function cynicismChoices(BoardState $state, int $botGamePlayerId): array
    {
        $recipientId = $this->cynicismFirstValidRecipient($state, $botGamePlayerId);
        $discardCardId = $this->cynicismCheapDiscardCardId($state);
        if ($recipientId === null || $discardCardId === null) {
            return [];
        }

        return ['discard_card_id' => $discardCardId, 'recipient_player_id' => $recipientId];
    }

    /**
     * The cheapest (lowest baseValue()) card currently in the discard
     * pile, or null if either the pile is empty or its own cheapest card
     * still isn't cheap enough (CYNICISM_LOW_VALUE_DISCARD_THRESHOLD) to
     * hand an opponent for free. Plain catalog baseValue(), the same
     * "not in play" reading rationalizationLowValueHand() already uses
     * for hand cards -- BoardState::valueOf() requires a card to
     * currently be a mood in play, which a discard-pile card never is.
     */
    private function cynicismCheapDiscardCardId(BoardState $state): ?int
    {
        $cheapestCardId = null;
        $cheapestValue = PHP_INT_MAX;
        foreach ($state->discardPile() as $discardCardId) {
            $value = $this->baseValue($state, $discardCardId);
            if ($value < $cheapestValue) {
                $cheapestValue = $value;
                $cheapestCardId = $discardCardId;
            }
        }

        return $cheapestCardId !== null && $cheapestValue <= self::CYNICISM_LOW_VALUE_DISCARD_THRESHOLD
            ? $cheapestCardId
            : null;
    }

    /**
     * The first active player who's a legal Cynicism recipient --
     * CynicismEffect's own validation (not the acting player, not a
     * teammate -- see BoardState::isTeammate()) -- matching
     * BotChoiceResolver's own generic "first legal candidate" default
     * for any other required/forced 'player' field with scope 'other'
     * (there's no per-opponent value to rank by the way a mood target
     * has, so "first" is as good as any).
     */
    private function cynicismFirstValidRecipient(BoardState $state, int $botGamePlayerId): ?int
    {
        foreach ($state->activePlayerOrder() as $playerId) {
            if ($playerId !== $botGamePlayerId && !$state->isTeammate($botGamePlayerId, $playerId)) {
                return $playerId;
            }
        }

        return null;
    }

    /**
     * Intimidation's own "who to target" policy (confirmed by the
     * maintainer) -- the first active, non-teammate opponent who
     * currently has at least one card in hand, or null if none do.
     * Deliberately excludes teammates (Intimidation's own printed text
     * has no such restriction -- "choose ANOTHER player" -- but taking a
     * card from your own teammate's hand for yourself isn't the
     * "opponent" this policy means, the same distinction Cynicism's own
     * recipient search already draws). An opponent with an empty hand is
     * skipped over in favor of one who actually has a card to reveal,
     * rather than just taking the first opponent in seat order the way
     * BotChoiceResolver's own generic "first legal candidate" default
     * would -- IntimidationEffect's own pendingDecisionsFor() silently
     * no-ops against an empty hand, so targeting one on purpose while
     * another qualifying opponent sits right there would waste the play
     * for nothing. Used both to decide WHETHER Intimidation is worth
     * playing right now (see sortPriorityValue()'s own docblock) and, if
     * so, WHO to actually target.
     */
    private function intimidationTargetPlayerId(BoardState $state, int $botGamePlayerId): ?int
    {
        foreach ($state->activePlayerOrder() as $playerId) {
            if ($playerId !== $botGamePlayerId
                && !$state->isTeammate($botGamePlayerId, $playerId)
                && $state->hand($playerId) !== []) {
                return $playerId;
            }
        }

        return null;
    }

    /**
     * Paranoia's own "who to target" policy (confirmed by the
     * maintainer) -- identical in shape to intimidationTargetPlayerId()
     * above: the first active, non-teammate opponent who currently has
     * at least one card in hand, or null if none do. Deliberately
     * excludes both the acting player itself and any teammate --
     * CardChoiceSchema's own field is scope 'any' (Paranoia's printed
     * text allows self-targeting, unlike Intimidation's "another
     * player"), so BotChoiceResolver's own generic default would happily
     * let the bot target itself, but "an opponent" per the maintainer
     * means neither the bot nor a teammate.
     */
    private function paranoiaTargetPlayerId(BoardState $state, int $botGamePlayerId): ?int
    {
        foreach ($state->activePlayerOrder() as $playerId) {
            if ($playerId !== $botGamePlayerId
                && !$state->isTeammate($botGamePlayerId, $playerId)
                && $state->hand($playerId) !== []) {
                return $playerId;
            }
        }

        return null;
    }

    /**
     * Sneakiness's own printed value -- see sneakinessTargetPlayerId()'s
     * own docblock for why this specific number is the margin a rival
     * needs to be ahead by, not just any arbitrary threshold.
     */
    private const SNEAKINESS_WIN_MARGIN = 5;

    /**
     * Sneakiness's own "who to target, and is it even worth playing at
     * all" policy (confirmed by the maintainer) -- unlike Intimidation/
     * Paranoia above, this is never played as a plain "highest printed
     * value" opening play: "swap your own score with [a chosen
     * opponent's] before determining who wins the round" only helps when
     * either the swap alone would win the round outright, or nothing is
     * actually at stake this round to begin with. Used both to decide
     * WHETHER Sneakiness is worth playing (isWorthPlaying()) and, if so,
     * WHO to target -- null from either branch below means "don't play
     * it," the same veto Fury/Avoidance already get from isWorthPlaying().
     *
     * Two cases:
     * - The round's own scoring is already being skipped entirely
     *   (BoardState::skipScoringOwnerId() non-null -- Awe, or any future
     *   card with the same "no one wins or loses this round" effect):
     *   the swap can never actually apply this round regardless of who's
     *   chosen (Awe's own printed text: "after-scoring effects don't
     *   happen"), so there's nothing to lose by playing it anyway -- it
     *   still sits in play as an ordinary 5-value mood contributing to
     *   every round from now on. The specific opponent picked here is
     *   arbitrary (the first active non-teammate one), since it's
     *   inconsequential either way.
     * - Otherwise: only worth it if some non-teammate opponent's own
     *   CURRENT round score (RoundScorer::score() against the board as
     *   it stands right now, before this play -- the same live-snapshot
     *   approach wouldBecomeHighestScore() above already uses) is MORE
     *   than SNEAKINESS_WIN_MARGIN (Sneakiness's own printed value, 5)
     *   ahead of the bot's own. That specific margin isn't arbitrary:
     *   Sneakiness itself is about to add its own 5 points to the bot's
     *   PRE-swap total the instant it's played (it's an ordinary mood
     *   card too, scored like any other before the swap ever applies),
     *   so a margin of exactly 5 or less would already be closed by
     *   Sneakiness's own value alone, with nothing left for the swap to
     *   win -- only a margin genuinely GREATER than 5 guarantees the
     *   chosen opponent is still ahead even after that addition, which is
     *   exactly what makes swapping with them unambiguously correct. When
     *   more than one non-teammate opponent clears that bar, the
     *   HIGHEST-scoring one is targeted -- the biggest possible swap, not
     *   just the first one that qualifies.
     */
    private function sneakinessTargetPlayerId(BoardState $state, int $botGamePlayerId): ?int
    {
        $opponentIds = array_values(array_filter(
            $state->activePlayerOrder(),
            fn (int $playerId): bool => $playerId !== $botGamePlayerId && !$state->isTeammate($botGamePlayerId, $playerId),
        ));
        if ($opponentIds === []) {
            return null;
        }

        if ($state->skipScoringOwnerId() !== null) {
            return $opponentIds[0];
        }

        $scores = (new RoundScorer())->score($state);
        $botScore = $scores[$botGamePlayerId] ?? 0;

        $bestOpponentId = null;
        $bestOpponentScore = -1;
        foreach ($opponentIds as $opponentId) {
            $opponentScore = $scores[$opponentId] ?? 0;
            if ($opponentScore > $bestOpponentScore) {
                $bestOpponentScore = $opponentScore;
                $bestOpponentId = $opponentId;
            }
        }

        return $bestOpponentScore - $botScore > self::SNEAKINESS_WIN_MARGIN ? $bestOpponentId : null;
    }

    /**
     * Pacifism's own "who/what to suppress" policy (confirmed by the
     * maintainer) -- up to two moods, at most one per non-teammate
     * opponent (each opponent's own HIGHEST-value in-play mood), and
     * preferring two DIFFERENT opponents over a single opponent's own
     * single mood whenever at least two opponents currently have a mood
     * in play. CardChoiceSchema's own `'distinct_owners'` constraint
     * already forbids taking two moods from the same player, so "one
     * mood from each of two opponents" IS simply "fill both target
     * slots" here -- sorting every opponent's own best mood by value and
     * taking the top two (instead of just the first two in seat order)
     * satisfies both "prefer two opponents" and "target the
     * highest-point opponent cards" at once, since a single very strong
     * mood never loses out to two weak ones under this policy, but two
     * opponents each above the third-best candidate always beats
     * bothering only one of them. Deliberately excludes both the acting
     * player itself and any teammate -- CardChoiceSchema's own field is
     * scope 'any' (Pacifism's printed text says "choose up to two
     * players" with no restriction against the acting player or a
     * teammate), so BotChoiceResolver's own generic default would
     * happily let the bot suppress its own or a teammate's mood, but "an
     * opponent" per the maintainer means neither.
     *
     * @return int[]
     */
    private function pacifismTargetMoodIds(BoardState $state, int $botGamePlayerId): array
    {
        $bestMoodIdByOpponent = [];
        foreach ($state->activePlayerOrder() as $playerId) {
            if ($playerId === $botGamePlayerId || $state->isTeammate($botGamePlayerId, $playerId)) {
                continue;
            }

            $bestMoodId = null;
            foreach ($state->moodsOwnedBy($playerId) as $mood) {
                if ($bestMoodId === null || $state->valueOf($mood->cardId) > $state->valueOf($bestMoodId)) {
                    $bestMoodId = $mood->cardId;
                }
            }

            if ($bestMoodId !== null) {
                $bestMoodIdByOpponent[] = $bestMoodId;
            }
        }

        usort($bestMoodIdByOpponent, fn (int $a, int $b) => $state->valueOf($b) <=> $state->valueOf($a));

        return array_slice($bestMoodIdByOpponent, 0, 2);
    }

    /**
     * The highest printed value a mood can have and still count as
     * "cheap enough to sacrifice for a replay" for
     * denialReplayTargetMoodIds() below -- confirmed by the maintainer.
     * Denial returns a targeted mood to its OWNER's hand, from which it
     * can simply be played again (MoodPlayService re-runs a mood's own
     * afterPlaying() hook in full every time it's played, with no
     * once-per-game memory of it), so the whole point of targeting one
     * of the bot's own moods this way is the re-triggered ability, not
     * the mood's own scoring contribution -- a genuinely expensive mood
     * (a real point total worth keeping in play) isn't worth giving up
     * for that, but a cheap one essentially is that ability for free.
     */
    private const DENIAL_REPLAY_MAX_VALUE = 2;

    /**
     * Denial's own "what to target" policy (confirmed by the
     * maintainer) -- two priorities, tried in order, both constrained to
     * a legal same-color-or-same-value pair (CardChoiceSchema's own
     * `'same_color_or_value'` constraint for this field) the same way
     * DenialEffect itself validates:
     *
     * 1. denialWinningTargetMoodIds() -- a pair of non-teammate
     *    opponents' own in-play moods (either one opponent's own two, or
     *    one each from two different opponents -- Denial's own field has
     *    no `distinct_owners` constraint the way Pacifism's does) whose
     *    combined removal (back to their owners' hands, off the board
     *    entirely for scoring purposes -- see BoardState::
     *    moveInPlayToHand()) would win the round outright for the bot's
     *    own group. "Remove them from play" is worth doing for its own
     *    sake even without an immediate win, but ONLY chasing an outright
     *    win here (rather than any positive swing at all) keeps this a
     *    deliberate, decisive play rather than the bot fishing for
     *    marginal opponent setbacks with no real payoff -- the same
     *    "only when it actually wins" discipline sneakinessTargetPlayerId()
     *    already applies for the identical reason.
     * 2. Failing that, denialReplayTargetMoodIds() -- one of the bot's
     *    own moods cheap enough (DENIAL_REPLAY_MAX_VALUE) to give up for
     *    a replay of its own "after playing this mood" ability, paired
     *    with whatever else qualifies.
     *
     * Returns [] (Denial still legally playable as a plain 1-point blue
     * mood, per DenialEffect's own `if ($targets === []) { return; }`)
     * when NEITHER priority finds a qualifying pair -- unlike Pacifism/
     * Harmony/Nostalgia above, this doesn't deprioritize Denial itself
     * via sortPriorityValue() when that happens; a same-color-or-value
     * pair genuinely doesn't always exist among whatever's in play, and
     * Denial's own printed value is ordinary enough (1) that leading
     * with it purely by baseValue() the way most cards do is still a
     * perfectly reasonable default in that case.
     */
    private function denialTargetMoodIds(BoardState $state, int $cardId, int $botGamePlayerId): array
    {
        $winningTargetMoodIds = $this->denialWinningTargetMoodIds($state, $cardId, $botGamePlayerId);
        if ($winningTargetMoodIds !== null) {
            return $winningTargetMoodIds;
        }

        return $this->denialReplayTargetMoodIds($state, $botGamePlayerId) ?? [];
    }

    /**
     * @see denialTargetMoodIds()'s own docblock, priority 1. null (not
     * []) specifically means "don't play this way" -- distinguished from
     * denialReplayTargetMoodIds()'s own identical null-vs-[] convention
     * only by which caller consults it. skipScoringOwnerId() non-null
     * (Awe or similar) short-circuits straight to null, the same
     * "nothing to win this round regardless of who's targeted" guard
     * sneakinessTargetPlayerId() already applies -- there's no round
     * outcome removing a mood's value could possibly change right now.
     *
     * @return ?int[]
     */
    private function denialWinningTargetMoodIds(BoardState $state, int $cardId, int $botGamePlayerId): ?array
    {
        if ($state->skipScoringOwnerId() !== null) {
            return null;
        }

        $opponentMoodIds = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->ownerId !== $botGamePlayerId && !$state->isTeammate($botGamePlayerId, $mood->ownerId)) {
                $opponentMoodIds[] = $mood->cardId;
            }
        }

        $pairs = $this->sameColorOrValuePairs($state, $opponentMoodIds);
        usort(
            $pairs,
            fn (array $a, array $b) => ($state->valueOf($b[0]) + $state->valueOf($b[1])) <=> ($state->valueOf($a[0]) + $state->valueOf($a[1])),
        );

        foreach ($pairs as $pair) {
            if ($this->denialWouldWinRoundByRemoving($state, $cardId, $botGamePlayerId, $pair)) {
                return $pair;
            }
        }

        return null;
    }

    /**
     * Whether removing $targetCardIds (each back to its own owner's
     * hand, per DenialEffect -- see denialTargetMoodIds()'s own
     * docblock) would move the bot's own group from BELOW the best
     * remaining rival group's current round score to AT OR ABOVE it.
     * The same RoundScorer()-based group-scoring approach
     * wouldBecomeHighestScore() above already uses for a value BOOST,
     * generalized here for an arbitrary per-owner REMOVAL instead: each
     * target's own live value is subtracted from its owner's score
     * before regrouping, rather than added to the bot's. Denial's own
     * base value (still an ordinary mood contributing to the bot's own
     * total once played, same as any other card) is added in by hand
     * the same "not reflected on the board yet" reasoning
     * wouldBecomeHighestScore() documents, via baseValue($cardId)
     * rather than a hardcoded constant so a future card sharing this
     * effect key at a different printed value would still be handled
     * correctly.
     *
     * @param int[] $targetCardIds
     */
    private function denialWouldWinRoundByRemoving(BoardState $state, int $cardId, int $botGamePlayerId, array $targetCardIds): bool
    {
        $scores = (new RoundScorer())->score($state);
        foreach ($targetCardIds as $targetCardId) {
            $ownerId = $state->ownerOf($targetCardId);
            $scores[$ownerId] = ($scores[$ownerId] ?? 0) - $state->valueOf($targetCardId);
        }

        $activeIds = $state->activePlayerOrder();
        $myGroupIds = array_values(array_filter(
            $activeIds,
            fn (int $id): bool => $id === $botGamePlayerId || $state->isTeammate($botGamePlayerId, $id),
        ));
        $myTotal = array_sum(array_map(fn (int $id) => $scores[$id] ?? 0, $myGroupIds)) + $this->baseValue($state, $cardId);

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

        return $myTotal >= $rivalBest;
    }

    /**
     * @see denialTargetMoodIds()'s own docblock, priority 2. Every one of
     * the bot's own in-play moods cheap enough (DENIAL_REPLAY_MAX_VALUE)
     * with its own "after playing this mood" ability is a replay
     * candidate; among those, a pair of TWO such candidates that
     * satisfies the same-color-or-value constraint is tried first (a
     * double payoff -- both return to hand and can both be replayed),
     * before falling back to pairing a single candidate with whatever
     * else qualifies (bestDenialReplayPartner()). null (not []) means no
     * candidate qualifies at all, distinguished from
     * denialWinningTargetMoodIds()'s own identical convention only by
     * which caller consults it.
     *
     * @return ?int[]
     */
    private function denialReplayTargetMoodIds(BoardState $state, int $botGamePlayerId): ?array
    {
        $replayCandidateIds = [];
        foreach ($state->moodsOwnedBy($botGamePlayerId) as $mood) {
            if ($this->qualifiesForDenialReplay($state, $mood->cardId)) {
                $replayCandidateIds[] = $mood->cardId;
            }
        }

        foreach ($this->sameColorOrValuePairs($state, $replayCandidateIds) as $pair) {
            return $pair;
        }

        foreach ($replayCandidateIds as $candidateId) {
            $partnerId = $this->bestDenialReplayPartner($state, $botGamePlayerId, $candidateId);
            if ($partnerId !== null) {
                return [$candidateId, $partnerId];
            }
        }

        return null;
    }

    private function qualifiesForDenialReplay(BoardState $state, int $cardId): bool
    {
        $catalogRow = $state->catalogRow($state->effectiveCardId($cardId));

        return $catalogRow['hasAfterPlaying'] && $catalogRow['baseValue'] <= self::DENIAL_REPLAY_MAX_VALUE;
    }

    /**
     * The best partner for $forCardId (one of the bot's own
     * denialReplayTargetMoodIds() candidates that found no matching
     * SECOND candidate to pair with) among every OTHER in-play mood
     * satisfying the same-color-or-value constraint: a non-teammate
     * opponent's own mood (preferring their highest-value one -- the
     * bot loses nothing by touching it, and the higher its value the
     * more it's worth knocking back to their hand) over any other one of
     * the bot's own moods, so the "filler" second target never costs the
     * bot a second good card just to enable one cheap replay.
     */
    private function bestDenialReplayPartner(BoardState $state, int $botGamePlayerId, int $forCardId): ?int
    {
        $bestOpponentId = null;
        $bestOpponentValue = -1;
        $fallbackOwnId = null;

        foreach ($state->moodsInPlay() as $mood) {
            $candidateId = $mood->cardId;
            if ($candidateId === $forCardId || !$this->sameColorOrValue($state, $forCardId, $candidateId)) {
                continue;
            }

            if ($mood->ownerId !== $botGamePlayerId && !$state->isTeammate($botGamePlayerId, $mood->ownerId)) {
                if ($state->valueOf($candidateId) > $bestOpponentValue) {
                    $bestOpponentValue = $state->valueOf($candidateId);
                    $bestOpponentId = $candidateId;
                }
            } elseif ($fallbackOwnId === null) {
                $fallbackOwnId = $candidateId;
            }
        }

        return $bestOpponentId ?? $fallbackOwnId;
    }

    private function sameColorOrValue(BoardState $state, int $a, int $b): bool
    {
        return $state->colorOf($a) === $state->colorOf($b) || $state->valueOf($a) === $state->valueOf($b);
    }

    /**
     * Every distinct pair from $cardIds satisfying Denial's own
     * same-color-or-value constraint, used by both
     * denialWinningTargetMoodIds() and denialReplayTargetMoodIds()'s own
     * two-own-candidates case above.
     *
     * @param int[] $cardIds
     * @return int[][]
     */
    private function sameColorOrValuePairs(BoardState $state, array $cardIds): array
    {
        $pairs = [];
        $count = count($cardIds);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->sameColorOrValue($state, $cardIds[$i], $cardIds[$j])) {
                    $pairs[] = [$cardIds[$i], $cardIds[$j]];
                }
            }
        }

        return $pairs;
    }

    /**
     * Creativity's own "what to copy" policy (confirmed by the
     * maintainer) -- generally the highest-value mood currently in play,
     * regardless of owner (CardChoiceSchema's own `copy_card_id` field is
     * scope `'any'` with no `excludes_teammate`, and there's no reason to
     * exclude the bot's own board here the way Intimidation/Paranoia/
     * Pacifism exclude opponents' -- copying your own best mood is just
     * as legitimate as copying an opponent's). Deliberately skips any
     * candidate whose OWN printed ability has a "to play" cost (Bliss,
     * Envy, Exhilaration, Guile, Neurosis, Regret, Self-Loathing) --
     * `MoodPlayService::playMood()` pays a Creativity-copy's cost against
     * the COPIED card's own `canPayToPlayCost()`, not Creativity's
     * (always payable, since Creativity itself has no printed cost), so
     * choosing one of these without knowing in advance whether the bot
     * could actually pay it risks turning a legal Creativity play into an
     * illegal one (`IllegalPlayException`) -- "generally" the highest
     * value per the maintainer, not "the literal highest value no matter
     * what," so skipping straight past a would-be-illegal target in favor
     * of the next-best safe one is well within that. Resolved through
     * `effectiveCardId()` throughout (both for the hasToPlay check and
     * for `valueOf()`'s own live value), so copying a Creativity that's
     * itself already copying something targets -- and is scored as --
     * whatever THAT card actually is, never blank Creativity. `null`
     * (leaving `copy_card_id` unfilled, the same "just a blank blue 0"
     * default as before this policy existed) only when nothing is in
     * play yet at all, or every in-play mood happens to have a to-play
     * cost.
     */
    private function creativityBestCopyTargetId(BoardState $state): ?int
    {
        $bestCardId = null;
        $bestValue = -1;
        foreach ($state->moodsInPlay() as $mood) {
            if ($state->catalogRow($state->effectiveCardId($mood->cardId))['hasToPlay']) {
                continue;
            }

            $value = $state->valueOf($mood->cardId);
            if ($value > $bestValue) {
                $bestValue = $value;
                $bestCardId = $mood->cardId;
            }
        }

        return $bestCardId;
    }

    /**
     * Anger's own after-playing targets (confirmed by the maintainer): the
     * subset of every non-teammate opponent's own in-play moods that
     * maximizes total value discarded without exceeding
     * AngerEffect::MAX_TOTAL_VALUE, via angerSwingMaximizingTargets()
     * below, PLUS Anger's own card id whenever
     * angerShouldAlsoTargetItself() says the bot should discard itself too
     * -- always additive, never a trade-off against the swing-maximizing
     * targets, since Anger's own printed base value is 0 (see
     * CardChoiceSchema's own `includes_self` docblock for `anger`), so
     * including it never eats into the 5-point budget the opponent-owned
     * targets are competing for.
     *
     * @return int[]
     */
    private function angerTargetMoodIds(BoardState $state, int $cardId, int $botGamePlayerId): array
    {
        $targets = $this->angerSwingMaximizingTargets($state, $botGamePlayerId);

        if ($this->angerShouldAlsoTargetItself($state, $botGamePlayerId)) {
            $targets[] = $cardId;
        }

        return $targets;
    }

    /**
     * The "maximize point swing" half of Anger's own targeting policy
     * (confirmed by the maintainer): every non-teammate opponent's own
     * in-play mood is a candidate (the acting player's own moods, and any
     * teammate's, are deliberately excluded -- discarding either would
     * only ever REDUCE the swing, the same "an opponent means neither"
     * policy pacifismTargetMoodIds() already applies), scored by
     * maxValueSubsetWithinBudget() to find the highest-total-value subset
     * that still fits Anger's own 5-point combined-value ceiling.
     *
     * @return int[]
     */
    private function angerSwingMaximizingTargets(BoardState $state, int $botGamePlayerId): array
    {
        $opponentMoodValues = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->ownerId === $botGamePlayerId || $state->isTeammate($botGamePlayerId, $mood->ownerId)) {
                continue;
            }

            $opponentMoodValues[$mood->cardId] = $state->valueOf($mood->cardId);
        }

        return $this->maxValueSubsetWithinBudget($opponentMoodValues, self::ANGER_DISCARD_BUDGET);
    }

    /**
     * A standard 0/1 knapsack over $valuesByCardId (weight == value for
     * each candidate, same as Anger's own combined-value ceiling) -- the
     * subset whose values sum to the highest total that still fits within
     * $budget. $bestValueAt[$b]/$chosenAt[$b] track, for every budget from
     * 0 to $budget, the best achievable total and which card ids reach it;
     * iterating each candidate's own capacity slots downward (the
     * ordinary 0/1 knapsack trick) keeps every candidate from being
     * counted against itself twice. A candidate above $budget on its own,
     * or worth 0, can never improve any achievable total, so it's skipped
     * outright rather than wasting a pass through every capacity slot for
     * nothing.
     *
     * @param array<int, int> $valuesByCardId card id => value
     * @return int[]
     */
    private function maxValueSubsetWithinBudget(array $valuesByCardId, int $budget): array
    {
        $bestValueAt = array_fill(0, $budget + 1, 0);
        $chosenAt = array_fill(0, $budget + 1, []);

        foreach ($valuesByCardId as $cardId => $value) {
            if ($value <= 0 || $value > $budget) {
                continue;
            }

            for ($capacity = $budget; $capacity >= $value; $capacity--) {
                $candidateTotal = $bestValueAt[$capacity - $value] + $value;
                if ($candidateTotal > $bestValueAt[$capacity]) {
                    $bestValueAt[$capacity] = $candidateTotal;
                    $chosenAt[$capacity] = [...$chosenAt[$capacity - $value], $cardId];
                }
            }
        }

        return $chosenAt[$budget];
    }

    /**
     * Whether Anger should also target its own just-played card id
     * (confirmed by the maintainer): only in a format with genuinely
     * separate per-player decks (BoardState::hasSeparateDecks() -- 'duel'
     * and any drafted deck_type, including team formats using one; every
     * other format shares one deck, so there's no distinct "the bot's own
     * deck" vs "the opponent's deck" to compare recursion counts between
     * at all), only once the bot's own recursionCardCount() strictly
     * exceeds EVERY currently active non-teammate opponent's own count
     * (not just one of them, in a multi-opponent draft -- the bot needs a
     * clear recursion edge over the WHOLE table before gambling that it,
     * rather than some other player, is the one who actually gets back to
     * Anger sitting in the shared discard pile), and never when any such
     * opponent currently has Grace in play -- Grace's own perpetual,
     * every-turn discard-sourced grant (unlike Harmony/Grief/Angst/
     * Nostalgia's one-shot ones) makes that opponent likely to reach the
     * discard pile before the bot's own recursion ever gets a turn,
     * regardless of who has more of it on paper.
     */
    private function angerShouldAlsoTargetItself(BoardState $state, int $botGamePlayerId): bool
    {
        if (!$state->hasSeparateDecks()) {
            return false;
        }

        $opponents = array_filter(
            $state->activePlayerOrder(),
            fn (int $playerId) => $playerId !== $botGamePlayerId && !$state->isTeammate($botGamePlayerId, $playerId),
        );
        if ($opponents === []) {
            return false;
        }

        $botRecursionCount = $this->recursionCardCount($state, $botGamePlayerId);

        foreach ($opponents as $opponentId) {
            if ($state->playerHasMoodInPlay($opponentId, 'grace')) {
                return false;
            }
            if ($this->recursionCardCount($state, $opponentId) >= $botRecursionCount) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many of $playerId's own cards -- across their remaining deck,
     * hand, and current in-play moods (deliberately excluding the discard
     * pile: a Harmony/Grief/Angst/Nostalgia sitting there already spent
     * its one-time grant, and Grace/Melancholy wouldn't be there at all
     * while still actively granting anything) -- have one of
     * RECURSION_EFFECT_KEYS as their own effect key. Used only by
     * angerShouldAlsoTargetItself() above, as a proxy for how much live-or-
     * future capability a player has to eventually play a mood back out of
     * the shared discard pile.
     */
    private function recursionCardCount(BoardState $state, int $playerId): int
    {
        $cardIds = [...$state->deck($playerId), ...$state->hand($playerId)];
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            $cardIds[] = $mood->cardId;
        }

        $count = 0;
        foreach ($cardIds as $cardId) {
            if (in_array($state->catalogRow($cardId)['effectKey'], self::RECURSION_EFFECT_KEYS, true)) {
                $count++;
            }
        }

        return $count;
    }
}
