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

    /**
     * Curiosity's own optional target_player_id (ALWAYS_FILLED_OPTIONAL_FIELDS)
     * is resolved despite required being false -- unlike an ordinary
     * optional field (see testOptionalFieldIsNeverResolved() above).
     */
    public function testCuriositysOptionalPlayerFieldIsAlwaysFilledDespiteBeingOptional(): void
    {
        $state = $this->boardState(); // players [1, 2, 3]
        $field = ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => false];

        self::assertSame(2, $this->resolver->resolve($state, $field, 1, 0, 'curiosity'));
    }

    /**
     * scope 'any' would otherwise permit self-targeting, but a forced
     * field always excludes the acting player anyway (see
     * ALWAYS_FILLED_OPTIONAL_FIELDS's own docblock).
     */
    public function testCuriositysForcedFieldNeverTargetsTheActingPlayerItself(): void
    {
        $state = $this->boardState(); // players [1, 2, 3]
        $field = ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => false];

        $chosen = $this->resolver->resolve($state, $field, 1, 0, 'curiosity');

        self::assertNotSame(1, $chosen);
    }

    /**
     * Suspicion's own player_ids field takes every legal opponent, not
     * just count.min (which would default to 1 -- see pickIdCandidates()'s
     * own $takeAll behavior) -- "choose any number of players" has no
     * downside to choosing more, so the bot chooses all of them.
     */
    public function testSuspicionsForcedMultiFieldTakesEveryOpponentInsteadOfJustCountMin(): void
    {
        $state = $this->boardState(hands: [2 => [8], 3 => [55]]); // players [1, 2, 3]
        $field = ['key' => 'player_ids', 'type' => 'player', 'scope' => 'any', 'multi' => true, 'required' => false, 'filter' => ['min_hand_count' => 1]];

        self::assertSame([2, 3], $this->resolver->resolve($state, $field, 1, 0, 'suspicion'));
    }

    /**
     * If nothing qualifies (here, every other player's hand is empty, so
     * the field's own min_hand_count filter excludes everyone), a forced
     * field resolves to null exactly like an unfillable required one --
     * BotPlayerService is the one that treats a forced field's own null
     * differently from a required field's (see its own test coverage).
     */
    public function testSuspicionsForcedFieldReturnsNullWhenNoOpponentsQualify(): void
    {
        $state = $this->boardState(); // no hands at all
        $field = ['key' => 'player_ids', 'type' => 'player', 'scope' => 'any', 'multi' => true, 'required' => false, 'filter' => ['min_hand_count' => 1]];

        self::assertNull($this->resolver->resolve($state, $field, 1, 0, 'suspicion'));
    }

    /**
     * A similarly-shaped optional target_player_id field on any OTHER
     * effect key -- e.g. Malice, which grants the target extra plays, a
     * real trade-off unlike Curiosity/Suspicion's own free targets -- is
     * still never resolved, proving this is a narrow per-card exception,
     * not a blanket "always fill optional player fields" policy change.
     */
    public function testOptionalPlayerFieldsOnOtherEffectKeysAreStillNeverResolved(): void
    {
        $state = $this->boardState();
        $field = ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => false];

        self::assertNull($this->resolver->resolve($state, $field, 1, 0, 'malice'));
    }
}
