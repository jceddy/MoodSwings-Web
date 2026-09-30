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

    public function testEnumerateOffersPanicBouncingTheOpponentsHighestMoods(): void
    {
        // Panic is a bespoke-choice card whose heuristic targeting only ever
        // bounces the bot's own Compulsion/Suspicion (with Validation in
        // play), so a search never considered bouncing an opponent's mood
        // -- reported live: a round lost where bouncing a 3-point mood won.
        $state = $this->boardState([1 => [48], 2 => [5, 2, 3]]);
        $state->moveHandToInPlay(2, 5); // Complacency, value 4
        $state->moveHandToInPlay(2, 2); // Benevolence, value 2
        $state->moveHandToInPlay(2, 3); // Charity, value 1
        $state->startTurn(1);

        $actions = $this->enumerator->enumerate($state, [48], 1);

        $targetSets = array_map(static fn (array $action) => $action['choices']['target_mood_ids'] ?? [], $actions);
        self::assertContains([], $targetSets, 'the no-bounce default stays on offer');
        self::assertContains([5], $targetSets);
        self::assertContains([2], $targetSets);
        self::assertNotContains([3], $targetSets, 'only the top two opponent moods are offered, to bound the branching factor');
        foreach ($actions as $action) {
            self::assertSame(48, $action['card_id']);
            self::assertLessThanOrEqual(2, count($action['choices']['target_mood_ids'] ?? []));
        }
    }

    public function testEnumerateNeverOffersBouncingAnOpponentsReplayMoodUnlessItDecidesTheGame(): void
    {
        // Reported live: an opponent replaying their Compulsion/Suspicion/
        // Intimidation/Paranoia is a serious long-run card disadvantage.
        $state = $this->boardState([1 => [48], 2 => [86, 5]]);
        $state->moveHandToInPlay(2, 86); // their Compulsion, value 3
        $state->moveHandToInPlay(2, 5);  // Complacency, value 4
        $state->startTurn(1);

        $targetSets = array_map(static fn (array $a) => $a['choices']['target_mood_ids'] ?? [], $this->enumerator->enumerate($state, [48], 1));
        self::assertNotContains([86], $targetSets);
        self::assertContains([5], $targetSets);

        // One round win from the game, and bouncing the Compulsion is what
        // would take the round's lead here.
        $state = $this->boardState([1 => [48], 2 => [86]]);
        $state->moveHandToInPlay(2, 86);
        $state->startTurn(1);

        $targetSets = array_map(static fn (array $a) => $a['choices']['target_mood_ids'] ?? [], $this->enumerator->enumerate($state, [48], 1, 1, []));
        self::assertContains([86], $targetSets);
    }

    public function testEnumeratePanicNeverPairsTwoMoodsOfTheSameOwner(): void
    {
        $state = $this->boardState([1 => [48, 7], 2 => [5, 2]]);
        $state->moveHandToInPlay(1, 7); // the bot's own Courage
        $state->moveHandToInPlay(2, 5);
        $state->moveHandToInPlay(2, 2);
        $state->startTurn(1);

        $actions = $this->enumerator->enumerate($state, [48], 1);

        foreach ($actions as $action) {
            $targets = $action['choices']['target_mood_ids'] ?? [];
            $owners = array_map(static fn (int $id) => $state->ownerOf($id), $targets);
            self::assertSame($owners, array_values(array_unique($owners)), 'Panic allows at most one mood per chosen player');
        }
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
