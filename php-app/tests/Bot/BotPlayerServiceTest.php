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

    private function boardState(array $hands = []): BoardState
    {
        return new BoardState($this->sampleCatalog(), DefaultEffectRegistry::build(), [1, 2, 3], $hands);
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
     * Still played (and still commits to a mode) once it's the only
     * legal candidate left, even with neither trigger active -- the
     * demotion only ever changes ORDER, never whether Rationalization is
     * worth playing at all.
     */
    public function testChooseActionStillPlaysRationalizationAloneEvenWithoutATrigger(): void
    {
        $state = $this->boardState(hands: [1 => [49, 4]]);

        $action = $this->bot->chooseAction($state, [49], 1);

        self::assertSame(49, $action['card_id']);
        self::assertSame(['mode' => 'refresh'], $action['choices']);
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
}
