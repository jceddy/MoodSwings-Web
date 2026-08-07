<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Bot;

use MoodSwings\Bot\BotChoiceResolver;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Tests\Rules\CatalogFixture;
use PHPUnit\Framework\TestCase;

final class BotChoiceResolverTest extends TestCase
{
    use CatalogFixture;

    private BotChoiceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new BotChoiceResolver();
    }

    private function boardState(array $hands = []): BoardState
    {
        return new BoardState($this->sampleCatalog(), DefaultEffectRegistry::build(), [1, 2, 3], $hands);
    }

    public function testOptionalFieldIsNeverResolved(): void
    {
        $state = $this->boardState();
        $field = ['key' => 'x', 'type' => 'mood', 'scope' => 'own', 'required' => false];

        self::assertNull($this->resolver->resolve($state, $field, 1, 0, 'dignity'));
    }

    public function testModeFieldPicksFirstOption(): void
    {
        $state = $this->boardState();
        $field = ['key' => 'color', 'type' => 'mode', 'required' => true, 'options' => ['white', 'blue', 'black', 'red', 'green']];

        self::assertSame('white', $this->resolver->resolve($state, $field, 1, 0, 'imagination'));
    }

    public function testModeFieldUsesTheHandAuthoredOverrideForGuiltContemptRedemption(): void
    {
        $state = $this->boardState();
        $field = ['key' => 'mode', 'type' => 'mode', 'required' => true, 'options' => ['single', 'all']];

        self::assertSame('all', $this->resolver->resolve($state, $field, 1, 0, 'guilt'));
        self::assertSame('all', $this->resolver->resolve($state, $field, 1, 0, 'contempt'));
        self::assertSame('all', $this->resolver->resolve($state, $field, 1, 0, 'redemption'));
        // Every other effect key with a plain 'mode' field gets the
        // ordinary "first option" policy, not the override.
        self::assertSame('single', $this->resolver->resolve($state, $field, 1, 0, 'some_other_effect'));
    }

    public function testValueFieldPicksItsOwnMinimum(): void
    {
        $state = $this->boardState();
        $field = ['key' => 'value', 'type' => 'value', 'required' => true, 'min' => 0, 'max' => 3];

        self::assertSame(0, $this->resolver->resolve($state, $field, 1, 0, 'rebellion'));
    }

    public function testMoodFieldOwnScopePicksTheLowestValueCandidate(): void
    {
        $state = $this->boardState(hands: [1 => [8, 55]]); // Dignity (value 3), Apathy (value 4)
        $state->moveHandToInPlay(1, 8);
        $state->moveHandToInPlay(1, 55);
        $field = ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => true];

        self::assertSame(8, $this->resolver->resolve($state, $field, 1, 0, 'envy'));
    }

    public function testMoodFieldOtherScopePicksTheHighestValueCandidate(): void
    {
        $state = $this->boardState(hands: [2 => [8, 55]]); // Dignity (value 3), Apathy (value 4)
        $state->moveHandToInPlay(2, 8);
        $state->moveHandToInPlay(2, 55);
        $field = ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'other', 'required' => true];

        self::assertSame(55, $this->resolver->resolve($state, $field, 1, 0, 'conviction'));
    }

    public function testMoodMultiFieldPicksCountMinLowestValueCandidates(): void
    {
        $state = $this->boardState(hands: [1 => [8, 55, 5]]); // Dignity 3, Apathy 4, Complacency 4
        $state->moveHandToInPlay(1, 8);
        $state->moveHandToInPlay(1, 55);
        $state->moveHandToInPlay(1, 5);
        $field = ['key' => 'discard_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => true, 'count' => ['min' => 1]];

        self::assertSame([8], $this->resolver->resolve($state, $field, 1, 0, 'self_loathing'));
    }

    public function testMoodMultiFieldReturnsNullWhenThereArentEnoughCandidates(): void
    {
        $state = $this->boardState();
        $field = ['key' => 'hand_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => true, 'count' => ['min' => 2, 'max' => 2]];

        self::assertNull($this->resolver->resolve($state, $field, 1, 0, 'regret'));
    }

    public function testHandCardFieldPicksTheLowestValueCandidate(): void
    {
        $state = $this->boardState(hands: [1 => [8, 55]]); // Dignity 3, Apathy 4, both still in hand
        $field = ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => true];

        self::assertSame(8, $this->resolver->resolve($state, $field, 1, 0, 'bliss'));
    }

    public function testPlayerFieldPicksTheFirstLegalCandidateRespectingScopeOther(): void
    {
        $state = $this->boardState(); // players [1, 2, 3]
        $field = ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'other', 'required' => true];

        self::assertSame(2, $this->resolver->resolve($state, $field, 1, 0, 'compulsion'));
    }

    public function testCandidateCardIdsOverrideTakesPriorityOverScopeFilter(): void
    {
        $state = $this->boardState(hands: [1 => [8, 55]]);
        $state->moveHandToInPlay(1, 8);
        $state->moveHandToInPlay(1, 55);
        $field = ['key' => 'x', 'type' => 'mood', 'scope' => 'own', 'required' => true, 'candidate_card_ids' => [55]];

        self::assertSame(55, $this->resolver->resolve($state, $field, 1, 0, ''));
    }

    public function testIncludesSelfOffersTheCardCurrentlyBeingPlayedAsACandidate(): void
    {
        $state = $this->boardState(hands: [1 => [80]]); // Anger, nothing else in play
        $field = ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'required' => true, 'includes_self' => true];

        self::assertSame(80, $this->resolver->resolve($state, $field, 1, 80, 'anger'));
    }

    public function testCardOrderFieldAcceptsTheGivenDefaultOrder(): void
    {
        $state = $this->boardState();
        $field = ['key' => 'ordered_card_ids', 'type' => 'card_order', 'required' => true, 'cards' => [['card_id' => 5], ['card_id' => 8]]];

        self::assertSame([5, 8], $this->resolver->resolve($state, $field, 1, 0, ''));
    }

    public function testUnsupportedFieldTypeResolvesToNull(): void
    {
        $state = $this->boardState();
        $field = ['key' => 'grant_source_card_id', 'type' => 'grant_choice', 'required' => true, 'options' => []];

        self::assertNull($this->resolver->resolve($state, $field, 1, 0, ''));
    }
}
