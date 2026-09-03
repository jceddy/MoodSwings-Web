<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Bot;

use MoodSwings\Bot\BotChoiceResolver;
use MoodSwings\Bot\BotPlayerService;
use MoodSwings\Bot\Determinizer;
use MoodSwings\Bot\LegalChoiceEnumerator;
use MoodSwings\Bot\SearchBotPlayerService;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use MoodSwings\Tests\Rules\CatalogFixture;
use PHPUnit\Framework\TestCase;

final class SearchBotPlayerServiceTest extends TestCase
{
    use CatalogFixture;

    private SearchBotPlayerService $search;

    protected function setUp(): void
    {
        $heuristic = new BotPlayerService(new BotChoiceResolver());
        $this->search = new SearchBotPlayerService(
            new MoodPlayService(DefaultEffectRegistry::build()),
            new RoundScorer(),
            $heuristic,
            SearchBotPlayerService::defaultEnumeratorFor($heuristic),
        );
    }

    /** @param array<int, int[]> $hands */
    private function boardState(array $hands): BoardState
    {
        return new BoardState($this->sampleCatalog(), DefaultEffectRegistry::build(), [1, 2], $hands);
    }

    public function testChooseActionPrefersTheHigherValueOfTwoInertCards(): void
    {
        // Apathy (id 55, value 4) and Creativity (id 32, value 0 in this
        // fixture) both have hasToPlay/hasWhileInPlay/hasAfterPlaying all
        // false -- neither has any cost, ability, or choice field at all
        // (see BotPlayerServiceTest's own identical use of Apathy), so
        // this is a clean, fully deterministic "which of two known point
        // totals is actually better" comparison with zero interaction
        // noise. Player 2 has no hand and no deck at all, so their own
        // simulated turn always trivially passes -- the only thing that
        // varies rollout to rollout is which of MY two cards search
        // actually tries, never anything about the opponent.
        $state = $this->boardState([1 => [55, 32], 2 => []]);
        $state->startTurn(1);

        $action = $this->search->chooseAction($state, [55, 32], 1, timeBudgetSeconds: 0.2);

        self::assertNotNull($action);
        self::assertSame(55, $action['card_id'], 'Apathy (value 4) strictly dominates Creativity (value 0) here -- search must find this with a nonzero budget');
        self::assertSame([], $action['choices']);
    }

    public function testChooseActionReturnsALegalActionEvenWithAnEffectivelyZeroBudget(): void
    {
        $state = $this->boardState([1 => [55, 32], 2 => []]);
        $state->startTurn(1);

        $action = $this->search->chooseAction($state, [55, 32], 1, timeBudgetSeconds: 0.0);

        self::assertNotNull($action);
        self::assertContains($action['card_id'], [55, 32], 'even a near-zero budget must still return one of the actually-legal candidates, never nothing and never something illegal');
    }

    public function testChooseActionReturnsNullWhenNoCardsArePlayable(): void
    {
        $state = $this->boardState([1 => [], 2 => []]);
        $state->startTurn(1);

        self::assertNull($this->search->chooseAction($state, [], 1, timeBudgetSeconds: 0.1));
    }

    public function testChooseActionNeverMutatesTheRealBoardStatePassedIn(): void
    {
        // Every rollout must operate on a clone (via Determinizer, itself
        // built on BoardState::__clone()) -- the real, live state driving
        // an actual game must come back completely untouched regardless
        // of how many hypothetical plays search tried against copies of
        // it.
        $state = $this->boardState([1 => [55, 32], 2 => []]);
        $state->startTurn(1);

        $this->search->chooseAction($state, [55, 32], 1, timeBudgetSeconds: 0.2);

        self::assertSame([55, 32], $state->hand(1));
        self::assertSame([], $state->hand(2));
        self::assertSame([], $state->moodsInPlay());
    }
}
