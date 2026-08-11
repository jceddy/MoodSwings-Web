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
}
