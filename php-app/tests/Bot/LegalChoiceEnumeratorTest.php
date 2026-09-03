<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Bot;

use MoodSwings\Bot\BotChoiceResolver;
use MoodSwings\Bot\BotPlayerService;
use MoodSwings\Bot\LegalChoiceEnumerator;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Tests\Rules\CatalogFixture;
use PHPUnit\Framework\TestCase;

final class LegalChoiceEnumeratorTest extends TestCase
{
    use CatalogFixture;

    private LegalChoiceEnumerator $enumerator;

    protected function setUp(): void
    {
        $heuristic = new BotPlayerService(new BotChoiceResolver());
        $this->enumerator = new LegalChoiceEnumerator($heuristic, new BotChoiceResolver());
    }

    /** @param array<int, int[]> $hands */
    private function boardState(array $hands): BoardState
    {
        return new BoardState($this->sampleCatalog(), DefaultEffectRegistry::build(), [1, 2], $hands);
    }

    public function testEnumerateGeneratesOneVariantPerLegalTargetForASchemaDrivenField(): void
    {
        // Guile's own schema (CardChoiceSchema) has exactly one required
        // schema-driven target field with scope 'other' -- target_mood_id
        // -- so with two of player 2's own moods in play, enumerate()
        // should offer one action variant per target, not just the
        // resolver's own single non-strategic pick.
        $state = $this->boardState([1 => [40, 5, 30], 2 => [8, 9]]); // Guile + 2 filler cost cards; opponent has 2 moods to move into play
        $state->moveHandToInPlay(2, 8); // a dummy opponent mood to target
        $state->moveHandToInPlay(2, 9); // a second dummy opponent mood to target
        $state->startTurn(1);

        $actions = $this->enumerator->enumerate($state, [40], 1);

        $targets = array_map(static fn (array $action) => $action['choices']['target_mood_id'], $actions);
        sort($targets);
        self::assertSame([8, 9], $targets, 'one variant per legal opponent mood target, not just the resolver\'s own single pick');
        foreach ($actions as $action) {
            self::assertSame(40, $action['card_id']);
            self::assertSame([5, 30], $action['choices']['discard_card_ids'] ?? null, 'the cost field itself is untouched by target-variant generation');
        }
    }

    public function testEnumerateOffersOnlyTheHeuristicDefaultForABespokeChoiceCard(): void
    {
        // Denial is one of BotPlayerService's own bespoke choice-building
        // effect keys (denialTargetMoodIds()) rather than a generic
        // schema-driven field -- see usesBespokeChoiceBuilding()'s own
        // docblock for why this class deliberately never tries to
        // generate its own alternate targeting for one of these.
        $state = $this->boardState([1 => [34], 2 => [8, 9]]); // Denial alone, no cost
        $state->moveHandToInPlay(2, 8);
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $actions = $this->enumerator->enumerate($state, [34], 1);

        self::assertCount(1, $actions, 'a bespoke-choice effect key must never be varied beyond the heuristic\'s own single built choice set');
    }

    public function testEnumerateSkipsACardWithNoLegalChoiceSetAtAll(): void
    {
        // Guile always needs 2 hand cards to discard as its own cost --
        // with nothing else in hand, buildChoicesForCard() itself returns
        // null, and this class must skip the card entirely rather than
        // emitting a broken/incomplete action.
        $state = $this->boardState([1 => [40], 2 => []]);
        $state->startTurn(1);

        self::assertSame([], $this->enumerator->enumerate($state, [40], 1));
    }
}
