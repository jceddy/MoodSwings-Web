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
}
