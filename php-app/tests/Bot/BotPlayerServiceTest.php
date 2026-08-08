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
}
