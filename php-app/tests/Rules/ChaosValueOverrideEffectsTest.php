<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosEffects\Chaos001Effect;
use MoodSwings\Rules\ChaosEffects\Chaos033Effect;
use MoodSwings\Rules\ChaosEffects\Chaos062Effect;
use MoodSwings\Rules\ChaosEffects\Chaos095Effect;
use MoodSwings\Rules\ChaosEffects\Chaos108Effect;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\PlayerChoices;
use PHPUnit\Framework\TestCase;

/**
 * A bug caught live, reported for chaos_033: "the UI should not rotate the
 * card 180 degrees". chaos_001/008/033/058/062/087/095/108/110/111/118 all
 * share Dignity's/Delight's exact absolute "this mood's value becomes N"
 * printed wording, and the original chaos_value_delta fix (see
 * ChaosPermanentValueDeltaEffectsTest) deliberately left these calling the
 * base-card BoardState::setValueOverride() directly, reasoning they were
 * conceptually identical to a card locking in its own value. That was
 * wrong: the card a chaos effect attaches to is essentially arbitrary
 * (whatever hand card the round's offer got attached to), so its OWN
 * printed ability almost never actually fixed a value at all -- reusing
 * setValueOverride() meant the frontend's 'value_locked' 180-degree
 * rotation fired anyway, misleadingly suggesting it did.
 * BoardState::setChaosValueOverride()/chaosValueOverrideOf() fix this the
 * same way adjustChaosValueDelta() fixed the delta-shaped effects: a
 * separate effectState key that never touches 'valueOverride' at all.
 *
 * chaos_058/118/008(-087/110/111) are covered instead by
 * ChaosEffects/ChaosHandCardStalenessEffectsTest, which already exercises
 * their full ChaosRequiresOpponentDecision dispatch -- this file only adds
 * the never-sets-valueOverride assertion those tests were missing.
 */
final class ChaosValueOverrideEffectsTest extends TestCase
{
    use CatalogFixture;

    private function boardState(array $hands, array $chaosCatalog = [], array $chaosEffectIdFor = []): BoardState
    {
        return new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            $hands,
            chaosCatalog: $chaosCatalog,
            chaosEffectIdFor: $chaosEffectIdFor,
        );
    }

    /** BoardState-level precedence: an attached chaos effect's own absolute override wins over the base card's own valueOverride, and never sets 'valueOverride' itself. */
    public function testChaosValueOverrideTakesPrecedenceOverTheCardsOwnValueOverride(): void
    {
        $state = $this->boardState(hands: [1 => [2]]); // Benevolence
        $state->moveHandToInPlay(1, 2);
        $state->startRound(1);

        $state->setValueOverride(2, 5); // as if Benevolence's own printed ability had fixed it at 5
        $state->setChaosValueOverride(2, 8); // an attached chaos effect's own "becomes 8" fires afterwards

        self::assertSame(8, $state->valueOf(2), 'the attached chaos effect gets the final say, same as applyChaosValuePipeline() for while-in-play effects');
        self::assertSame(8, $state->chaosValueOverrideOf(2));
    }

    /**
     * The exact reported scenario: chaos_033 attached to a white card,
     * targeting a player whose only hand card is also white.
     */
    public function testChaos033SetsChaosValueOverrideNotValueOverrideOnColorMatch(): void
    {
        $state = $this->boardState(hands: [1 => [2], 2 => [7]]); // Benevolence (white, host); Courage (white)
        $state->moveHandToInPlay(1, 2);
        $state->startRound(1);

        $effect = new Chaos033Effect();
        $effect->afterPlaying($state, 2, 1, new PlayerChoices(['target_player_id' => 2]));

        self::assertSame(6, $state->valueOf(2));
        self::assertSame(6, $state->chaosValueOverrideOf(2));
        self::assertNull($state->effectState(2, 'valueOverride'), 'must never set valueOverride -- that would incorrectly trigger the 180-degree value_locked rotation');
    }

    public function testChaos033DoesNothingWhenTheRevealedCardDoesNotShareAColor(): void
    {
        $state = $this->boardState(hands: [1 => [53], 2 => [7]]); // Ambition (black, host); Courage (white)
        $state->moveHandToInPlay(1, 53);
        $state->startRound(1);

        $effect = new Chaos033Effect();
        $effect->afterPlaying($state, 53, 1, new PlayerChoices(['target_player_id' => 2]));

        self::assertSame(2, $state->valueOf(53)); // unaffected, base value
        self::assertNull($state->chaosValueOverrideOf(53));
        self::assertNull($state->effectState(53, 'valueOverride'));
    }

    public function testChaos001SetsChaosValueOverrideNotValueOverride(): void
    {
        $state = $this->boardState(hands: [1 => [2], 2 => [7]]); // Benevolence (host); Courage, to seed the discard pile
        $state->moveHandToDiscard(2, 7);
        $state->moveHandToInPlay(1, 2);
        $state->startRound(1);

        $effect = new Chaos001Effect();
        $effect->afterPlaying($state, 2, 1, new PlayerChoices([]));

        self::assertSame(7, $state->valueOf(2));
        self::assertSame(7, $state->chaosValueOverrideOf(2));
        self::assertNull($state->effectState(2, 'valueOverride'));
    }

    public function testChaos062SetsChaosValueOverrideNotValueOverride(): void
    {
        $state = $this->boardState(hands: [1 => [2], 2 => [7]]); // Benevolence (host); Courage, to seed the discard pile
        $state->moveHandToDiscard(2, 7);
        $state->moveHandToInPlay(1, 2);
        $state->startRound(1);

        $effect = new Chaos062Effect();
        $effect->afterPlaying($state, 2, 1, new PlayerChoices(['discard_card_id' => 7, 'opponent_player_id' => 2]));

        self::assertContains(7, $state->hand(2));
        self::assertSame(6, $state->valueOf(2));
        self::assertSame(6, $state->chaosValueOverrideOf(2));
        self::assertNull($state->effectState(2, 'valueOverride'));
    }

    public function testChaos095SetsChaosValueOverrideNotValueOverride(): void
    {
        $state = $this->boardState(hands: [1 => [2, 3, 5]]); // Benevolence (host); Charity; Complacency
        $state->moveHandToInPlay(1, 2);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 5);
        $state->startRound(1);

        $effect = new Chaos095Effect();
        $effect->afterPlaying($state, 2, 1, new PlayerChoices(['discard_mood_card_ids' => [3, 5]]));

        self::assertContains(3, $state->discardPile());
        self::assertContains(5, $state->discardPile());
        self::assertSame(9, $state->valueOf(2));
        self::assertSame(9, $state->chaosValueOverrideOf(2));
        self::assertNull($state->effectState(2, 'valueOverride'));
    }

    /** Chaos108 boosts OTHER same-colored moods, not the host card itself -- the host's own value is untouched. */
    public function testChaos108SetsChaosValueOverrideOnOtherSameColorMoodsNotValueOverride(): void
    {
        $state = $this->boardState(hands: [1 => [2, 7]]); // Benevolence (white, host); Courage (white, base value 1)
        $state->moveHandToInPlay(1, 2);
        $state->moveHandToInPlay(1, 7);
        $state->startRound(1);

        $effect = new Chaos108Effect();
        $effect->afterPlaying($state, 2, 1, new PlayerChoices([]));

        self::assertSame(2, $state->valueOf(7)); // 1 + 1
        self::assertSame(2, $state->chaosValueOverrideOf(7));
        self::assertNull($state->effectState(7, 'valueOverride'));
    }
}
