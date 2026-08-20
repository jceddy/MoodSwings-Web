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
 * field-filling policy this builds on -- with five deliberate
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
 * from), rather than leading with it purely by printed value; and
 * cynicismChoices()/sortPriorityValue() again (confirmed by the
 * maintainer), which similarly deprioritize Cynicism unless a cheap
 * discard-pile card is available to boost it for free, or the round's
 * own score makes playing it (even unboosted) the deciding difference
 * with nothing else able to swing it as much -- see
 * cynicismHasAGoodReasonToPlayNow()'s own docblock.
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
     * swing the round as much" comparison; every other candidate here
     * ignores it.
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

        return $this->baseValue($state, $cardId);
    }

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

        return $this->wouldBecomeHighestScore($state, $botGamePlayerId, 0, $baseValue)
            && !$this->anotherPlayableCardOffersASufficientSwing($state, $playableCardIds, $cardId, $baseValue);
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
}
