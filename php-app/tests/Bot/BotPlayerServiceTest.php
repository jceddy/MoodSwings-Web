<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Bot;

use MoodSwings\Bot\BotChoiceResolver;
use MoodSwings\Bot\BotPlayerService;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Tests\Rules\CatalogFixture;
use PHPUnit\Framework\TestCase;

final class BotPlayerServiceTest extends TestCase
{
    use CatalogFixture;

    private BotPlayerService $bot;

    protected function setUp(): void
    {
        $this->bot = new BotPlayerService(new BotChoiceResolver());
    }

    /**
     * @param array<int, int> $catalogCardIdFor instance id => catalog id,
     *     for a test that needs two DIFFERENT physical instances sharing
     *     the SAME catalog entry (e.g. two players each with their own
     *     copy of Determination) -- every other test leaves this empty,
     *     which BoardState::catalogCardId() already treats as "instance
     *     id doubles as its own catalog id" (this fixture's usual
     *     convention throughout the rest of this file).
     */
    private function boardState(array $hands = [], array $catalogCardIdFor = []): BoardState
    {
        return new BoardState($this->sampleCatalog(), DefaultEffectRegistry::build(), [1, 2, 3], $hands, catalogCardIdFor: $catalogCardIdFor);
    }

    public function testChooseActionPicksTheHighestValuePlayableCard(): void
    {
        $state = $this->boardState(hands: [1 => [8, 55]]); // Dignity (value 3, has an optional field), Apathy (value 4, no ability)

        $action = $this->bot->chooseAction($state, [8, 55], 1);

        self::assertSame(55, $action['card_id']);
        // Apathy has no choice_fields at all, so this is trivially empty
        // -- but also proves Dignity's own optional discard field was
        // never even a factor in the choice, since it wasn't picked.
        self::assertSame([], $action['choices']);
    }

    public function testChooseActionLeavesAnOptionalFieldUnfilled(): void
    {
        $state = $this->boardState(hands: [1 => [8]]); // Dignity only

        $action = $this->bot->chooseAction($state, [8], 1);

        self::assertSame(8, $action['card_id']);
        self::assertSame([], $action['choices']); // discard_card_id is optional -- never filled
    }

    public function testChooseActionReturnsNullWhenNoCardsArePlayable(): void
    {
        $state = $this->boardState();

        self::assertNull($this->bot->chooseAction($state, [], 1));
    }

    public function testChooseActionFillsARequiredOwnScopeCostField(): void
    {
        $state = $this->boardState(hands: [1 => [64, 8]]); // Envy (in hand); Dignity moved into play below
        $state->moveHandToInPlay(1, 8);

        $action = $this->bot->chooseAction($state, [64], 1);

        self::assertSame(64, $action['card_id']);
        self::assertSame(['discard_mood_id' => 8], $action['choices']);
    }

    /**
     * Regret (id 50) requires exactly 2 of the bot's own moods to return
     * to hand plus an opponent's mood to steal -- with nothing at all in
     * play, no legal choices exist for it, so chooseAction() should skip
     * it and fall through to the next-highest-value candidate instead of
     * giving up outright.
     */
    public function testChooseActionSkipsACardItCannotLegallyFillAndTriesTheNextOne(): void
    {
        $state = $this->boardState(hands: [1 => [50, 55]]); // Regret (unfillable right now), Apathy (no fields)

        $action = $this->bot->chooseAction($state, [50, 55], 1);

        self::assertSame(55, $action['card_id']);
    }

    /**
     * Curiosity (id 33) has one optional target_player_id field, in
     * BotChoiceResolver's own ALWAYS_FILLED_OPTIONAL_FIELDS list -- unlike
     * Dignity's own optional field above (testChooseActionLeavesAnOptionalFieldUnfilled()),
     * this one gets filled in.
     */
    public function testChooseActionFillsCuriositysOptionalTargetField(): void
    {
        $state = $this->boardState(hands: [1 => [33], 2 => [8]]); // Curiosity; player 2 has a card to reveal

        $action = $this->bot->chooseAction($state, [33], 1);

        self::assertSame(33, $action['card_id']);
        self::assertSame(['target_player_id' => 2], $action['choices']);
    }

    /**
     * Suspicion (id 78) has one optional multi player_ids field, also in
     * ALWAYS_FILLED_OPTIONAL_FIELDS -- every legal opponent is targeted,
     * not just one.
     */
    public function testChooseActionTargetsEveryOpponentWithSuspicion(): void
    {
        $state = $this->boardState(hands: [1 => [78], 2 => [8], 3 => [55]]); // Suspicion; players 2 and 3 both have a card to discard

        $action = $this->bot->chooseAction($state, [78], 1);

        self::assertSame(78, $action['card_id']);
        self::assertSame(['player_ids' => [2, 3]], $action['choices']);
    }

    /**
     * Suspicion is still playable even when nothing qualifies for its own
     * forced field (every other player's hand is empty) -- the field
     * just stays unfilled, the same as an ordinary optional field would,
     * rather than making the whole card unplayable the way a genuinely
     * required field's own null would (see buildChoicesForCard()'s own
     * required-vs-forced distinction).
     */
    public function testChooseActionStillPlaysSuspicionWhenNoOpponentsQualify(): void
    {
        $state = $this->boardState(hands: [1 => [78]]); // Suspicion only -- players 2/3 have empty hands

        $action = $this->bot->chooseAction($state, [78], 1);

        self::assertSame(78, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Fury (id 91, value 4) costs the bot its own highest-value mood too
     * -- only worth playing when at least one opponent's own
     * highest-value mood is worth more than the bot's own. Here the
     * opponent's Discipline (id 9, value 6) beats the bot's own Dignity
     * (id 8, value 3), so Fury is worth it.
     */
    public function testChooseActionPlaysFuryWhenAnOpponentsHighestMoodExceedsItsOwn(): void
    {
        $state = $this->boardState(hands: [1 => [91, 8], 2 => [9]]);
        $state->moveHandToInPlay(1, 8);
        $state->moveHandToInPlay(2, 9);

        $action = $this->bot->chooseAction($state, [91], 1);

        self::assertSame(91, $action['card_id']);
    }

    /**
     * The mirror case: no opponent's own highest-value mood exceeds the
     * bot's own (Charity, id 3, value 1, isn't more than the bot's own
     * Dignity, id 8, value 3) -- Fury is the only playable card, so
     * chooseAction() should pass rather than play a pure loss.
     */
    public function testChooseActionSkipsFuryAndPassesWhenNoOpponentExceedsItsOwnHighestMood(): void
    {
        $state = $this->boardState(hands: [1 => [91, 8], 2 => [3]]);
        $state->moveHandToInPlay(1, 8);
        $state->moveHandToInPlay(2, 3);

        self::assertNull($this->bot->chooseAction($state, [91], 1));
    }

    /**
     * When Fury is vetoed but a different, unconditionally-worth-playing
     * card is also playable, chooseAction() falls through to it instead
     * of passing outright -- same "try the next-highest" fallback
     * testChooseActionSkipsACardItCannotLegallyFillAndTriesTheNextOne()
     * already covers for an unfillable required field.
     */
    public function testChooseActionFallsThroughToTheNextCardWhenFuryIsVetoed(): void
    {
        $state = $this->boardState(hands: [1 => [91, 8, 3], 2 => [3]]); // Fury (4), Dignity (3), Charity (1)
        $state->moveHandToInPlay(1, 8);
        $state->moveHandToInPlay(2, 3);

        $action = $this->bot->chooseAction($state, [91, 3], 1);

        self::assertSame(3, $action['card_id']); // Charity -- Fury (higher value) was vetoed
    }

    /**
     * Only ONE of several opponents needs to qualify -- the other
     * opponent's own low mood doesn't disqualify Fury.
     */
    public function testChooseActionPlaysFuryWhenOnlyOneOfSeveralOpponentsExceedsItsOwnHighestMood(): void
    {
        $state = $this->boardState(hands: [1 => [91, 8], 2 => [3], 3 => [9]]);
        $state->moveHandToInPlay(1, 8); // bot: Dignity, value 3
        $state->moveHandToInPlay(2, 3); // opponent 2: Charity, value 1 -- doesn't qualify
        $state->moveHandToInPlay(3, 9); // opponent 3: Discipline, value 6 -- qualifies

        $action = $this->bot->chooseAction($state, [91], 1);

        self::assertSame(91, $action['card_id']);
    }

    /**
     * A player with no moods in play at all has an effective highest
     * value of -1 (see FuryEffect's own identical sentinel) -- an
     * opponent with literally any mood in play (even Fear, id 38, value
     * 0) still exceeds that, so Fury is worth it even though the bot
     * itself has nothing in play to lose from the general "highest
     * value" comparison alone.
     */
    public function testChooseActionPlaysFuryWhenTheBotHasNoMoodsInPlayAndAnOpponentHasAny(): void
    {
        $state = $this->boardState(hands: [1 => [91], 2 => [38]]);
        $state->moveHandToInPlay(2, 38);

        $action = $this->bot->chooseAction($state, [91], 1);

        self::assertSame(91, $action['card_id']);
    }

    /**
     * Neither the bot nor its opponent has any mood in play -- both
     * effective highest values are -1, and -1 is not GREATER than -1,
     * so Fury still isn't worth it (there'd be nothing for either side
     * to actually lose).
     */
    public function testChooseActionSkipsFuryWhenNeitherTheBotNorAnyOpponentHasAMoodInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [91]]);

        self::assertNull($this->bot->chooseAction($state, [91], 1));
    }

    /**
     * Avoidance (id 29, value 3) is vetoed when the bot's own cheapest
     * mood to give up (Discipline, id 9, value 6, is the ONLY mood in
     * play, so it's also the lowest) is worth more than
     * AVOIDANCE_LOW_VALUE_MOOD_THRESHOLD (2) and neither neighbor's own
     * lowest mood (both empty here) beats it -- a pure loss.
     */
    public function testChooseActionSkipsAvoidanceAndPassesWhenNoGoodReasonToPlayIt(): void
    {
        $state = $this->boardState(hands: [1 => [29, 9]]);
        $state->moveHandToInPlay(1, 9);

        self::assertNull($this->bot->chooseAction($state, [29], 1));
    }

    /**
     * The bot's own cheapest mood to give up (Charity, id 3, value 1) is
     * low-value enough (<= AVOIDANCE_LOW_VALUE_MOOD_THRESHOLD) to be worth
     * risking regardless of what comes back, so Avoidance is worth
     * playing even though neither neighbor has anything to offer.
     */
    public function testChooseActionPlaysAvoidanceWhenItsOwnCheapestMoodIsLowValue(): void
    {
        $state = $this->boardState(hands: [1 => [29, 3]]);
        $state->moveHandToInPlay(1, 3);

        $action = $this->bot->chooseAction($state, [29], 1);

        self::assertSame(29, $action['card_id']);
    }

    /**
     * A player with no moods in play at all has nothing to give up
     * (lowestMoodValueOwnedBy()'s own 0 sentinel), which trivially
     * qualifies as "low-value enough" -- Avoidance is worth playing even
     * with nothing at all on the board yet.
     */
    public function testChooseActionPlaysAvoidanceWhenTheBotHasNoMoodsInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [29]]);

        $action = $this->bot->chooseAction($state, [29], 1);

        self::assertSame(29, $action['card_id']);
    }

    /**
     * The bot's own cheapest mood (Curiosity, id 33, value 3) is too
     * valuable to risk for nothing on its own, but under direction
     * 'right' the giver who'd pass TO player 1 is player 2
     * (activeNeighbor(1, 'left') === 2, so the giver on the OPPOSITE
     * side -- avoidanceReceivedValueFor()'s own mapping), who has Chaos
     * (id 85, value 6) as their own lowest (only) mood -- worth MORE
     * than what the bot gives up. That's a genuinely profitable trade,
     * so Avoidance is worth playing, and 'right' is the direction chosen.
     */
    public function testChooseActionPlaysAvoidanceAndPicksTheProfitableDirection(): void
    {
        $state = $this->boardState(hands: [1 => [29, 33], 2 => [85]]);
        $state->moveHandToInPlay(1, 33);
        $state->moveHandToInPlay(2, 85);

        $action = $this->bot->chooseAction($state, [29], 1);

        self::assertSame(29, $action['card_id']);
        self::assertSame(['direction' => 'right'], $action['choices']);
    }

    /**
     * The mirror case: under direction 'left' the giver who'd pass TO
     * player 1 is player 3 (the OPPOSITE side from the 'right' case
     * above), who has the more valuable mood to offer this time (Chaos,
     * id 85, value 6) instead of player 2 (Charity, id 3, value 1), so
     * 'left' is the profitable direction now.
     */
    public function testChooseActionPicksLeftWhenThatsTheProfitableDirection(): void
    {
        $state = $this->boardState(hands: [1 => [29, 33], 2 => [3], 3 => [85]]);
        $state->moveHandToInPlay(1, 33);
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 85);

        $action = $this->bot->chooseAction($state, [29], 1);

        self::assertSame(29, $action['card_id']);
        self::assertSame(['direction' => 'left'], $action['choices']);
    }

    /**
     * Both directions would receive the exact same value -- 'left' wins
     * the tie, matching BotChoiceResolver's own "first option" default
     * for every other required mode field (avoidanceBestDirection()'s
     * own docblock).
     */
    public function testChooseActionPicksLeftOnATiedDirectionValue(): void
    {
        // Own cheapest mood (Charity, value 1) is low-value enough on its
        // own to guarantee Avoidance is worth playing regardless of the
        // tie below -- isolating the tie-break to just direction choice.
        $state = $this->boardState(hands: [1 => [29, 3], 2 => [85], 3 => [27]]);
        $state->moveHandToInPlay(1, 3); // Charity, value 1
        $state->moveHandToInPlay(2, 85); // Chaos, value 6
        $state->moveHandToInPlay(3, 27); // Ambivalence, value 6

        $action = $this->bot->chooseAction($state, [29], 1);

        self::assertSame(29, $action['card_id']);
        self::assertSame(['direction' => 'left'], $action['choices']);
    }

    /**
     * Cynicism (id 62, value 3) is deprioritized behind a higher-value
     * card (Apathy, id 55, value 4, no ability of its own) when neither
     * trigger applies: no discard-pile card to give away at all, and
     * nobody's own round score makes playing Cynicism the deciding
     * difference (both players start at 0, and 0 is not strictly below
     * 0).
     */
    public function testChooseActionDeprioritizesCynicismWithNoGoodReason(): void
    {
        $state = $this->boardState(hands: [1 => [62, 55]]);

        $action = $this->bot->chooseAction($state, [62, 55], 1);

        self::assertSame(55, $action['card_id']);
    }

    /**
     * With nothing else playable, Cynicism is still played -- deprioritized
     * WHEN, never skipped outright, the same "save it for later, but
     * still play it eventually" treatment Rationalization gets. No
     * discard-pile card exists to give away, so it's played unboosted.
     */
    public function testChooseActionStillPlaysCynicismUnboostedWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [62]]);

        $action = $this->bot->chooseAction($state, [62], 1);

        self::assertSame(62, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * A cheap discard-pile card (Charity, id 3, value 1, well under
     * CYNICISM_LOW_VALUE_DISCARD_THRESHOLD) makes Cynicism worth playing
     * right away -- boosted, since giving an opponent back a card this
     * weak barely helps them and the +3 (6 minus Cynicism's own printed
     * 3) is effectively free. Player 2 is the only other active player,
     * so it's also the only legal recipient.
     */
    public function testChooseActionBoostsCynicismWithACheapDiscardPileCard(): void
    {
        $state = $this->boardState(hands: [1 => [62], 2 => [3]]);
        $state->moveHandToDiscard(2, 3);

        $action = $this->bot->chooseAction($state, [62], 1);

        self::assertSame(62, $action['card_id']);
        self::assertSame(['discard_card_id' => 3, 'recipient_player_id' => 2], $action['choices']);
    }

    /**
     * No cheap discard-pile card exists here, but playing Cynicism for
     * its own plain printed value (3, unboosted) would be the deciding
     * difference between the bot's own score and the rival's (Cruelty,
     * id 61, value 3, in play for player 2 puts their total at 3 against
     * the bot's own 0), and nothing ELSE playable (Pacifism, id 20,
     * value 1, no EARLY_PRIORITY_EFFECT_KEYS bonus of its own) offers as
     * big a swing on its own -- "fine to play Cynicism for no extra
     * value" per the maintainer.
     */
    public function testChooseActionPlaysCynicismUnboostedWhenItDecidesTheRound(): void
    {
        $state = $this->boardState(hands: [1 => [62, 20], 2 => [61]]);
        $state->moveHandToInPlay(2, 61);

        $action = $this->bot->chooseAction($state, [62, 20], 1);

        self::assertSame(62, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * The same round-deciding setup as above, but this time Dignity (id
     * 8, value 3, tied with Cynicism's own printed value) is ALSO
     * playable -- since it already offers the same 3-point swing on its
     * own, Cynicism doesn't need to be the one that closes the gap, so
     * it stays deprioritized (PHP_INT_MIN) even though the round-deciding
     * trigger itself is satisfied. Cynicism is listed FIRST in
     * $playableCardIds specifically to prove this isn't just a tie won
     * by array order (PHP's stable sort would otherwise favor whichever
     * of two equal-priority candidates came first) -- Dignity wins
     * outright because Cynicism's own priority here is genuinely lower,
     * not merely tied and second in line.
     */
    public function testChooseActionKeepsCynicismDeprioritizedWhenAnotherCardAlreadyDecidesTheRound(): void
    {
        $state = $this->boardState(hands: [1 => [62, 8], 2 => [61]]);
        $state->moveHandToInPlay(2, 61);

        $action = $this->bot->chooseAction($state, [62, 8], 1);

        self::assertSame(8, $action['card_id']);
    }

    /**
     * The addendum to the Cynicism policy above (confirmed by the
     * maintainer): with no round-deciding swing available at all (both
     * players start at 0) and no cheap discard-pile card either,
     * Cynicism is STILL a fine first play -- Pacifism (id 20, value 1)
     * offers neither a 3+-point swing nor any hand interaction of its
     * own, so nothing better is on offer, and Cynicism's own plain
     * printed value (3) wins over it.
     */
    public function testChooseActionPlaysCynicismAsAFineFirstPlayWithNoBetterAlternative(): void
    {
        $state = $this->boardState(hands: [1 => [62, 20]]);

        $action = $this->bot->chooseAction($state, [62, 20], 1);

        self::assertSame(62, $action['card_id']);
    }

    /**
     * EARLY_PRIORITY_EFFECT_KEYS' own flat bonus (confirmed by the
     * maintainer, the same Cynicism addendum): Charity (id 3, value 1,
     * grants the acting player an extra play) outranks Apathy (id 55,
     * value 4, no ability of its own) despite its own much lower printed
     * value -- an extra play is worth leading with regardless of either
     * card's own printed value.
     */
    public function testChooseActionPrioritizesAnExtraPlayCardOverAHigherPlainValueCard(): void
    {
        $state = $this->boardState(hands: [1 => [3, 55]]);

        $action = $this->bot->chooseAction($state, [3, 55], 1);

        self::assertSame(3, $action['card_id']);
    }

    /**
     * The same EARLY_PRIORITY_EFFECT_KEYS bonus, this time for its
     * hand-interaction half rather than its extra-play half: Suspicion
     * (id 78, value 3, forces one or more opponents to discard from
     * their own hand) outranks Apathy (id 55, value 4) the same way.
     */
    public function testChooseActionPrioritizesAHandInteractionCardOverAHigherPlainValueCard(): void
    {
        $state = $this->boardState(hands: [1 => [78, 55]]);

        $action = $this->bot->chooseAction($state, [78, 55], 1);

        self::assertSame(78, $action['card_id']);
    }

    /**
     * Zeal's own "should I cycle" policy (confirmed by the maintainer):
     * with a genuinely low-value card sitting in hand (Charity, id 3,
     * value 1, well under ZEAL_LOW_VALUE_HAND_CARD_THRESHOLD), the bot
     * volunteers for its own optional bottom-and-redraw field, unlike
     * every other unforced-optional-field card, which would leave it
     * unfilled by default.
     */
    public function testChooseActionCyclesZealWithALowValueHandCard(): void
    {
        $state = $this->boardState(hands: [1 => [106, 3]]);

        $action = $this->bot->chooseAction($state, [106], 1);

        self::assertSame(106, $action['card_id']);
        self::assertSame(['hand_card_id' => 3], $action['choices']);
    }

    /**
     * With nothing else in hand to cycle, Zeal's own optional field
     * stays unfilled, the same as any other unforced optional field --
     * "if it has one to cycle" per the maintainer.
     */
    public function testChooseActionDoesNotCycleZealWithAnEmptyRemainingHand(): void
    {
        $state = $this->boardState(hands: [1 => [106]]);

        $action = $this->bot->chooseAction($state, [106], 1);

        self::assertSame(106, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Dignity (id 8, value 3) is too valuable to gamble on a random
     * replacement for -- above ZEAL_LOW_VALUE_HAND_CARD_THRESHOLD -- so
     * Zeal's own optional field stays unfilled here too.
     */
    public function testChooseActionDoesNotCycleZealWithOnlyAMediumValueHandCard(): void
    {
        $state = $this->boardState(hands: [1 => [106, 8]]);

        $action = $this->bot->chooseAction($state, [106], 1);

        self::assertSame(106, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Reported live: "when a bot plays ambition, it should discard a
     * card for another play if it has 3+ cards in hand and it has
     * another play that will net it points." With three cards in hand
     * (Ambition, id 53, plus Courage id 7/value 1 and Apathy id 55/
     * value 4), the bot volunteers for Ambition's own optional
     * discard_card_id field -- unlike every other unforced-optional-
     * field card, which would leave it unfilled by default -- and
     * BotChoiceResolver's own generic "N lowest-value legal candidates"
     * policy picks Courage (the cheapest OTHER hand card) as the
     * discard, leaving Apathy (value 4) to spend the unlocked extra
     * play on.
     */
    public function testChooseActionDiscardsForAmbitionsExtraPlayWithEnoughHandAndAScoringFollowUp(): void
    {
        $state = $this->boardState(hands: [1 => [53, 7, 55]]);

        $action = $this->bot->chooseAction($state, [53], 1);

        self::assertSame(53, $action['card_id']);
        self::assertSame(['discard_card_id' => 7], $action['choices']);
    }

    /**
     * Only two cards in hand total (Ambition plus one other) -- below
     * AMBITION_MIN_HAND_SIZE_TO_DISCARD (3) -- so discarding the other
     * card would leave nothing to spend the unlocked extra play on, a
     * pure loss for nothing. Ambition's own optional field stays
     * unfilled, the same as any other unforced optional field.
     */
    public function testChooseActionDoesNotDiscardForAmbitionWithTooSmallAHand(): void
    {
        $state = $this->boardState(hands: [1 => [53, 7]]);

        $action = $this->bot->chooseAction($state, [53], 1);

        self::assertSame(53, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Three cards in hand (enough by count), but once the cheapest
     * other hand card (Fickleness or Disorientation, both value 0) is
     * set aside as the discard cost, the only card left to spend the
     * unlocked extra play on is the OTHER value-0 card -- no genuine
     * scoring play to unlock, so Ambition's own optional field stays
     * unfilled rather than burning a hand card for nothing.
     */
    public function testChooseActionDoesNotDiscardForAmbitionWithNoScoringFollowUp(): void
    {
        $state = $this->boardState(hands: [1 => [53, 39, 35]]);

        $action = $this->bot->chooseAction($state, [53], 1);

        self::assertSame(53, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Intimidation's own "always target an opponent" policy (confirmed
     * by the maintainer): player 2 has a card in hand, so it's the only
     * legal, non-teammate target -- the bot volunteers for its own
     * optional target_player_id field rather than leaving it unfilled
     * the way an ordinary unforced optional field would default to.
     */
    public function testChooseActionTargetsAnOpponentWithACardWhenPlayingIntimidation(): void
    {
        $state = $this->boardState(hands: [1 => [67], 2 => [8]]);

        $action = $this->bot->chooseAction($state, [67], 1);

        self::assertSame(67, $action['card_id']);
        self::assertSame(['target_player_id' => 2], $action['choices']);
    }

    /**
     * With no opponent holding any card at all, targeting anyone would
     * be a pure no-op (IntimidationEffect's own pendingDecisionsFor()
     * silently skips an empty-handed target), so Intimidation is
     * deprioritized behind Spite (id 76, value 1, plain filler -- not
     * Pacifism, which is no longer a neutral filler itself now that it
     * has its own bespoke targeting policy) -- "other plays should be
     * prioritized above it" per the maintainer.
     */
    public function testChooseActionDeprioritizesIntimidationWhenNoOpponentHasACard(): void
    {
        $state = $this->boardState(hands: [1 => [67, 76]]);

        $action = $this->bot->chooseAction($state, [67, 76], 1);

        self::assertSame(76, $action['card_id']);
    }

    /**
     * With nothing else playable, Intimidation is still played --
     * deprioritized WHEN, never skipped outright, the same treatment
     * Rationalization/Cynicism already get. No opponent has a card, so
     * its own optional field stays unfilled.
     */
    public function testChooseActionStillPlaysIntimidationUnfilledWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [67]]);

        $action = $this->bot->chooseAction($state, [67], 1);

        self::assertSame(67, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Player 2 (seated first) has an empty hand; player 3 has a card.
     * The bot skips past the empty-handed seat to target player 3
     * instead of just taking the first legal candidate in seat order
     * the way BotChoiceResolver's own generic "player" field default
     * would -- targeting player 2 here would accomplish nothing.
     */
    public function testChooseActionSkipsAnEmptyHandedOpponentToTargetOneWithACard(): void
    {
        $state = $this->boardState(hands: [1 => [67], 3 => [8]]);

        $action = $this->bot->chooseAction($state, [67], 1);

        self::assertSame(67, $action['card_id']);
        self::assertSame(['target_player_id' => 3], $action['choices']);
    }

    /**
     * A teammate with a card in hand doesn't count as a valid target --
     * Intimidation's own printed text allows targeting anyone ("choose
     * ANOTHER player", no opponent restriction), but the maintainer's
     * own "opponent" framing means a teammate is deliberately excluded
     * here, the same distinction Cynicism's own recipient search already
     * draws. Player 2 (the bot's own teammate) has a card; player 3 (the
     * only actual opponent) has none -- no valid target exists, so
     * Intimidation is deprioritized behind Spite (id 76, value 1, plain
     * filler -- see testChooseActionDeprioritizesIntimidationWhenNoOpponentHasACard()'s
     * own docblock for why not Pacifism).
     */
    public function testChooseActionDoesNotCountATeammatesHandAsAValidIntimidationTarget(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [67, 76], 2 => [8]],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );

        $action = $this->bot->chooseAction($state, [67, 76], 1);

        self::assertSame(76, $action['card_id']);
    }

    /**
     * Paranoia's own "always target an opponent" policy (confirmed by
     * the maintainer, the identical policy to Intimidation): player 2
     * has a card in hand, so it's the only legal, non-teammate target.
     */
    public function testChooseActionTargetsAnOpponentWithACardWhenPlayingParanoia(): void
    {
        $state = $this->boardState(hands: [1 => [71], 2 => [8]]);

        $action = $this->bot->chooseAction($state, [71], 1);

        self::assertSame(71, $action['card_id']);
        self::assertSame(['target_player_id' => 2], $action['choices']);
    }

    /**
     * With no opponent holding any card at all, Paranoia is
     * deprioritized behind Spite (id 76, value 1, plain filler -- see
     * testChooseActionDeprioritizesIntimidationWhenNoOpponentHasACard()'s
     * own docblock for why not Pacifism) -- "other plays should be
     * prioritized above it" per the maintainer. Unlike Intimidation,
     * targeting an untargetable player here wouldn't just be a no-op --
     * ParanoiaEffect::afterPlaying() throws against an empty hand -- so
     * this also avoids an outright illegal play.
     */
    public function testChooseActionDeprioritizesParanoiaWhenNoOpponentHasACard(): void
    {
        $state = $this->boardState(hands: [1 => [71, 76]]);

        $action = $this->bot->chooseAction($state, [71, 76], 1);

        self::assertSame(76, $action['card_id']);
    }

    /**
     * With nothing else playable, Paranoia is still played --
     * deprioritized WHEN, never skipped outright. No opponent has a
     * card, so its own optional field stays unfilled.
     */
    public function testChooseActionStillPlaysParanoiaUnfilledWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [71]]);

        $action = $this->bot->chooseAction($state, [71], 1);

        self::assertSame(71, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Player 2 (seated first) has an empty hand; player 3 has a card.
     * The bot skips past the empty-handed seat to target player 3 --
     * targeting player 2 here would be an outright illegal play, not
     * just a wasted one.
     */
    public function testChooseActionSkipsAnEmptyHandedOpponentToTargetOneWithACardWithParanoia(): void
    {
        $state = $this->boardState(hands: [1 => [71], 3 => [8]]);

        $action = $this->bot->chooseAction($state, [71], 1);

        self::assertSame(71, $action['card_id']);
        self::assertSame(['target_player_id' => 3], $action['choices']);
    }

    /**
     * A teammate with a card in hand doesn't count as a valid target,
     * even though Paranoia's own CardChoiceSchema field is scope 'any'
     * (its printed text allows self-targeting too) -- "an opponent" per
     * the maintainer means neither the bot itself nor a teammate. Player
     * 2 (the bot's own teammate) has a card; player 3 (the only actual
     * opponent) has none -- no valid target exists, so Paranoia is
     * deprioritized behind Spite (id 76, value 1, plain filler -- see
     * testChooseActionDeprioritizesIntimidationWhenNoOpponentHasACard()'s
     * own docblock for why not Pacifism).
     */
    public function testChooseActionDoesNotCountATeammatesHandAsAValidParanoiaTarget(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [71, 76], 2 => [8]],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );

        $action = $this->bot->chooseAction($state, [71, 76], 1);

        self::assertSame(76, $action['card_id']);
    }

    /**
     * Pacifism's own "always suppress an opponent's mood" policy
     * (confirmed by the maintainer): player 2 has Dignity (id 8, value
     * 3) in play, the only legal, non-teammate target.
     */
    public function testChooseActionTargetsAnOpponentsMoodWhenPlayingPacifism(): void
    {
        $state = $this->boardState(hands: [1 => [20], 2 => [8]]);
        $state->moveHandToInPlay(2, 8);

        $action = $this->bot->chooseAction($state, [20], 1);

        self::assertSame(20, $action['card_id']);
        self::assertSame(['target_mood_ids' => [8]], $action['choices']);
    }

    /**
     * "One mood from each of two opponents, when possible" per the
     * maintainer: player 2 has Dignity (id 8, value 3) in play and
     * player 3 has Discipline (id 9, value 6) -- CardChoiceSchema's own
     * `distinct_owners` constraint already forbids two from the same
     * player, so filling both target slots here means one from each.
     * Sorted highest-value first (player 3's own Discipline, then player
     * 2's own Dignity), proving "the highest point opponent cards should
     * be targeted" holds across different opponents too, not just within
     * one opponent's own moods (see the next test for that half).
     */
    public function testChooseActionTargetsOneMoodFromEachOfTwoOpponentsWhenPlayingPacifism(): void
    {
        $state = $this->boardState(hands: [1 => [20], 2 => [8], 3 => [9]]);
        $state->moveHandToInPlay(2, 8);
        $state->moveHandToInPlay(3, 9);

        $action = $this->bot->chooseAction($state, [20], 1);

        self::assertSame(20, $action['card_id']);
        self::assertSame(['target_mood_ids' => [9, 8]], $action['choices']);
    }

    /**
     * When a single opponent has multiple moods in play, Pacifism only
     * ever suppresses that opponent's own HIGHEST-value one (per the
     * maintainer) -- CardChoiceSchema's own `distinct_owners` constraint
     * forbids taking both anyway, so Discipline (id 9, value 6) beats
     * Dignity (id 8, value 3), both owned by player 2.
     */
    public function testChooseActionTargetsTheHighestValueMoodWhenOneOpponentHasSeveral(): void
    {
        $state = $this->boardState(hands: [1 => [20], 2 => [8, 9]]);
        $state->moveHandToInPlay(2, 8);
        $state->moveHandToInPlay(2, 9);

        $action = $this->bot->chooseAction($state, [20], 1);

        self::assertSame(20, $action['card_id']);
        self::assertSame(['target_mood_ids' => [9]], $action['choices']);
    }

    /**
     * With no non-teammate opponent holding any mood in play at all,
     * suppressing nothing would be the only outcome, so Pacifism is
     * deprioritized behind Spite (id 76, value 1, plain filler) --
     * "other plays should be prioritized above it" per the maintainer.
     */
    public function testChooseActionDeprioritizesPacifismWhenNoOpponentHasAMoodInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [20, 76]]);

        $action = $this->bot->chooseAction($state, [20, 76], 1);

        self::assertSame(76, $action['card_id']);
    }

    /**
     * With nothing else playable, Pacifism is still played --
     * deprioritized WHEN, never skipped outright. No opponent has a
     * mood in play, so its own optional field stays unfilled.
     */
    public function testChooseActionStillPlaysPacifismUnfilledWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [20]]);

        $action = $this->bot->chooseAction($state, [20], 1);

        self::assertSame(20, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * A teammate's mood in play doesn't count as a valid target, even
     * though Pacifism's own CardChoiceSchema field is scope 'any' (its
     * printed text says "choose up to two players" with no restriction
     * against the acting player or a teammate) -- "an opponent" per the
     * maintainer means neither. Player 2 (the bot's own teammate) has
     * Dignity in play; player 3 (the only actual opponent) has no mood
     * at all -- no valid target exists, so Pacifism is deprioritized
     * behind Spite.
     */
    public function testChooseActionDoesNotCountATeammatesMoodAsAValidPacifismTarget(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [20, 76], 2 => [8]],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );
        $state->moveHandToInPlay(2, 8);

        $action = $this->bot->chooseAction($state, [20, 76], 1);

        self::assertSame(76, $action['card_id']);
    }

    // -- Shock (reported live: bots should target an opponent's mood) ------

    public function testChooseActionTargetsAnOpponentsMoodWhenPlayingShock(): void
    {
        $state = $this->boardState(hands: [1 => [101], 2 => [8]]); // Shock, Dignity (value 3)
        $state->moveHandToInPlay(2, 8);

        $action = $this->bot->chooseAction($state, [101], 1);

        self::assertSame(101, $action['card_id']);
        self::assertSame(['target_mood_ids' => [8]], $action['choices']);
    }

    /**
     * "One mood from each of two opponents, when possible" -- same
     * `distinct_owners`-driven preference pacifismTargetMoodIds() applies,
     * sorted highest-qualifying-value first: player 2's own Dignity
     * (id 8, value 3) beats player 3's own Spite (id 76, value 1).
     */
    public function testChooseActionTargetsOneMoodFromEachOfTwoOpponentsWhenPlayingShock(): void
    {
        $state = $this->boardState(hands: [1 => [101], 2 => [8], 3 => [76]]);
        $state->moveHandToInPlay(2, 8);
        $state->moveHandToInPlay(3, 76);

        $action = $this->bot->chooseAction($state, [101], 1);

        self::assertSame(101, $action['card_id']);
        self::assertSame(['target_mood_ids' => [8, 76]], $action['choices']);
    }

    /**
     * When a single opponent has multiple qualifying moods in play, Shock
     * only ever targets that opponent's own HIGHEST-value one (mirroring
     * Pacifism) -- Dignity (id 8, value 3) beats Courage (id 7, value 1),
     * both owned by player 2 and both within the value-3 cap.
     */
    public function testChooseActionTargetsTheHighestQualifyingValueMoodWhenOneOpponentHasSeveral(): void
    {
        $state = $this->boardState(hands: [1 => [101], 2 => [8, 7]]);
        $state->moveHandToInPlay(2, 8);
        $state->moveHandToInPlay(2, 7);

        $action = $this->bot->chooseAction($state, [101], 1);

        self::assertSame(101, $action['card_id']);
        self::assertSame(['target_mood_ids' => [8]], $action['choices']);
    }

    /**
     * Discipline (id 9, value 6) exceeds Shock's own value-3 cap, so it's
     * never a legal target at all -- with no other opponent mood in play,
     * Shock's own optional field stays unfilled rather than the bot
     * illegally (or pointlessly) reaching for it.
     */
    public function testChooseActionDoesNotTargetAMoodAboveTheValueLimitWhenPlayingShock(): void
    {
        $state = $this->boardState(hands: [1 => [101], 2 => [9]]);
        $state->moveHandToInPlay(2, 9);

        $action = $this->bot->chooseAction($state, [101], 1);

        self::assertSame(101, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * A teammate's mood in play doesn't count as a valid target, even
     * though Shock's own CardChoiceSchema field is scope 'any' (its
     * printed text says "choose up to two players" with no restriction
     * against the acting player or a teammate) -- "an opponent" means
     * neither, the same policy Pacifism above already applies. Player 2
     * (the bot's own teammate) has Dignity in play; player 3 (the only
     * actual opponent) has no mood at all -- no valid target exists.
     */
    public function testChooseActionDoesNotCountATeammatesMoodAsAValidShockTarget(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [101], 2 => [8]],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );
        $state->moveHandToInPlay(2, 8);

        $action = $this->bot->chooseAction($state, [101], 1);

        self::assertSame(101, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Reported live: "Bots should avoid playing Shock without a target
     * opponent mood for it - exception for when they just need 2 points
     * to win a game." With no in-play moods at all (nothing for
     * shockTargetMoodIds() to find), Shock (value 2) is deprioritized
     * behind Courage (value 1) despite its own higher printed value --
     * without the veto, Shock's own 2 would otherwise win the sort
     * outright.
     */
    public function testChooseActionDemotesShockBehindALowerValueCardWhenNoTargetExists(): void
    {
        $state = $this->boardState(hands: [1 => [101, 7]]);

        $action = $this->bot->chooseAction($state, [101, 7], 1);

        self::assertSame(7, $action['card_id']);
    }

    /**
     * The reported exception: the opponent's only mood, Confusion (id
     * 31, value 4), is ABOVE SHOCK_MAX_TARGET_VALUE (3) -- not a legal
     * Shock target at all, so shockTargetMoodIds() finds nothing. The
     * bot's own Benevolence (id 2, value 2, already in play) keeps its
     * own baseline BELOW the opponent's 4, but playing Shock for its own
     * plain printed value (2) alone closes the gap exactly (2 + 2 = 4),
     * taking the round's lead, AND $roundWinsNeededToWinGame says
     * winning this round would win the whole game outright.
     */
    public function testShockHasAGoodReasonToPlayNowWhenItWouldWinTheGame(): void
    {
        $state = $this->boardState(hands: [1 => [101, 2], 2 => [31]]);
        $state->moveHandToInPlay(1, 2);
        $state->moveHandToInPlay(2, 31);

        self::assertTrue($this->bot->hasGoodReasonToPlayNow($state, 101, 1, [101], roundWinsNeededToWinGame: 1));
    }

    /** Same board as above, but $roundWinsNeededToWinGame is 2 -- winning THIS round wouldn't be enough on its own, so the carve-out doesn't apply. */
    public function testShockHasNoGoodReasonToPlayNowWhenGameWinIsNotClose(): void
    {
        $state = $this->boardState(hands: [1 => [101, 2], 2 => [31]]);
        $state->moveHandToInPlay(1, 2);
        $state->moveHandToInPlay(2, 31);

        self::assertFalse($this->bot->hasGoodReasonToPlayNow($state, 101, 1, [101], roundWinsNeededToWinGame: 2));
    }

    /**
     * $roundWinsNeededToWinGame is 1 again (winning this round WOULD win
     * the game), but the opponent's own in-play total (Betrayal, id 56,
     * value 6 -- also above SHOCK_MAX_TARGET_VALUE, so still no legal
     * target) is too far ahead for Shock's own value (2) to close: 0 + 2
     * = 2 is still short of 6. Being close to winning the game isn't
     * enough by itself -- the round has to be winnable too.
     */
    public function testShockHasNoGoodReasonToPlayNowWhenItWouldNotTakeTheRoundLead(): void
    {
        $state = $this->boardState(hands: [1 => [101], 2 => [56]]);
        $state->moveHandToInPlay(2, 56);

        self::assertFalse($this->bot->hasGoodReasonToPlayNow($state, 101, 1, [101], roundWinsNeededToWinGame: 1));
    }

    /**
     * Corruption's own live "awardsExtraWin" marker means winning the
     * round in progress would award 2 round wins at once, so
     * $roundWinsNeededToWinGame of 2 still counts as "winning this round
     * wins the game." Bot's own baseline (Corruption itself, value 2) is
     * behind the opponent's Confusion (id 31, value 4 -- above
     * SHOCK_MAX_TARGET_VALUE, so still no legal target), but adding
     * Shock's own value (2) reaches 4, taking the lead.
     */
    public function testChooseActionPlaysShockForPointsWithCorruptionsDoubleWinMarker(): void
    {
        $state = $this->boardState(hands: [1 => [101, 60], 2 => [31]]);
        $state->moveHandToInPlay(2, 31);
        $state->moveHandToInPlay(1, 60);
        $state->setEffectState(60, 'awardsExtraWin', true);

        self::assertTrue($this->bot->hasGoodReasonToPlayNow($state, 101, 1, [101], roundWinsNeededToWinGame: 2));
    }

    // -- Exhilaration (reported live: don't sacrifice Bliss to it) ---------

    public function testChooseActionAvoidsSacrificingBlissWhenAnotherMoodIsAvailable(): void
    {
        $state = $this->boardState(hands: [1 => [89, 108, 38]]); // Exhilaration, Bliss, Fear (value 0)
        $state->moveHandToInPlay(1, 108);
        $state->moveHandToInPlay(1, 38);

        $action = $this->bot->chooseAction($state, [89], 1);

        self::assertSame(89, $action['card_id']);
        self::assertSame(['discard_mood_id' => 38], $action['choices']);
    }

    /**
     * Bliss (id 108, value 2) has a LOWER face value than Discipline
     * (id 9, value 6) -- the generic "give up whatever's cheapest" policy
     * would have picked Bliss precisely because of that. Exhilaration's
     * own bespoke policy ignores face value entirely for Bliss, so
     * Discipline is sacrificed instead despite being "more expensive."
     */
    public function testChooseActionAvoidsSacrificingBlissEvenWhenItsFaceValueIsLower(): void
    {
        $state = $this->boardState(hands: [1 => [89, 108, 9]]); // Exhilaration, Bliss, Discipline (value 6)
        $state->moveHandToInPlay(1, 108);
        $state->moveHandToInPlay(1, 9);

        $action = $this->bot->chooseAction($state, [89], 1);

        self::assertSame(89, $action['card_id']);
        self::assertSame(['discard_mood_id' => 9], $action['choices']);
    }

    /**
     * With Bliss as the bot's own ONLY mood in play, paying Exhilaration's
     * cost means discarding Bliss regardless -- Exhilaration is
     * deprioritized behind Spite (id 76, value 1, plain filler) rather
     * than led with blindly, the same "other plays should be prioritized
     * above it" treatment Pacifism/Denial above already get.
     */
    public function testChooseActionDeprioritizesExhilarationWhenBlissIsTheOnlyMoodInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [89, 76, 108]]);
        $state->moveHandToInPlay(1, 108);

        $action = $this->bot->chooseAction($state, [89, 76], 1);

        self::assertSame(76, $action['card_id']);
    }

    /**
     * With nothing else playable, Exhilaration is still played --
     * deprioritized WHEN, never skipped outright -- forced to sacrifice
     * Bliss since it's the bot's only own mood in play.
     */
    public function testChooseActionStillPlaysExhilarationSacrificingBlissWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [89, 108]]);
        $state->moveHandToInPlay(1, 108);

        $action = $this->bot->chooseAction($state, [89], 1);

        self::assertSame(89, $action['card_id']);
        self::assertSame(['discard_mood_id' => 108], $action['choices']);
    }

    // -- Harmony (confirmed by the maintainer) -----------------------------

    /**
     * Harmony's own extra play is restricted to a card FROM the discard
     * pile -- with the pile completely empty, that grant accomplishes
     * nothing, so Harmony (id 123, value 2) is deprioritized behind
     * Apathy (id 55, value 4, plain filler) -- "avoid playing it until
     * there are cards in the discard pile to play" per the maintainer.
     */
    public function testChooseActionDeprioritizesHarmonyWhenTheDiscardPileIsEmpty(): void
    {
        $state = $this->boardState(hands: [1 => [123, 55]]);

        $action = $this->bot->chooseAction($state, [123, 55], 1);

        self::assertSame(55, $action['card_id']);
    }

    /**
     * The instant the discard pile has even one card in it, Harmony
     * reverts to its ordinary EARLY_PRIORITY_EFFECT_KEYS boosted
     * treatment -- its own value (2) plus EARLY_PRIORITY_BONUS (10)
     * comfortably outranks Apathy's plain value (4).
     */
    public function testChooseActionPrioritizesHarmonyWhenTheDiscardPileHasACard(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [123, 55]],
            discard: [8],
        );

        $action = $this->bot->chooseAction($state, [123, 55], 1);

        self::assertSame(123, $action['card_id']);
    }

    /**
     * With nothing else playable, Harmony is still played -- deprioritized
     * WHEN, never skipped outright.
     */
    public function testChooseActionStillPlaysHarmonyWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [123]]);

        $action = $this->bot->chooseAction($state, [123], 1);

        self::assertSame(123, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    // -- Grief (confirmed by the maintainer) --------------------------------

    /**
     * Grief's own extra plays are restricted to cards FROM the discard
     * pile, same as Harmony -- with the pile completely empty, that
     * grant accomplishes nothing, so Grief (id 65, value 0) is
     * deprioritized behind Apathy (id 55, value 4, plain filler).
     */
    public function testChooseActionDeprioritizesGriefWhenTheDiscardPileIsEmpty(): void
    {
        $state = $this->boardState(hands: [1 => [65, 55]]);

        $action = $this->bot->chooseAction($state, [65, 55], 1);

        self::assertSame(55, $action['card_id']);
    }

    /**
     * The instant the discard pile has even one card in it, Grief
     * reverts to its ordinary EARLY_PRIORITY_EFFECT_KEYS boosted
     * treatment -- its own value (0) plus EARLY_PRIORITY_BONUS (10)
     * comfortably outranks Apathy's plain value (4).
     */
    public function testChooseActionPrioritizesGriefWhenTheDiscardPileHasACard(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [65, 55]],
            discard: [8],
        );

        $action = $this->bot->chooseAction($state, [65, 55], 1);

        self::assertSame(65, $action['card_id']);
    }

    /**
     * With nothing else playable, Grief is still played -- deprioritized
     * WHEN, never skipped outright.
     */
    public function testChooseActionStillPlaysGriefWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [65]]);

        $action = $this->bot->chooseAction($state, [65], 1);

        self::assertSame(65, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    // -- Nostalgia (confirmed by the maintainer) ---------------------------

    /**
     * Nostalgia's own "you may put a card from the discard pile into
     * your hand" half of the effect has nothing to take from with the
     * pile completely empty, so Nostalgia (id 128, value 0) is
     * deprioritized behind Apathy (id 55, value 4, plain filler) -- the
     * same "save it until there's something in the discard pile to take"
     * policy Harmony above already has, per the maintainer.
     */
    public function testChooseActionDeprioritizesNostalgiaWhenTheDiscardPileIsEmpty(): void
    {
        $state = $this->boardState(hands: [1 => [128, 55]]);

        $action = $this->bot->chooseAction($state, [128, 55], 1);

        self::assertSame(55, $action['card_id']);
    }

    /**
     * The instant the discard pile has even one card in it, Nostalgia
     * reverts to its ordinary EARLY_PRIORITY_EFFECT_KEYS boosted
     * treatment -- its own value (0) plus EARLY_PRIORITY_BONUS (10)
     * comfortably outranks Apathy's plain value (4).
     */
    public function testChooseActionPrioritizesNostalgiaWhenTheDiscardPileHasACard(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [128, 55]],
            discard: [8],
        );

        $action = $this->bot->chooseAction($state, [128, 55], 1);

        self::assertSame(128, $action['card_id']);
    }

    /**
     * With nothing else playable, Nostalgia is still played -- its own
     * extra-play grant is unconditional and unrestricted (unlike
     * Harmony's own discard-sourced one), so an empty discard pile only
     * ever costs it the discard-pickup half, never the extra play
     * itself. Deprioritized WHEN, never skipped outright.
     */
    public function testChooseActionStillPlaysNostalgiaWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [128]]);

        $action = $this->bot->chooseAction($state, [128], 1);

        self::assertSame(128, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * With more than one card in the discard pile and nothing in play
     * that depends on it, Nostalgia targets the HIGHEST-baseValue()
     * discard card (Chaos, id 85, value 6) over the lower-value one
     * (Apathy, id 55, value 4) -- confirmed by the maintainer.
     */
    public function testChooseActionTargetsTheHighestValueDiscardCardWhenPlayingNostalgia(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [128]],
            discard: [55, 85],
        );

        $action = $this->bot->chooseAction($state, [128], 1);

        self::assertSame(128, $action['card_id']);
        self::assertSame(['discard_card_id' => 85], $action['choices']);
    }

    /**
     * Sadness (id 74) already in play for the acting bot depends on the
     * discard pile staying full for its own whileInPlay value -- taking
     * a card out of it would undo part of that, so Nostalgia's own
     * discard_card_id field is left unfilled even though the pile has a
     * perfectly good candidate sitting in it. Confirmed by the
     * maintainer.
     */
    public function testChooseActionSkipsTheDiscardPickupWhenPlayingNostalgiaWithSadnessInPlay(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [128, 74]],
            discard: [55],
        );
        $state->moveHandToInPlay(1, 74);

        $action = $this->bot->chooseAction($state, [128], 1);

        self::assertSame(128, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Same policy, Wonder (id 133) -- the other DISCARD_PILE_VALUE_SOURCE_EFFECT_KEYS
     * card. Confirmed by the maintainer.
     */
    public function testChooseActionSkipsTheDiscardPickupWhenPlayingNostalgiaWithWonderInPlay(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            hands: [1 => [128, 133]],
            discard: [55],
        );
        $state->moveHandToInPlay(1, 133);

        $action = $this->bot->chooseAction($state, [128], 1);

        self::assertSame(128, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    // -- Fear (confirmed by the maintainer) ----------------------------------

    /**
     * Fear (id 38, value 0) is always worth 0 points on its own, and the
     * bot's hand holds no mood-counting or blue-caring synergy card to
     * make its extra play worthwhile -- deprioritized behind Apathy (id
     * 55, value 4, plain filler).
     */
    public function testChooseActionDeprioritizesFearWithNoSynergyCardInHand(): void
    {
        $state = $this->boardState(hands: [1 => [38, 55]]);

        $action = $this->bot->chooseAction($state, [38, 55], 1);

        self::assertSame(55, $action['card_id']);
    }

    /**
     * With Euphoria (id 117, a MOOD_COUNTING_EFFECT_KEYS card) also in
     * hand, Fear's extra play can put it into play the very same turn --
     * Fear reverts to its ordinary EARLY_PRIORITY_EFFECT_KEYS boosted
     * treatment (0 + 10 = 10), outranking Apathy's plain 4.
     */
    public function testChooseActionPrioritizesFearWithAMoodCountingSynergyCardInHand(): void
    {
        $state = $this->boardState(hands: [1 => [38, 117, 55]]);

        $action = $this->bot->chooseAction($state, [38, 55], 1);

        self::assertSame(38, $action['card_id']);
    }

    /**
     * Same policy, Love (id 127, a BLUE_CARING_EFFECT_KEYS card whose own
     * value depends on a blue mood being in play among others) -- Fear is
     * itself blue, so playing it directly feeds Love's own condition.
     */
    public function testChooseActionPrioritizesFearWithABlueCaringSynergyCardInHand(): void
    {
        $state = $this->boardState(hands: [1 => [38, 127, 55]]);

        $action = $this->bot->chooseAction($state, [38, 55], 1);

        self::assertSame(38, $action['card_id']);
    }

    /**
     * With nothing else playable, Fear is still played -- deprioritized
     * WHEN, never skipped outright.
     */
    public function testChooseActionStillPlaysFearWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [38]]);

        $action = $this->bot->chooseAction($state, [38], 1);

        self::assertSame(38, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    // -- Denial (confirmed by the maintainer) -------------------------------

    /**
     * Player 2 has Apathy (id 55, value 4) and Complacency (id 5, value
     * 4) in play -- different colors, but the same live value, so they
     * satisfy Denial's own same-color-or-value constraint. Removing both
     * (back to player 2's own hand) drops their round score to 0; the
     * bot's own total is just Denial's own base value (1) once played,
     * which is already >= 0 -- an outright win, so Denial targets them
     * rather than leading with its own (empty) hand for a replay.
     */
    public function testChooseActionTargetsAWinningOpponentPairWhenPlayingDenial(): void
    {
        $state = $this->boardState(hands: [1 => [34], 2 => [55, 5]]);
        $state->moveHandToInPlay(2, 55);
        $state->moveHandToInPlay(2, 5);

        $action = $this->bot->chooseAction($state, [34], 1);

        self::assertSame(34, $action['card_id']);
        self::assertSame(['target_mood_ids' => [55, 5]], $action['choices']);
    }

    /**
     * Player 2 has a same-color pair (Nostalgia id 128, Harmony id 123,
     * both green, values 0 and 2) in play -- the only legal pair here,
     * since player 3's own Chaos (id 85, red, value 6) shares neither
     * color nor value with either one, so it can never be paired with
     * anything and stays untouchable. Removing player 2's own pair only
     * drops THEIR total from 2 to 0; player 3's own 6 is completely
     * unaffected and still exceeds the bot's post-play total (1,
     * Denial's own base value) either way. Not an outright win, so
     * Denial doesn't target them; with no own low-value after-playing
     * mood in play either to fall back on, it's played with no targets
     * at all, exactly as if this policy didn't exist.
     */
    public function testChooseActionDoesNotTargetALosingOpponentPairWhenPlayingDenial(): void
    {
        $state = $this->boardState(hands: [1 => [34], 2 => [128, 123], 3 => [85]]);
        $state->moveHandToInPlay(2, 128);
        $state->moveHandToInPlay(2, 123);
        $state->moveHandToInPlay(3, 85);

        $action = $this->bot->chooseAction($state, [34], 1);

        self::assertSame(34, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * "One from each of two opponents" wins just as validly as "both
     * from one opponent" (the first test above) -- Denial's own field
     * has no `distinct_owners` constraint the way Pacifism's does.
     * Player 2's own Pacifism (id 20, white, value 1) and player 3's own
     * Discipline (id 9, white, value 6) share a color, so they're a
     * legal pair; removing both drops player 2 to 0 and player 3 to 0,
     * while the bot's post-play total (1) already clears that -- an
     * outright win, so Denial targets them over the smaller same-value
     * pair (Spite id 76 + that same Pacifism) also available to player
     * 2 alone, since 20+9's own combined value (7) beats 76+20's (2) and
     * both qualify as winning pairs.
     */
    public function testChooseActionTargetsAWinningPairAcrossTwoDifferentOpponentsWhenPlayingDenial(): void
    {
        $state = $this->boardState(hands: [1 => [34], 2 => [76, 20], 3 => [9]]);
        $state->moveHandToInPlay(2, 76);
        $state->moveHandToInPlay(2, 20);
        $state->moveHandToInPlay(3, 9);

        $action = $this->bot->chooseAction($state, [34], 1);

        self::assertSame(34, $action['card_id']);
        self::assertSame(['target_mood_ids' => [20, 9]], $action['choices']);
    }

    /**
     * With no winning opponent pair available (no opponent moods in play
     * at all here), Denial falls back to its own Charity (id 3, value 1)
     * and Kindness (id 17, value 2) -- both cheap enough
     * (DENIAL_REPLAY_MAX_VALUE) and both carry their own "after playing
     * this mood" ability, and share the same color (white) -- returning
     * both to the bot's own hand lets it replay each one's ability all
     * over again.
     */
    public function testChooseActionTargetsOwnLowValueAfterPlayingPairWhenPlayingDenialWithNoWinningOpponentPair(): void
    {
        $state = $this->boardState(hands: [1 => [34, 3, 17]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 17);

        $action = $this->bot->chooseAction($state, [34], 1);

        self::assertSame(34, $action['card_id']);
        self::assertSame(['target_mood_ids' => [3, 17]], $action['choices']);
    }

    /**
     * A teammate's same-value pair doesn't count as a valid "win the
     * round" target, even though removing it would mathematically clear
     * the same bar an opponent's pair would -- "opponent card(s)" per
     * the maintainer means neither the bot's own moods nor a teammate's.
     * Player 2 (the bot's own teammate) has the matching Apathy/
     * Complacency pair from the first test above; player 3 (the only
     * actual opponent) has nothing in play. With no own low-value
     * after-playing mood to fall back on either, Denial is played with
     * no targets at all.
     */
    public function testChooseActionDoesNotCountATeammatesMoodAsAValidDenialWinTarget(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [34], 2 => [55, 5]],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );
        $state->moveHandToInPlay(2, 55);
        $state->moveHandToInPlay(2, 5);

        $action = $this->bot->chooseAction($state, [34], 1);

        self::assertSame(34, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * The bot's own Charity (id 3, value 1) is the only replay
     * candidate in play -- its own Dignity (id 8, value 3, also white)
     * is too expensive to qualify (DENIAL_REPLAY_MAX_VALUE), so it can't
     * pair with a SECOND own candidate. Both Dignity and player 2's own
     * Pacifism (id 20, value 1, also white) legally satisfy the
     * same-color constraint as a second target -- the opponent's mood is
     * preferred, so the "filler" second target never costs the bot a
     * second good card of its own just to enable one cheap replay.
     */
    public function testChooseActionPrefersAnOpponentsMoodOverASecondOwnMoodAsDenialReplayFiller(): void
    {
        $state = $this->boardState(hands: [1 => [34, 3, 8], 2 => [20]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 8);
        $state->moveHandToInPlay(2, 20);

        $action = $this->bot->chooseAction($state, [34], 1);

        self::assertSame(34, $action['card_id']);
        self::assertSame(['target_mood_ids' => [3, 20]], $action['choices']);
    }

    /**
     * With no target to bounce (nothing in play at all) and no scenario
     * where Denial's own plain value (1) would win the round (both
     * players tied at 0 already), Denial is deprioritized behind Apathy
     * (id 55, value 4, plain filler) -- "avoid playing it unless there's
     * a good target, or the point alone wins the round" per the
     * maintainer.
     */
    public function testChooseActionDeprioritizesDenialWithNoGoodTargetAndNoRoundWinningValue(): void
    {
        $state = $this->boardState(hands: [1 => [34, 55]]);

        $action = $this->bot->chooseAction($state, [34, 55], 1);

        self::assertSame(55, $action['card_id']);
    }

    /**
     * Player 2's own Apathy (id 55, value 4) and Complacency (id 5, value
     * 4) pair is a "significant swing" (combined 8, clears
     * DENIAL_SIGNIFICANT_SWING_THRESHOLD) but NOT a round-winning one --
     * player 3's own Chaos (id 85, value 6) stays completely unaffected
     * and still exceeds the bot's post-play total (1) either way, the
     * same non-winning setup as
     * testChooseActionDoesNotTargetALosingOpponentPairWhenPlayingDenial()
     * above, just with a bigger (8, not 2) pair. That's still a genuinely
     * meaningful cost to player 2 even without flipping the round's own
     * outcome, so Denial is NOT deprioritized -- it beats Creativity (id
     * 32, value 0, plain filler) -- and denialTargetMoodIds() itself
     * chases that same pair (denialSignificantSwingTargetMoodIds(), its
     * own priority 2, since priority 1's own winning check still fails).
     */
    public function testChooseActionTargetsASignificantNonWinningSwingWhenPlayingDenial(): void
    {
        $state = $this->boardState(hands: [1 => [34, 32], 2 => [55, 5], 3 => [85]]);
        $state->moveHandToInPlay(2, 55);
        $state->moveHandToInPlay(2, 5);
        $state->moveHandToInPlay(3, 85);

        $action = $this->bot->chooseAction($state, [34, 32], 1);

        self::assertSame(34, $action['card_id']);
        self::assertSame(['target_mood_ids' => [55, 5]], $action['choices']);
    }

    /**
     * Player 2's own Charity (id 3, value 1) is the round's current best
     * rival score, one point ahead of the bot's own total (0) -- with
     * only a single opponent mood in play, no pair (winning, significant
     * swing, or otherwise) is even possible, so Denial has no good target
     * to bounce. But playing it for its own plain value alone (1, no
     * target at all) brings the bot's own total up to meet player 2's --
     * "the point from it will win the round" exception, worth playing
     * even with no good target to bounce. Beats Creativity (id 32, value
     * 0, plain filler).
     */
    public function testChooseActionPlaysDenialWithNoGoodTargetWhenItsOwnPlainValueWinsTheRound(): void
    {
        $state = $this->boardState(hands: [1 => [34, 32], 2 => [3]]);
        $state->moveHandToInPlay(2, 3);

        $action = $this->bot->chooseAction($state, [34, 32], 1);

        self::assertSame(34, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    // -- Envy (confirmed by the maintainer) ----------------------------------

    /**
     * Same setup as testChooseActionDoesNotExemptAnExtraPlayGrantingLowValueCardWhenNoOther4PointPlayExistsDespiteEnvy()
     * below, but Wrath is the bot's only playable card -- deprioritized
     * WHEN, never skipped outright, the same policy every other
     * sortPriorityValue() veto in this class already follows.
     */
    public function testChooseActionStillPlaysALowValueCardWhenNothingElseIsPlayableDespiteEnvy(): void
    {
        $state = $this->boardState(hands: [1 => [105], 2 => [64]]);
        $state->moveHandToInPlay(2, 64);

        $action = $this->bot->chooseAction($state, [105], 1);

        self::assertSame(105, $action['card_id']);
    }

    /**
     * Charity (id 3, value 1, grants an extra play) is exempt from the
     * Envy veto because Apathy (id 55, value 4) is also playable --
     * playing Charity "enables" that bigger play the same turn, worth
     * the same one point of Envy risk either way. Charity's own
     * EARLY_PRIORITY_EFFECT_KEYS boost (1 + 10 = 11) then outranks
     * Apathy's plain 4.
     */
    public function testChooseActionExemptsAnExtraPlayGrantingLowValueCardFromTheEnvyVetoWhenItEnablesA4PointPlay(): void
    {
        $state = $this->boardState(hands: [1 => [3, 55], 2 => [64]]);
        $state->moveHandToInPlay(2, 64);

        $action = $this->bot->chooseAction($state, [3, 55], 1);

        self::assertSame(3, $action['card_id']);
    }

    /**
     * Same Charity, but the only other playable card is Doubt (id 36,
     * value 2) -- below the 4-point bar, so the exception doesn't apply
     * and Charity is deprioritized like any other low-value card despite
     * its own extra-play grant.
     */
    public function testChooseActionDoesNotExemptAnExtraPlayGrantingLowValueCardWhenNoOther4PointPlayExistsDespiteEnvy(): void
    {
        $state = $this->boardState(hands: [1 => [3, 36], 2 => [64]]);
        $state->moveHandToInPlay(2, 64);

        $action = $this->bot->chooseAction($state, [3, 36], 1);

        self::assertSame(36, $action['card_id']);
    }

    /**
     * A teammate's own Envy is never the target of this policy --
     * EnvyEffect::computeValue() itself only ever counts a non-teammate's
     * own mood total toward "moodiest opponent", so the acting bot's own
     * low-value plays can never pump a TEAMMATE's Envy. Same Charity
     * (id 3) + Doubt (id 36) setup as
     * testChooseActionDoesNotExemptAnExtraPlayGrantingLowValueCardWhenNoOther4PointPlayExistsDespiteEnvy()
     * above -- there, Charity is deprioritized behind Doubt because
     * player 2's Envy belongs to a non-teammate opponent; here, player 2
     * is on the bot's own team (Open Team Play), so the veto never
     * engages at all and Charity's own EARLY_PRIORITY_EFFECT_KEYS boost
     * (1 + 10 = 11) wins outright over Doubt's plain 2, exactly as if
     * Envy didn't exist.
     */
    public function testChooseActionDoesNotApplyTheEnvyVetoForATeammatesEnvy(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [3, 36], 2 => [64]],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );
        $state->moveHandToInPlay(2, 64);

        $action = $this->bot->chooseAction($state, [3, 36], 1);

        self::assertSame(3, $action['card_id']);
    }

    // -- Contempt (confirmed by the maintainer) ------------------------------

    /**
     * No opponent has a green or white mood in play, and playing Contempt
     * (id 59, value 1) for its own plain value wouldn't decide the round
     * (every score is 0) -- deprioritized behind Wrath (id 105, value 0,
     * no ability), proving the veto actively suppresses Contempt below
     * even a LOWER-value alternative, not just something that would
     * already outrank it by plain baseValue().
     */
    public function testChooseActionDeprioritizesContemptWithNoTargetAndNoRoundToWin(): void
    {
        $state = $this->boardState(hands: [1 => [59, 105]]);

        $action = $this->bot->chooseAction($state, [59, 105], 1);

        self::assertSame(105, $action['card_id']);
    }

    /**
     * Player 2 has Complacency (id 5, white, value 4) in play -- a legal
     * target, so Contempt is played (outranking Wrath once the veto
     * lifts) and targets it in single mode.
     */
    public function testChooseActionTargetsAnOpponentsQualifyingMoodWhenPlayingContempt(): void
    {
        $state = $this->boardState(hands: [1 => [59, 105], 2 => [5]]);
        $state->moveHandToInPlay(2, 5);

        $action = $this->bot->chooseAction($state, [59, 105], 1);

        self::assertSame(59, $action['card_id']);
        self::assertSame(['mode' => 'single', 'target_mood_id' => 5], $action['choices']);
    }

    /**
     * The bot's OWN Complacency doesn't count as a legal target --
     * ContemptEffect's own field has no owner restriction, so
     * BotChoiceResolver's generic default would happily let the bot
     * discard its own mood, but "an opponent" per the maintainer means
     * it never does. With no opponent mood either, and no round to win,
     * Contempt is deprioritized behind Wrath exactly as if the bot had
     * no moods in play at all.
     */
    public function testChooseActionDoesNotTargetTheBotsOwnQualifyingMoodWhenPlayingContempt(): void
    {
        $state = $this->boardState(hands: [1 => [59, 105, 5]]);
        $state->moveHandToInPlay(1, 5);

        $action = $this->bot->chooseAction($state, [59, 105], 1);

        self::assertSame(105, $action['card_id']);
    }

    /**
     * Player 2 has Intimidation (id 67, black, value 1) in play -- not a
     * legal Contempt target (wrong color), so contemptTargetMoodId()
     * finds nothing. But the bot's own round total is currently 0 while
     * player 2's is 1, and playing Contempt for its own plain value (1,
     * no ability) would bring the bot's own total to 1, tying/clearing
     * player 2's -- the deciding difference between losing and winning
     * the round, so Contempt is played anyway, purely for its own value
     * (no legal target to fill in).
     */
    public function testChooseActionPlaysContemptForItsOwnValueWhenItWouldWinTheRound(): void
    {
        $state = $this->boardState(hands: [1 => [59, 105], 2 => [67]]);
        $state->moveHandToInPlay(2, 67);

        $action = $this->bot->chooseAction($state, [59, 105], 1);

        self::assertSame(59, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * A teammate's own Complacency doesn't count as a legal target
     * either (the same "an opponent" exclusion as
     * testChooseActionDoesNotTargetTheBotsOwnQualifyingMoodWhenPlayingContempt()
     * above) -- and since it already contributes to the bot's own
     * GROUP total (Open Team Play), the bot's group is already ahead of
     * player 3's empty board before Contempt is even played, so the
     * round-winning exception doesn't apply either. Contempt is
     * deprioritized behind Wrath exactly as if no one had any qualifying
     * mood in play at all.
     */
    public function testChooseActionDoesNotTargetATeammatesQualifyingMoodWhenPlayingContempt(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [59, 105], 2 => [5]],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );
        $state->moveHandToInPlay(2, 5);

        $action = $this->bot->chooseAction($state, [59, 105], 1);

        self::assertSame(105, $action['card_id']);
    }

    /**
     * With nothing else playable, Contempt is still played -- deprioritized
     * WHEN, never skipped outright, the same policy every other
     * sortPriorityValue() veto in this class already follows.
     */
    public function testChooseActionStillPlaysContemptWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [59]]);

        $action = $this->bot->chooseAction($state, [59], 1);

        self::assertSame(59, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    // -- Conviction (confirmed by the maintainer) -----------------------------

    /**
     * Player 2 has Doubt (id 36, value 2) in play and player 3 has
     * Complacency (id 5, value 4) -- convictionBestOpponentMoodId() picks
     * the HIGHEST across every non-teammate opponent (Complacency), not
     * just the first opponent's own mood it finds.
     */
    public function testChooseActionTargetsTheHighestValueOpponentsMoodWhenPlayingConviction(): void
    {
        $state = $this->boardState(hands: [1 => [6, 105], 2 => [36], 3 => [5]]);
        $state->moveHandToInPlay(2, 36);
        $state->moveHandToInPlay(3, 5);

        $action = $this->bot->chooseAction($state, [6, 105], 1);

        self::assertSame(6, $action['card_id']);
        self::assertSame(['target_mood_id' => 5], $action['choices']);
    }

    /**
     * The bot's own Complacency (id 5, value 4) is in play alongside
     * player 2's Doubt (id 36, value 2) -- ConvictionEffect's own field
     * has no owner restriction, so BotChoiceResolver's generic "any
     * scope, highest value" default would happily send the bot's OWN
     * higher-value mood to the bottom of the deck instead. Per the
     * maintainer, Conviction should always prefer AN OPPONENT's mood
     * over the acting player's own, even a lower-value one -- removing a
     * mood only denies its OWNER those round points, so targeting the
     * bot's own mood would cost the acting side points for nothing.
     */
    public function testChooseActionPrefersAnOpponentsLowerValueMoodOverTheBotsOwnHigherValueMoodWhenPlayingConviction(): void
    {
        $state = $this->boardState(hands: [1 => [6, 105, 5], 2 => [36]]);
        $state->moveHandToInPlay(1, 5);
        $state->moveHandToInPlay(2, 36);

        $action = $this->bot->chooseAction($state, [6, 105], 1);

        self::assertSame(6, $action['card_id']);
        self::assertSame(['target_mood_id' => 36], $action['choices']);
    }

    /**
     * A teammate's own Complacency doesn't count as "an opponent" either
     * (the same exclusion Contempt/Pacifism already apply) -- and since
     * it already contributes to the bot's own GROUP total (Open Team
     * Play), that group is already ahead of player 3's empty board
     * before Conviction is even played, so the round-winning exception
     * doesn't apply. Conviction is deprioritized behind Wrath exactly as
     * if no one had any mood in play at all.
     */
    public function testChooseActionDoesNotTargetATeammatesMoodWhenPlayingConviction(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [6, 105], 2 => [5]],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );
        $state->moveHandToInPlay(2, 5);

        $action = $this->bot->chooseAction($state, [6, 105], 1);

        self::assertSame(105, $action['card_id']);
    }

    /**
     * No opponent has any mood in play -- only the bot's own Charity (id
     * 3, value 1) is, giving Conviction a legal (but undesirable) target
     * -- and playing Conviction for its own plain value (2) wouldn't
     * decide the round (every score is at or above 0 already: the bot's
     * own is 1, both rivals' are 0). Deprioritized behind Wrath (id 105,
     * value 0, no ability), proving the veto actively suppresses
     * Conviction below even a LOWER-value alternative, not just
     * something that would already outrank it by plain baseValue().
     */
    public function testChooseActionDeprioritizesConvictionWithNoOpponentTargetAndNoRoundToWin(): void
    {
        $state = $this->boardState(hands: [1 => [6, 105, 3]]);
        $state->moveHandToInPlay(1, 3);

        $action = $this->bot->chooseAction($state, [6, 105], 1);

        self::assertSame(105, $action['card_id']);
    }

    /**
     * With nothing else playable, Conviction is still played --
     * deprioritized WHEN, never skipped outright, the same policy every
     * other sortPriorityValue() veto in this class already follows.
     * ConvictionEffect's own field is REQUIRED (unlike Hate/Contempt's
     * own optional "you may" fields), so convictionTargetMoodId() must
     * still supply a legal target even with no opponent to target --
     * here, the bot's own only other in-play mood (Charity, id 3).
     */
    public function testChooseActionStillPlaysConvictionWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [6, 3]]);
        $state->moveHandToInPlay(1, 3);

        $action = $this->bot->chooseAction($state, [6], 1);

        self::assertSame(6, $action['card_id']);
        self::assertSame(['target_mood_id' => 3], $action['choices']);
    }

    /**
     * No opponent has any mood in play, so convictionBestOpponentMoodId()
     * finds nothing -- but the bot's own Charity (id 3, base value 1) has
     * been pushed to -2 by an attached chaos effect (adjustChaosValueDelta(),
     * standing in for chaos_064 here without needing a full Chaos Draft
     * game state), putting the bot's own current round total at -2 while
     * both rivals sit at 0. Playing Conviction for its own plain value
     * (2) brings the bot's total to 0 -- tying/clearing both rivals, the
     * deciding difference between losing and winning the round -- so
     * Conviction is played anyway (outranking Wrath, id 105, value 0,
     * once the veto lifts), purely for its own value. With no opponent
     * target, convictionTargetMoodId() falls back to the LOWEST-value
     * other mood in play: the bot's OWN Hate (id 66, value 0) is also in
     * play, but Charity's own -2 is lower still, so Charity (id 3) is the
     * one sent to the bottom of the deck, not Hate.
     */
    public function testChooseActionPlaysConvictionForItsOwnValueWhenItWouldWinTheRound(): void
    {
        $state = $this->boardState(hands: [1 => [6, 105, 3, 66]]);
        $state->moveHandToInPlay(1, 3);
        $state->adjustChaosValueDelta(3, -3);
        $state->moveHandToInPlay(1, 66);

        $action = $this->bot->chooseAction($state, [6, 105], 1);

        self::assertSame(6, $action['card_id']);
        self::assertSame(['target_mood_id' => 3], $action['choices']);
    }

    // -- Hate (confirmed by the maintainer) -----------------------------------

    /**
     * Player 2's Complacency (id 5, value 4) is the only in-play mood
     * besides Hate itself -- hateTargetMoodId() targets it (the
     * highest-value NON-teammate opponent mood) rather than leaving Hate
     * (id 66, value 0) untargeted, or targeting Hate itself, since a real
     * opponent target is strictly better: the same card draw, plus
     * denying player 2 that scored value this round.
     */
    public function testChooseActionTargetsAnOpponentsMoodWhenPlayingHate(): void
    {
        $state = $this->boardState(hands: [1 => [66], 2 => [5]]);
        $state->moveHandToInPlay(2, 5);

        $action = $this->bot->chooseAction($state, [66], 1);

        self::assertSame(66, $action['card_id']);
        self::assertSame(['target_mood_id' => 5], $action['choices']);
    }

    /**
     * With no opponent mood in play at all, hateTargetMoodId() falls back
     * to Hate's own card id (66) -- HateEffect's own field has
     * `includes_self`, and Hate's printed value is 0, so bottoming it
     * costs nothing a plain, untargeted play wouldn't already have lost;
     * the card draw is pure upside. Confirms Hate is never left
     * deliberately untargeted just because no opponent target exists.
     */
    public function testChooseActionTargetsHateItselfWhenNoOpponentMoodExists(): void
    {
        $state = $this->boardState(hands: [1 => [66]]);

        $action = $this->bot->chooseAction($state, [66], 1);

        self::assertSame(66, $action['card_id']);
        self::assertSame(['target_mood_id' => 66], $action['choices']);
    }

    /**
     * A teammate's own Complacency doesn't count as a legal target
     * either (the same "an opponent" exclusion Contempt/Denial already
     * use above) -- with no true opponent in play, hateTargetMoodId()
     * falls back to Hate itself exactly as if no one had any mood in
     * play at all, rather than bottoming the teammate's own mood.
     */
    public function testChooseActionDoesNotTargetATeammatesMoodWhenPlayingHate(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [1 => [66], 2 => [5]],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );
        $state->moveHandToInPlay(2, 5);

        $action = $this->bot->chooseAction($state, [66], 1);

        self::assertSame(66, $action['card_id']);
        self::assertSame(['target_mood_id' => 66], $action['choices']);
    }

    /**
     * The bot already has Euphoria (id 117, "value increases by 1 for
     * each mood in play") in play -- bottoming ANY mood via Hate,
     * including Hate itself, would shrink the in-play mood count by one
     * and so cost Euphoria a permanent point of its own value, a real
     * ongoing loss a one-time random draw isn't worth. hateTargetMoodId()
     * returns null here even though player 2's Complacency (value 4)
     * would otherwise be a clearly-worthwhile target, so Hate is played
     * with no target at all (its own plain 0 value).
     */
    public function testChooseActionDoesNotTargetAnythingWithHateWhenEuphoriaIsInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [66, 117], 2 => [5]]);
        $state->moveHandToInPlay(1, 117);
        $state->moveHandToInPlay(2, 5);

        $action = $this->bot->chooseAction($state, [66], 1);

        self::assertSame(66, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    // -- Anger (confirmed by the maintainer) ---------------------------------

    /**
     * With no opponent moods in play, angerTargetMoodIds() (id 80) comes
     * back empty, so Anger is deprioritized behind Wrath (id 105) --
     * both are worth 0 printed value, so without the veto a stable sort
     * would keep Anger first (its own original position in
     * $playableCardIds); the veto is what pushes it below Wrath instead.
     */
    public function testChooseActionDeprioritizesAngerWithNoTargets(): void
    {
        $state = $this->boardState(hands: [1 => [80, 105]]);

        $action = $this->bot->chooseAction($state, [80, 105], 1);

        self::assertSame(105, $action['card_id']);
    }

    /**
     * Player 2 has Apathy (id 55, value 4) in play -- a legal Anger
     * target, so angerTargetMoodIds() is no longer empty and the veto
     * doesn't apply; Anger is played with that mood as its target.
     */
    public function testChooseActionPlaysAngerWithAnOpponentTarget(): void
    {
        $state = $this->boardState(hands: [1 => [80, 105], 2 => [55]]);
        $state->moveHandToInPlay(2, 55);

        $action = $this->bot->chooseAction($state, [80, 105], 1);

        self::assertSame(80, $action['card_id']);
        self::assertSame(['target_mood_ids' => [55]], $action['choices']);
    }

    /**
     * With nothing else playable, Anger is still played -- deprioritized
     * WHEN, never skipped outright, the same policy every other
     * sortPriorityValue() veto in this class already follows.
     */
    public function testChooseActionStillPlaysAngerWhenNothingElseIsPlayable(): void
    {
        $state = $this->boardState(hands: [1 => [80]]);

        $action = $this->bot->chooseAction($state, [80], 1);

        self::assertSame(80, $action['card_id']);
        self::assertSame(['target_mood_ids' => []], $action['choices']);
    }

    /**
     * Regression test (confirmed by the maintainer): SuperiorityEffect's
     * own "7 if you have more moods than each other player" value depends
     * on a mood-count comparison that playing Anger itself can tip. Here
     * player 1 (the bot) has 2 other moods in play (fewer than player 2's
     * 3, Superiority included), so Superiority sits at 7 -- over Anger's
     * own 5-point combined-value budget -- right up until Anger is
     * actually played and player 1's own count rises to 3, tying player
     * 2's and dropping Superiority back to its base value of 3 (within
     * budget). Before this fix, angerSwingMaximizingTargets() scored
     * Superiority using its stale pre-play value (7), so the bot would
     * never target it even though it's actually in budget the moment
     * Anger is played.
     */
    public function testChooseActionTargetsASuperiorityThatOnlyFitsAngersBudgetOnceAngerItselfIsInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [80, 2, 3], 2 => [77, 13, 14]]);
        $state->moveHandToInPlay(1, 2); // Benevolence, value 2
        $state->moveHandToInPlay(1, 3); // Charity, value 1
        $state->moveHandToInPlay(2, 77); // Superiority, base 3 / alt 7
        $state->moveHandToInPlay(2, 13); // Friendliness, value 2
        $state->moveHandToInPlay(2, 14); // Guilt, value 2

        $action = $this->bot->chooseAction($state, [80], 1);

        self::assertSame(80, $action['card_id']);
        self::assertContains(77, $action['choices']['target_mood_ids']);
    }

    public function testChooseDecisionAnswerReturnsEmptyForAnOptionalField(): void
    {
        $state = $this->boardState();
        $field = ['key' => 'duplicity_repeat', 'type' => 'bool', 'required' => false];

        self::assertSame([], $this->bot->chooseDecisionAnswer($state, $field, 1));
    }

    public function testChooseDecisionAnswerFillsARequiredHandCardField(): void
    {
        $state = $this->boardState(hands: [2 => [8, 55]]); // the bot itself is player 2 here, values 3 and 4

        $answer = $this->bot->chooseDecisionAnswer($state, ['key' => 'given_card_id', 'type' => 'hand_card', 'required' => true], 2);

        self::assertSame(['given_card_id' => 8], $answer);
    }

    // -- Disillusionment (confirmed by the maintainer) --------------------

    private function disillusionmentColorField(int $playerId): array
    {
        return [
            'key' => "chosen_color_{$playerId}",
            'type' => 'mode',
            'options' => ['white', 'blue', 'black', 'red', 'green'],
            'required' => false,
        ];
    }

    public function testChooseDecisionAnswerPicksTheFirstSafeColorForDisillusionment(): void
    {
        $state = $this->boardState(hands: [1 => [8]]); // Dignity, white
        $state->moveHandToInPlay(1, 8);

        $answer = $this->bot->chooseDecisionAnswer($state, $this->disillusionmentColorField(1), 1, 'disillusionment_choose_color');

        // white is unsafe (the bot's own mood) -- blue is next in options order
        self::assertSame(['chosen_color_1' => 'blue'], $answer);
    }

    public function testChooseDecisionAnswerIgnoresAnOpponentsColorForDisillusionment(): void
    {
        $state = $this->boardState(hands: [1 => [8], 2 => [27]]); // bot: Dignity (white), opponent: Ambivalence (blue)
        $state->moveHandToInPlay(1, 8);
        $state->moveHandToInPlay(2, 27);

        $answer = $this->bot->chooseDecisionAnswer($state, $this->disillusionmentColorField(1), 1, 'disillusionment_choose_color');

        // the opponent's blue mood is no reason to avoid blue -- only the
        // bot's own white mood is unsafe here
        self::assertSame(['chosen_color_1' => 'blue'], $answer);
    }

    public function testChooseDecisionAnswerAvoidsATeammatesColorForDisillusionment(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            hands: [2 => [8]], // teammate's Dignity, white
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1],
        );
        $state->moveHandToInPlay(2, 8);

        $answer = $this->bot->chooseDecisionAnswer($state, $this->disillusionmentColorField(1), 1, 'disillusionment_choose_color');

        // white is unsafe (a teammate's mood, even though the bot itself
        // has none) -- blue is next in options order
        self::assertSame(['chosen_color_1' => 'blue'], $answer);
    }

    public function testChooseDecisionAnswerDeclinesDisillusionmentWhenEveryColorIsUnsafe(): void
    {
        $state = $this->boardState(hands: [1 => [8, 27, 55, 80, 107]]); // one mood of each color, all the bot's own
        foreach ([8, 27, 55, 80, 107] as $cardId) {
            $state->moveHandToInPlay(1, $cardId);
        }

        $answer = $this->bot->chooseDecisionAnswer($state, $this->disillusionmentColorField(1), 1, 'disillusionment_choose_color');

        self::assertSame([], $answer);
    }

    // -- Creativity (confirmed by the maintainer) --------------------------

    public function testChooseActionCopiesTheHighestValueMoodInPlayWithCreativity(): void
    {
        $state = $this->boardState(hands: [1 => [32], 2 => [55], 3 => [30]]); // Apathy (value 4), Bashfulness (value 6)
        $state->moveHandToInPlay(2, 55);
        $state->moveHandToInPlay(3, 30);

        $action = $this->bot->chooseAction($state, [32], 1);

        self::assertSame(32, $action['card_id']);
        self::assertSame(['copy_card_id' => 30], $action['choices']);
    }

    /**
     * Copying your own board is just as legitimate as copying an
     * opponent's -- Creativity's own CardChoiceSchema field has no
     * `excludes_teammate`/opponent-only restriction the way Intimidation/
     * Paranoia/Pacifism's own fields do, so the bot's own Bashfulness
     * (value 6) beats the opponent's own Apathy (value 4).
     */
    public function testChooseActionWillCopyItsOwnMoodWhenItsTheHighestValue(): void
    {
        $state = $this->boardState(hands: [1 => [32, 30], 2 => [55]]);
        $state->moveHandToInPlay(1, 30);
        $state->moveHandToInPlay(2, 55);

        $action = $this->bot->chooseAction($state, [32], 1);

        self::assertSame(32, $action['card_id']);
        self::assertSame(['copy_card_id' => 30], $action['choices']);
    }

    /**
     * Self-Loathing (id 75, value 6) has its own "to play" cost --
     * copying it risks turning a legal Creativity play into an illegal
     * one if the bot can't actually pay that cost, so it's skipped in
     * favor of the next-best SAFE candidate, Apathy (value 4), even
     * though Self-Loathing is nominally worth more.
     */
    public function testChooseActionSkipsAToPlayCostCardWhenCopyingWithCreativity(): void
    {
        $state = $this->boardState(hands: [1 => [32], 2 => [55], 3 => [75]]);
        $state->moveHandToInPlay(2, 55);
        $state->moveHandToInPlay(3, 75);

        $action = $this->bot->chooseAction($state, [32], 1);

        self::assertSame(32, $action['card_id']);
        self::assertSame(['copy_card_id' => 55], $action['choices']);
    }

    public function testChooseActionPlaysCreativityUnfilledWhenNothingIsInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [32]]);

        $action = $this->bot->chooseAction($state, [32], 1);

        self::assertSame(32, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    // -- Team Play (issue #360) ------------------------------------------

    public function testChooseTeamDecisionProposalAlwaysPicksTheFirstCandidate(): void
    {
        self::assertSame(5, $this->bot->chooseTeamDecisionProposal([5, 9]));
        // Order alone decides it -- not which one is "the bot itself" or
        // any other property of either id (see the method's own docblock:
        // deliberately arbitrary and deterministic).
        self::assertSame(9, $this->bot->chooseTeamDecisionProposal([9, 5]));
    }

    public function testChooseInitialCardPassPicksTheTwoLowestValueHandCards(): void
    {
        // Charity (value 1), Benevolence (value 2), Chivalry (value 3),
        // Complacency (value 4) -- deliberately out of value order in the
        // hand itself, to prove this sorts rather than just taking the
        // first two dealt.
        $state = $this->boardState(hands: [1 => [5, 4, 3, 2]]);

        self::assertSame([3, 2], $this->bot->chooseInitialCardPass($state, 1));
    }

    // -- shouldAttemptValueBoostDiscard() (Dignity/Embarrassment/Cheer/Delight) --

    /**
     * With only 1 other card in hand, discarding is still always attempted
     * when it's decisive -- here the bot has nothing in play (score 0) and
     * an opponent has a value-4 mood in play, so Delight's own unboosted
     * value (3) alone isn't enough to have the highest score in the game
     * (3 < 4), but its boosted value (5) is (5 >= 4).
     */
    public function testChooseActionAlwaysDiscardsToDelightWithOneSpareCardWhenItWouldGiveTheHighestScore(): void
    {
        $state = $this->boardState(hands: [1 => [111, 3], 2 => [5]]); // Delight + Charity (bot); Complacency, value 4 (opponent)
        $state->moveHandToInPlay(2, 5);

        $action = $this->bot->chooseAction($state, [111], 1);

        self::assertSame(111, $action['card_id']);
        self::assertSame(['discard_card_id' => 3], $action['choices']);
    }

    /**
     * The mirror case: an opponent's combined in-play value (12, from two
     * value-6 moods) is out of reach even WITH the boost (5 < 12), so
     * discarding wouldn't be decisive -- with only 1 spare card, the bot
     * never volunteers for a discard that can't win it.
     */
    public function testChooseActionSkipsDelightsDiscardWithOneSpareCardWhenTheBoostWouldStillNotBeEnough(): void
    {
        $state = $this->boardState(hands: [1 => [111, 3], 2 => [9, 56]]); // Delight + Charity (bot); Discipline + Betrayal, value 6 each (opponent)
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(2, 56);

        $action = $this->bot->chooseAction($state, [111], 1);

        self::assertSame(111, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * The other way discarding can be non-decisive: the bot is already
     * ahead without it (its own Discipline, value 6, already beats every
     * opponent's own 0) -- again, with only 1 spare card, no discard.
     */
    public function testChooseActionSkipsDelightsDiscardWithOneSpareCardWhenAlreadyAhead(): void
    {
        $state = $this->boardState(hands: [1 => [111, 3, 9]]); // Delight + Charity + Discipline (already in play below)
        $state->moveHandToInPlay(1, 9);

        $action = $this->bot->chooseAction($state, [111], 1);

        self::assertSame(111, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * With 4+ other cards in hand, the bot always discards regardless of
     * whether it's decisive -- here the bot is already ahead (0 >= 0, no
     * opponent has anything in play), the same non-decisive shape as
     * testChooseActionSkipsDelightsDiscardWithOneSpareCardWhenAlreadyAhead()
     * above, but with 4 spare cards instead of 1.
     */
    public function testChooseActionAlwaysDiscardsToDelightWithFourOrMoreSpareCards(): void
    {
        $state = $this->boardState(hands: [1 => [111, 3, 55, 91, 107]]); // Delight + Charity (only eligible discard) + 3 non-eligible fillers

        $action = $this->bot->chooseAction($state, [111], 1);

        self::assertSame(111, $action['card_id']);
        self::assertSame(['discard_card_id' => 3], $action['choices']);
    }

    /**
     * Between 1 and 4+ spare cards, the decision is a probability roll
     * scaling linearly with spare hand size: (otherCards - 1) / 3, i.e.
     * 1/3 at 2 spare cards and 2/3 at 3 spare cards. Both scenarios below
     * are deliberately non-decisive (the bot is already ahead, same as
     * testChooseActionSkipsDelightsDiscardWithOneSpareCardWhenAlreadyAhead()),
     * so every discard observed here is coming purely from the
     * probability roll, not the "would win" override. Run many trials and
     * check the observed rate falls in a generous band around the
     * expected one -- wide enough to avoid flakiness, but tight enough to
     * catch the formula being wrong (e.g. flipped, or ignoring hand size
     * entirely) -- plus that discarding is strictly more likely at 3
     * spare cards than at 2.
     */
    public function testShouldAttemptValueBoostDiscardScalesProbabilityWithSpareHandSize(): void
    {
        $trials = 300;

        $twoSpareState = $this->boardState(hands: [1 => [111, 3, 55]]); // 2 spare cards (Charity eligible, Apathy not)
        $twoSpareDiscards = 0;
        for ($i = 0; $i < $trials; $i++) {
            if (($this->bot->chooseAction($twoSpareState, [111], 1)['choices'] ?? []) !== []) {
                $twoSpareDiscards++;
            }
        }

        $threeSpareState = $this->boardState(hands: [1 => [111, 3, 55, 107]]); // 3 spare cards
        $threeSpareDiscards = 0;
        for ($i = 0; $i < $trials; $i++) {
            if (($this->bot->chooseAction($threeSpareState, [111], 1)['choices'] ?? []) !== []) {
                $threeSpareDiscards++;
            }
        }

        // Expected ~33% (100/300) and ~67% (200/300) respectively.
        self::assertGreaterThan(45, $twoSpareDiscards, '2 spare cards: observed rate implausibly far below the expected ~33%');
        self::assertLessThan(165, $twoSpareDiscards, '2 spare cards: observed rate implausibly far above the expected ~33%');
        self::assertGreaterThan(135, $threeSpareDiscards, '3 spare cards: observed rate implausibly far below the expected ~67%');
        self::assertLessThan(255, $threeSpareDiscards, '3 spare cards: observed rate implausibly far above the expected ~67%');
        self::assertGreaterThan($twoSpareDiscards, $threeSpareDiscards, '3 spare cards should discard strictly more often than 2');
    }

    /**
     * Open/Closed Team Play (issue #360): "the highest score in the game"
     * is the bot's own group total (itself plus its teammate), not just
     * its own individual score -- here the bot alone (score 0) plus
     * Delight's own boosted value (5) still wouldn't reach the rival
     * team's combined 6 (from a single value-6 mood), but the bot's
     * teammate already has a value-2 mood in play, and 2 + 5 = 7 >= 6.
     * With only 1 spare card, this should still always discard -- proving
     * the teammate's own score was actually included, since 0 + 5 = 5 < 6
     * alone would NOT have been decisive.
     */
    public function testChooseActionCountsTheTeammatesScoreTowardTheHighestScoreCheck(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3, 4],
            hands: [1 => [111, 3], 2 => [2], 3 => [9], 4 => []],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1, 4 => 1],
        );
        $state->moveHandToInPlay(2, 2); // teammate's Benevolence, value 2
        $state->moveHandToInPlay(3, 9); // rival's Discipline, value 6

        $action = $this->bot->chooseAction($state, [111], 1);

        self::assertSame(111, $action['card_id']);
        self::assertSame(['discard_card_id' => 3], $action['choices']);
    }

    // -- Draft pick scoring (issue #359) -------------------------------------

    /**
     * A minimal, synthetic draft-scoring dataset -- none of these ids need
     * to correspond to real catalog cards (chooseDraftCards()/
     * chooseWinstonAction()/chooseGridLine()/chooseDraftDeck() only ever
     * read what's passed in here, never the database), so each test below
     * just picks whichever ids/scores make its own point clearest.
     *
     * @param array<int, int> $scoresById card id => draft_priority_score
     * @param array<int, int[]> $synergyPartnersByMythicId
     * @param array<int, array{times_in_deck: int, deck_win_rate: ?float}> $deckWinRatesByCardId
     * @return array{rowsById: array<int, array{draftPriorityScore: int}>, synergyPartnersByMythicId: array<int, int[]>, deckWinRatesByCardId: array<int, array{times_in_deck: int, deck_win_rate: ?float}>}
     */
    private function draftScoringData(array $scoresById, array $synergyPartnersByMythicId = [], array $deckWinRatesByCardId = []): array
    {
        return [
            'rowsById' => array_map(static fn (int $score): array => ['draftPriorityScore' => $score], $scoresById),
            'synergyPartnersByMythicId' => $synergyPartnersByMythicId,
            'deckWinRatesByCardId' => $deckWinRatesByCardId,
        ];
    }

    public function testChooseDraftCardsPicksTheHighestScoredCandidates(): void
    {
        $data = $this->draftScoringData([101 => 4, 102 => 40, 103 => 1, 104 => 16]);

        $picks = $this->bot->chooseDraftCards([101, 102, 103, 104], 2, draftedCardIds: [], draftScoringData: $data);

        self::assertSame([102, 104], $picks);
    }

    /**
     * Card 201 (draft_priority_score 4) is a curated partner of mythic
     * 999, already drafted -- SYNERGY_PARTNER_BONUS (40) pushes its
     * effective score to 44, comfortably ahead of card 202's own higher
     * standalone score (20), which has no synergy with anything drafted.
     * The unrelated mythic 998 (also drafted) never enters into it --
     * only 999's own partner list includes 201.
     */
    public function testChooseDraftCardsPrioritizesASynergyPartnerOfAnAlreadyDraftedMythic(): void
    {
        $data = $this->draftScoringData(
            scoresById: [201 => 4, 202 => 20, 998 => 40, 999 => 16],
            synergyPartnersByMythicId: [999 => [201, 555], 998 => [777]],
        );

        $picks = $this->bot->chooseDraftCards([201, 202], 1, draftedCardIds: [998, 999], draftScoringData: $data);

        self::assertSame([201], $picks);
    }

    /**
     * Card 302's own recorded 100% deck win rate (10 games, right at
     * MIN_DECK_STATS_SAMPLE) only ever breaks a tie -- it and card 301
     * share the same draft_priority_score (8), so the win-rate nudge is
     * what separates them; it could never have been enough on its own to
     * overtake a genuinely higher-ranked card (DECK_WIN_RATE_WEIGHT is
     * kept well under the smallest gap between two distinct score tiers).
     */
    public function testChooseDraftCardsBreaksATieUsingDeckWinRateOnceSampleSizeIsMet(): void
    {
        $data = $this->draftScoringData(
            scoresById: [301 => 8, 302 => 8],
            deckWinRatesByCardId: [302 => ['times_in_deck' => 10, 'deck_win_rate' => 1.0]],
        );

        $picks = $this->bot->chooseDraftCards([301, 302], 1, draftedCardIds: [], draftScoringData: $data);

        self::assertSame([302], $picks);
    }

    /** Below MIN_DECK_STATS_SAMPLE (10), an even 100% win rate is ignored entirely -- too small a sample to trust, so the tie stays a tie (first candidate wins, same as no stats data at all). */
    public function testChooseDraftCardsIgnoresDeckWinRateBelowTheMinimumSampleSize(): void
    {
        $data = $this->draftScoringData(
            scoresById: [401 => 8, 402 => 8],
            deckWinRatesByCardId: [402 => ['times_in_deck' => 9, 'deck_win_rate' => 1.0]],
        );

        $picks = $this->bot->chooseDraftCards([401, 402], 1, draftedCardIds: [], draftScoringData: $data);

        self::assertSame([401], $picks);
    }

    public function testChooseWinstonActionTakesAPileScoringAboveTheCatalogAverage(): void
    {
        // Catalog average is (1 + 1 + 40) / 3 ≈ 14 -- the pile's own best
        // card (40) clears it easily.
        $data = $this->draftScoringData([501 => 1, 502 => 1, 503 => 40]);

        self::assertSame('take', $this->bot->chooseWinstonAction([503], draftedCardIds: [], draftScoringData: $data));
    }

    public function testChooseWinstonActionPassesAPileScoringBelowTheCatalogAverage(): void
    {
        $data = $this->draftScoringData([501 => 1, 502 => 1, 503 => 40]);

        self::assertSame('pass', $this->bot->chooseWinstonAction([501], draftedCardIds: [], draftScoringData: $data));
    }

    public function testChooseGridLinePicksTheLineWithTheHighestTotalScore(): void
    {
        $data = $this->draftScoringData([601 => 4, 602 => 4, 603 => 1, 604 => 1, 605 => 1]);
        $lines = [
            ['axis' => 'row', 'index' => 0, 'cardIds' => [601, 602]], // total 8
            ['axis' => 'column', 'index' => 0, 'cardIds' => [603, 604, 605]], // total 3, more cards but lower value
        ];

        $line = $this->bot->chooseGridLine($lines, draftedCardIds: [], draftScoringData: $data);

        self::assertSame(['axis' => 'row', 'index' => 0], $line);
    }

    public function testChooseDraftDeckKeepsExactlyTheMinDeckSizeHighestScoredCards(): void
    {
        $data = $this->draftScoringData([701 => 1, 702 => 40, 703 => 2, 704 => 24, 705 => 1]);

        $deck = $this->bot->chooseDraftDeck([701, 702, 703, 704, 705], minDeckSize: 3, draftScoringData: $data);

        self::assertCount(3, $deck);
        self::assertSame([702, 704, 703], $deck);
    }

    /** A drafted pool already smaller than minDeckSize is returned whole -- array_slice()'s own natural behavior, never padded or rejected here (submitDraftDeck() itself is what actually enforces the real floor). */
    public function testChooseDraftDeckReturnsEverythingWhenBelowMinDeckSize(): void
    {
        $data = $this->draftScoringData([801 => 4, 802 => 1]);

        $deck = $this->bot->chooseDraftDeck([801, 802], minDeckSize: 12, draftScoringData: $data);

        self::assertSame([801, 802], $deck);
    }

    // -- Rationalization -------------------------------------------------

    /** Card 49 = Rationalization (base value 3, blue, rare). */
    public function testChooseActionRefreshesWhenRationalizationIsTheOnlyCardInHand(): void
    {
        $state = $this->boardState(hands: [1 => [49]]);

        $action = $this->bot->chooseAction($state, [49], 1);

        self::assertSame(49, $action['card_id']);
        self::assertSame(['mode' => 'refresh'], $action['choices']);
    }

    /** Fear (38) and Fickleness (39) are both base value 0 -- a remaining hand this weak (average 0) is always worth refreshing over. */
    public function testChooseActionRefreshesWhenTheRemainingHandIsWeak(): void
    {
        $state = $this->boardState(hands: [1 => [49, 38, 39]]);

        $action = $this->bot->chooseAction($state, [49], 1);

        self::assertSame(49, $action['card_id']);
        self::assertSame(['mode' => 'refresh'], $action['choices']);
    }

    /**
     * Chivalry (4, base value 3) keeps the remaining hand's own average
     * (3) above RATIONALIZATION_LOW_VALUE_HAND_AVERAGE, and with no other
     * seated player holding any cards at all there's no overstuffed
     * neighbor to steal from either -- neither trigger applies, so
     * Rationalization (also value 3) is deliberately passed over in favor
     * of Chivalry despite the tie on printed value, proving
     * sortPriorityValue()'s own "save it to play last" demotion actually
     * changes which card gets chosen, not just which mode it uses once
     * played.
     */
    public function testChooseActionSavesRationalizationForLastWhenNeitherTriggerApplies(): void
    {
        $state = $this->boardState(hands: [1 => [49, 4]]);

        $action = $this->bot->chooseAction($state, [49, 4], 1);

        self::assertSame(4, $action['card_id']);
    }

    /**
     * Still played once it's the only legal candidate left, even with
     * neither trigger active -- the demotion only ever changes ORDER,
     * never whether Rationalization is worth playing at all. Declines
     * BOTH modes rather than defaulting to 'refresh' here (reported
     * live: "playing it to refresh hands when they have a good hand") --
     * Chivalry (id 4, value 3) keeps the remaining hand comfortably
     * above RATIONALIZATION_LOW_VALUE_HAND_AVERAGE, so gambling it away
     * on a random redraw would be a pure downside, not a free action.
     */
    public function testChooseActionStillPlaysRationalizationAloneEvenWithoutATrigger(): void
    {
        $state = $this->boardState(hands: [1 => [49, 4]]);

        $action = $this->bot->chooseAction($state, [49], 1);

        self::assertSame(49, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Player order is [1, 2, 3] (boardState()'s own fixed seating), so
     * player 2 sits at the bot's (1) own LEFT and player 3 at its RIGHT
     * (activeNeighbor()'s "left is index+1" rule). Player 2 here holds 5
     * cards against the bot's own 2 (Rationalization + Chivalry) --
     * RATIONALIZATION_STEAL_HAND_SIZE_ADVANTAGE (3) worth of edge --
     * which 'rotate' toward 'right' is what actually routes player 2's
     * own hand onto the bot (see rationalizationStealDirection()'s own
     * docblock for why the direction is the OPPOSITE side from where the
     * giving neighbor sits). This also proves Rationalization gets
     * PRIORITIZED (chosen over Chivalry, which alone would otherwise tie
     * it on printed value) once a trigger is actually live, not just
     * "eventually played last".
     */
    public function testChooseActionRotatesTowardAnOverstuffedLeftHandNeighbor(): void
    {
        $state = $this->boardState(hands: [
            1 => [49, 4],
            2 => [38, 39, 20, 7, 3],
        ]);

        $action = $this->bot->chooseAction($state, [49, 4], 1);

        self::assertSame(49, $action['card_id']);
        self::assertSame(['mode' => 'rotate', 'direction' => 'right'], $action['choices']);
    }

    /** Mirror of the left-neighbor case above -- player 3 (the bot's own RIGHT neighbor) overstuffed instead, resolved via 'left'. */
    public function testChooseActionRotatesTowardAnOverstuffedRightHandNeighbor(): void
    {
        $state = $this->boardState(hands: [
            1 => [49, 4],
            3 => [38, 39, 20, 7, 3],
        ]);

        $action = $this->bot->chooseAction($state, [49, 4], 1);

        self::assertSame(49, $action['card_id']);
        self::assertSame(['mode' => 'rotate', 'direction' => 'left'], $action['choices']);
    }

    /** When both neighbors qualify, the direction that routes the LARGER of the two hands onto the bot wins. */
    public function testChooseActionPrefersTheLargerOverstuffedNeighborWhenBothQualify(): void
    {
        $state = $this->boardState(hands: [
            1 => [49, 4],
            2 => [38, 39, 20, 7, 3], // 5 cards -- qualifies, would resolve via 'right'
            3 => [38, 39, 20, 7, 3, 8, 9], // 7 cards -- qualifies too, and bigger; resolves via 'left'
        ]);

        $action = $this->bot->chooseAction($state, [49, 4], 1);

        self::assertSame(49, $action['card_id']);
        self::assertSame(['mode' => 'rotate', 'direction' => 'left'], $action['choices']);
    }

    /**
     * Reported live: with the bot at 2 cards (Rationalization plus
     * Courage, id 7/value 1 -- a remaining-hand average of 1, well under
     * RATIONALIZATION_LOW_VALUE_HAND_AVERAGE) and an opponent holding 6
     * cards, the bot rotated to REFRESH its own single leftover card
     * instead of stealing the opponent's much larger hand.
     * rationalizationLowValueHand() and rationalizationStealDirection()
     * both fire here -- 'rotate' must win: gaining several more cards
     * outright is always better than gambling a random redraw of one
     * card, so a live steal opportunity takes priority over a merely
     * weak remaining hand.
     */
    public function testChooseActionPrefersStealingAnOverstuffedHandOverRefreshingAWeakOne(): void
    {
        $state = $this->boardState(hands: [
            1 => [49, 7],
            2 => [38, 39, 20, 3, 8, 9], // 6 cards -- well past the steal threshold
        ]);

        $action = $this->bot->chooseAction($state, [49], 1);

        self::assertSame(49, $action['card_id']);
        self::assertSame(['mode' => 'rotate', 'direction' => 'right'], $action['choices']);
    }

    /** Exactly RATIONALIZATION_STEAL_HAND_SIZE_ADVANTAGE (3) more cards is enough to qualify -- 2 more is not. */
    public function testChooseActionDoesNotRotateForAnUnderstuffedNeighbor(): void
    {
        $state = $this->boardState(hands: [
            1 => [49, 4],
            2 => [38, 39, 20, 7], // only 2 more than the bot's own 2 -- short of the 3-card threshold
        ]);

        $action = $this->bot->chooseAction($state, [49, 4], 1);

        self::assertSame(4, $action['card_id'], 'no trigger applies, so Rationalization should still be saved for last');
    }

    /**
     * Reported live: "Rationalization should be saved... it should not
     * be played for points to win a round unless it's going to win the
     * entire game." Neither existing trigger applies here (Discipline id
     * 9/value 6 and Courage id 7/value 1 keep the remaining-hand average
     * above RATIONALIZATION_LOW_VALUE_HAND_AVERAGE, and no other player
     * has an oversized hand to steal), so WITHOUT the game-win context
     * Rationalization is still saved for last behind Courage -- this
     * first test just re-confirms that baseline (passing no fourth
     * argument at all, same as every test above).
     */
    public function testChooseActionSavesRationalizationForLastWithoutGameWinContext(): void
    {
        $state = $this->boardState(hands: [1 => [49, 9, 7], 2 => [78]]); // player 2's own Suspicion (value 3, no alt value/no while-in-play wrinkle)
        $state->moveHandToInPlay(2, 78);

        $action = $this->bot->chooseAction($state, [49, 7], 1);

        self::assertSame(7, $action['card_id']);
    }

    /**
     * Same board as above, but now told winning the round in progress
     * would win the whole GAME ($roundWinsNeededToWinGame: 1) -- and
     * playing Rationalization purely for its own value (3) is exactly
     * enough to overtake player 2's own current total (3, all from their
     * own in-play Suspicion) per wouldBecomeHighestScore(). Both
     * conditions hold, so Rationalization is played for points instead
     * of being saved -- declining both modes (the remaining hand isn't
     * weak and there's no steal opportunity, so there's nothing to gain
     * from refreshing or rotating; the game's about to be won on points
     * alone), just for a different reason than either existing trigger.
     */
    public function testChooseActionPlaysRationalizationForPointsWhenItWouldWinTheGame(): void
    {
        $state = $this->boardState(hands: [1 => [49, 9, 7], 2 => [78]]);
        $state->moveHandToInPlay(2, 78);

        $action = $this->bot->chooseAction($state, [49, 7], 1, 1);

        self::assertSame(49, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Identical board/win-margin as the "wins the game" test above, but
     * $roundWinsNeededToWinGame is 2 -- winning THIS round wouldn't be
     * enough on its own, so the carve-out doesn't apply and
     * Rationalization is saved for last as usual.
     */
    public function testChooseActionDoesNotPlayRationalizationForPointsWhenGameWinIsNotClose(): void
    {
        $state = $this->boardState(hands: [1 => [49, 9, 7], 2 => [78]]);
        $state->moveHandToInPlay(2, 78);

        $action = $this->bot->chooseAction($state, [49, 7], 1, 2);

        self::assertSame(7, $action['card_id']);
    }

    /**
     * $roundWinsNeededToWinGame is 1 again (winning this round WOULD win
     * the game), but player 2's own in-play total (Discipline, id 9,
     * value 6 -- moved into play instead of Suspicion) is too far ahead
     * for Rationalization's own value (3) to close: 0 + 3 = 3 is still
     * short of 6, so wouldBecomeHighestScore() says no. Being close to
     * winning the game isn't enough by itself -- the round has to be
     * winnable too.
     */
    public function testChooseActionDoesNotPlayRationalizationForPointsWhenItWouldNotTakeTheRoundLead(): void
    {
        // Confusion (31, value 4) is purely a remaining-hand filler here,
        // keeping the average above RATIONALIZATION_LOW_VALUE_HAND_AVERAGE
        // so the EXISTING low-value-hand trigger doesn't also fire and
        // muddy what this test is actually checking.
        $state = $this->boardState(hands: [1 => [49, 7, 31], 2 => [9]]);
        $state->moveHandToInPlay(2, 9);

        $action = $this->bot->chooseAction($state, [49, 7], 1, 1);

        self::assertSame(7, $action['card_id']);
    }

    /**
     * Corruption's own live "awardsExtraWin" marker (GameService::
     * hasExtraWinMarker()'s own tag, duplicated here per
     * rationalizationWouldClinchTheGame()'s own docblock) means the
     * round's winner wins TWO rounds at once -- so $roundWinsNeededToWinGame
     * of 2 (which the earlier "not close" test above shows is normally
     * NOT enough) is enough here, since 2 round-wins is exactly what this
     * round is worth with Corruption's marker active.
     */
    public function testChooseActionPlaysRationalizationForPointsWithCorruptionsDoubleWinMarker(): void
    {
        $state = $this->boardState(hands: [1 => [49, 9, 7, 60], 2 => [78]]);
        $state->moveHandToInPlay(2, 78);
        $state->moveHandToInPlay(1, 60);
        $state->setEffectState(60, 'awardsExtraWin', true);

        $action = $this->bot->chooseAction($state, [49, 7], 1, 2);

        self::assertSame(49, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * A bug caught live (issue #196): Compulsion's own required
     * `target_player_id` field (CardChoiceSchema's `'compulsion'` entry,
     * scope 'other') has no special-cased choice-building of its own --
     * it falls straight through to the generic per-field
     * `resolveSchemaFields()` loop, same as every other required
     * single-player-target field. In Open Team Play specifically, that
     * field's own generic 'other' scope permits targeting ANY other
     * seated player, teammate included (unlike Duplicity's own
     * `excludes_teammate` flag on an otherwise-identical field shape) --
     * this is the one test confirming that resolution actually completes
     * with a real `target_player_id` rather than an incomplete choices
     * array in a 4-player team game, since no test previously exercised
     * Compulsion's own bot targeting at all.
     */
    public function testChooseActionTargetsAPlayerWhenPlayingCompulsionInTeamPlay(): void
    {
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3, 4],
            hands: [1 => [], 2 => [], 3 => [86], 4 => []],
            teamIdByPlayer: [1 => 0, 2 => 0, 3 => 1, 4 => 1],
        );

        $action = $this->bot->chooseAction($state, [86], 3);

        self::assertNotNull($action);
        self::assertArrayHasKey('target_player_id', $action['choices']);
        self::assertNotSame(3, $action['choices']['target_player_id'], 'Compulsion must target another player, never the acting player itself');
    }

    // -- Rejection ---------------------------------------------------------

    /**
     * Reported live: "Bots shouldn't play Rejection with no targets."
     * Rejection (73, value 0) and Creativity (32, value 0, no target in
     * play to copy) tie on printed value with neither having any real
     * ability to show for it here -- without the veto they'd tie in
     * sortPriorityValue() too, but Rejection's own hold-back demotes it
     * to PHP_INT_MIN, so Creativity wins outright.
     */
    public function testChooseActionDemotesRejectionBehindATiedValueCardWhenNeitherTriggerApplies(): void
    {
        $state = $this->boardState(hands: [1 => [73, 32]]);

        $action = $this->bot->chooseAction($state, [73, 32], 1);

        self::assertSame(32, $action['card_id']);
    }

    /**
     * Still played once it's the only legal candidate left, even with
     * neither trigger active -- the demotion only ever changes ORDER,
     * never whether Rejection is worth playing at all. Declines the
     * target field entirely rather than forcing a pair, matching
     * RejectionEffect's own `if ($targets === []) { return; }` no-op.
     */
    public function testChooseActionStillPlaysRejectionAloneWithNoTargetsWhenNothingQualifies(): void
    {
        $state = $this->boardState(hands: [1 => [73]]);

        $action = $this->bot->chooseAction($state, [73], 1);

        self::assertSame(73, $action['card_id']);
        self::assertSame([], $action['choices']);
    }

    /**
     * Contempt (59, black, value 1) and Cruelty (61, black, value 3),
     * both owned by the non-teammate opponent (player 2), share a color
     * -- removing both drops the opponent's own total from 4 to 0,
     * exactly matching the bot's own total (0, Rejection's printed value
     * is always 0) -- a round-winning pair, so it's chosen over the
     * merely-significant-swing tier even though the combined value (4)
     * would also clear that bar on its own.
     */
    public function testChooseActionTargetsAWinningPairForRejection(): void
    {
        $state = $this->boardState(hands: [1 => [73], 2 => [59, 61]]);
        $state->moveHandToInPlay(2, 59);
        $state->moveHandToInPlay(2, 61);

        $action = $this->bot->chooseAction($state, [73], 1);

        self::assertSame(73, $action['card_id']);
        self::assertSame(['target_mood_ids' => [59, 61]], $action['choices']);
    }

    /**
     * Same qualifying pair as above (Contempt/Cruelty, combined 4,
     * clearing REJECTION_SIGNIFICANT_SWING_THRESHOLD), but the opponent
     * also holds Sneakiness (51, blue, value 5, sharing neither color nor
     * value with the pair) -- removing just the pair leaves the opponent
     * at 5, still ahead of the bot's own 0, so this ISN'T a round-winning
     * removal. Targeted anyway for the genuine swing it costs the
     * opponent.
     */
    public function testChooseActionTargetsASignificantSwingPairForRejectionWithoutWinningOutright(): void
    {
        $state = $this->boardState(hands: [1 => [73], 2 => [59, 61, 51]]);
        $state->moveHandToInPlay(2, 59);
        $state->moveHandToInPlay(2, 61);
        $state->moveHandToInPlay(2, 51);

        $action = $this->bot->chooseAction($state, [73], 1);

        self::assertSame(73, $action['card_id']);
        self::assertSame(['target_mood_ids' => [59, 61]], $action['choices']);
    }

    /**
     * Reported live exception: "unless it being in play will pump
     * another mood enough to win a round." Euphoria (117, "+1 per mood
     * in play, any owner") counts EVERY mood in play regardless of
     * owner, so with the bot's own Euphoria plus the opponent's
     * Suspicion (78, value 3, no alt value/no while-in-play wrinkle)
     * already in play (2 moods), Euphoria sits at value 2 -- behind the
     * opponent's own total of 3. Playing Rejection (with no target at
     * all; the opponent has only ONE mood, so no pair exists to remove
     * anyway) raises the in-play count to 3, bumping Euphoria to value 3
     * and tying the opponent's own total. hasGoodReasonToPlayNow() is
     * asserted directly (rather than via chooseAction()'s own sort
     * order) since Rejection's printed value is always 0 --
     * sortPriorityValue() alone could never distinguish "vetoed" from
     * "allowed but still worth 0" against any genuinely positive-value
     * alternative.
     */
    public function testRejectionHasAGoodReasonToPlayNowWhenTheGuaranteedPumpWinsTheRound(): void
    {
        $state = $this->boardState(hands: [1 => [73, 117], 2 => [78]]);
        $state->moveHandToInPlay(1, 117);
        $state->moveHandToInPlay(2, 78);

        self::assertTrue($this->bot->hasGoodReasonToPlayNow($state, 73, 1, [73]));
    }

    /**
     * Same Euphoria pump as above, but the opponent's Bashfulness (30,
     * value 6) is too far ahead for one extra point of Euphoria to close
     * -- the pump happens, but doesn't flip the round, so there's still
     * no good reason to play Rejection right now.
     */
    public function testRejectionHasNoGoodReasonToPlayNowWhenThePumpIsNotEnoughToWinTheRound(): void
    {
        $state = $this->boardState(hands: [1 => [73, 117], 2 => [30]]);
        $state->moveHandToInPlay(1, 117);
        $state->moveHandToInPlay(2, 30);

        self::assertFalse($this->bot->hasGoodReasonToPlayNow($state, 73, 1, [73]));
    }

    // -- Guilt ---------------------------------------------------------------

    /**
     * Reported live: "bots should consider whether suppressing a single
     * opponent mood would net them more of a point swing than choosing
     * the 'all' mode." The bot's own Betrayal (56, black, value 6) sits
     * in play alongside the opponent's Contempt (59, black, value 1) --
     * 'all' mode would suppress BOTH (net swing +1 - 6 = -5), while
     * 'single' can cherry-pick just the opponent's Contempt (+1), a
     * strictly better outcome.
     */
    public function testGuiltChoosesSingleModeWhenItNetsABiggerSwingThanAll(): void
    {
        $state = $this->boardState(hands: [1 => [14, 56], 2 => [59]]);
        $state->moveHandToInPlay(1, 56);
        $state->moveHandToInPlay(2, 59);

        $action = $this->bot->chooseAction($state, [14], 1);

        self::assertSame(14, $action['card_id']);
        self::assertSame(['mode' => 'single', 'target_mood_id' => 59], $action['choices']);
    }

    /**
     * The opponent alone holds two qualifying moods (Contempt, 59, black,
     * value 1; Cruelty, 61, black, value 3) and the bot has none in play
     * -- 'all' costs the opponent both (+4 combined), strictly more than
     * 'single' could ever manage by cherry-picking just the pricier one
     * (+3).
     */
    public function testGuiltChoosesAllModeWhenItNetsABiggerSwingThanSingle(): void
    {
        $state = $this->boardState(hands: [1 => [14], 2 => [59, 61]]);
        $state->moveHandToInPlay(2, 59);
        $state->moveHandToInPlay(2, 61);

        $action = $this->bot->chooseAction($state, [14], 1);

        self::assertSame(14, $action['card_id']);
        self::assertSame(['mode' => 'all'], $action['choices']);
    }

    /** No black/red mood in play at all -- 'all' is a harmless no-op (GuiltEffect::allQualifyingMoods() finds nothing), so it's used rather than leaving 'single' with no legal target to fill in. */
    public function testGuiltChoosesAllModeAsANoOpWhenNothingQualifies(): void
    {
        $state = $this->boardState(hands: [1 => [14]]);

        $action = $this->bot->chooseAction($state, [14], 1);

        self::assertSame(14, $action['card_id']);
        self::assertSame(['mode' => 'all'], $action['choices']);
    }

    // -- Scorn -----------------------------------------------------------

    /**
     * Reported live: a bot's mandatory Scorn suppression targeted the
     * human's own mood when it should have targeted the other player's
     * copy of the same card instead -- "not sure whether this is a bug
     * (the targeting code couldn't distinguish between the two
     * Determinations) or just a bad play." Both players hold their own
     * separate physical Determination (112) -- distinct instance ids
     * (1120/1121) mapped to the SAME catalog entry via $catalogCardIdFor,
     * so they're genuinely identical in every printed respect, same as
     * two copies of one card in a real game. With no bespoke targeting,
     * the generic resolver's "highest value, any owner" policy could
     * tie-break either way; the fix must always prefer the non-teammate
     * opponent's own copy (1121) over the bot's own identical one
     * (1120).
     */
    public function testChooseActionTargetsTheOpponentsCopyOverTheBotsOwnIdenticalMoodWhenPlayingScorn(): void
    {
        $state = $this->boardState(
            hands: [1 => [24, 1120], 2 => [1121]],
            catalogCardIdFor: [1120 => 112, 1121 => 112],
        );
        $state->moveHandToInPlay(1, 1120);
        $state->moveHandToInPlay(2, 1121);

        $action = $this->bot->chooseAction($state, [24], 1);

        self::assertSame(24, $action['card_id']);
        self::assertSame(['target_mood_id' => 1121], $action['choices']);
    }

    /**
     * No non-teammate opponent mood in play at all -- Scorn's own
     * required target_mood_id must still supply SOME legal target, so
     * this falls back to the highest-value OTHER mood overall (Chivalry,
     * 4, value 3), excluding Scorn itself.
     */
    public function testChooseActionFallsBackToItsOwnMoodForScornWhenNoOpponentMoodExists(): void
    {
        $state = $this->boardState(hands: [1 => [24, 4]]);
        $state->moveHandToInPlay(1, 4);

        $action = $this->bot->chooseAction($state, [24], 1);

        self::assertSame(24, $action['card_id']);
        self::assertSame(['target_mood_id' => 4], $action['choices']);
    }
}
