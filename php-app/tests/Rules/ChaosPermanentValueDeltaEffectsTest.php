<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosEffects\Chaos056Effect;
use MoodSwings\Rules\ChaosEffects\Chaos120Effect;
use MoodSwings\Rules\ChaosEffects\Chaos133Effect;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\PlayerChoices;
use PHPUnit\Framework\TestCase;

/**
 * A bug caught live: chaos_056/064/120/133 ("permanently increase/
 * decrease this mood's value BY N") all used to call
 * BoardState::setValueOverride() -- the same ABSOLUTE "value BECOMES N"
 * mechanism Dignity/Delight-style printed abilities use, which the
 * frontend visually rotates 180 degrees (GameService's own 'value_locked'
 * field) and which REPLACES rather than stacks with a card's own dice/alt
 * value. Reusing it for a delta effect meant an attached chaos_133 firing
 * on a plain card would incorrectly rotate it (nothing about the card's
 * OWN printed ability actually locked anything), and would silently
 * clobber whatever alt-value computation the card's own printed ability
 * had already produced instead of adjusting it. adjustChaosValueDelta()
 * fixes both: see its own docblock on BoardState.
 *
 * @see \MoodSwings\Tests\Rules\ChaosDraftReactiveEffectsTest::testOnMoodDiscardedChaos064ReducesARandomOpponentMoodsValue
 *     for chaos_064's own reactive (onMoodDiscarded) firing, via the full
 *     MoodPlayService dispatch.
 */
final class ChaosPermanentValueDeltaEffectsTest extends TestCase
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

    /**
     * Chivalry (id 4, base 3/alt 5, "value is 5 if you didn't go first
     * this round") -- proving the exact scenario the bug report names:
     * with player 1 not going first, Chivalry's own printed ability
     * already computes its alt value (5); chaos_133's own delta (+2 for
     * the one green Apathy... no, Chivalry itself is white, so +2 for
     * itself matching the chosen color) STACKS on top of that 5, not
     * instead of it, and never touches 'valueOverride' at all -- so
     * neither value_locked nor any rotation fires.
     */
    public function testChaos133sDeltaStacksOnTopOfTheCardsOwnAltValueComputation(): void
    {
        $state = $this->boardState(hands: [1 => [4]]);
        $state->moveHandToInPlay(1, 4); // Chivalry
        $state->startRound(2); // player 2 goes first -- player 1's Chivalry now reads its alt value (5)

        self::assertSame(5, $state->valueOf(4), 'sanity check: Chivalry alone should already be reading its alt value');

        $effect = new Chaos133Effect();
        $effect->afterPlaying($state, 4, 1, new PlayerChoices(['color' => 'white'])); // matches Chivalry's own color, +2 for itself

        self::assertSame(7, $state->valueOf(4), "the +2 delta should stack on top of Chivalry's own alt value (5), not replace it");
        self::assertNull($state->effectState(4, 'valueOverride'), 'a chaos delta must never set valueOverride -- that would incorrectly trigger the 180-degree value_locked rotation');
        self::assertSame(2, $state->chaosValueDeltaOf(4));
    }

    public function testChaos056sDeltaReducesTheTargetWithoutSettingValueOverride(): void
    {
        // Chivalry (4, value 3 base, player 1's own) reduces Apathy (55, value 4, player 2's).
        $state = $this->boardState(hands: [1 => [4], 2 => [55]]);
        $state->moveHandToInPlay(1, 4);
        $state->moveHandToInPlay(2, 55);
        $state->startRound(1);

        $effect = new Chaos056Effect();
        $effect->afterPlaying($state, 4, 1, new PlayerChoices(['mood_card_id' => 55]));

        self::assertSame(1, $state->valueOf(55)); // 4 - 3
        self::assertNull($state->effectState(55, 'valueOverride'));
        self::assertSame(-3, $state->chaosValueDeltaOf(55));
    }

    public function testChaos056DiscardsTheTargetInsteadOfGoingNegative(): void
    {
        $state = $this->boardState(hands: [1 => [4], 2 => [55]]);
        $state->moveHandToInPlay(1, 4); // Chivalry, value 3
        $state->moveHandToInPlay(2, 55); // Apathy, value 4
        $state->startRound(2); // player 1 didn't go first -- Chivalry reads its alt value, 5 (> Apathy's 4)

        $effect = new Chaos056Effect();
        $effect->afterPlaying($state, 4, 1, new PlayerChoices(['mood_card_id' => 55]));

        self::assertFalse($state->isInPlay(55));
        self::assertContains(55, $state->discardPile());
    }

    public function testChaos120sDeltaIncreasesTheTargetWithoutSettingValueOverride(): void
    {
        $state = $this->boardState(hands: [1 => [4], 2 => [55]]);
        $state->moveHandToInPlay(1, 4);
        $state->moveHandToInPlay(2, 55);
        $state->startRound(1);

        $effect = new Chaos120Effect();
        $effect->afterPlaying($state, 4, 1, new PlayerChoices([])); // arms the marker
        $effect->onMoodPlayed($state, 4, 1, 2, 55); // player 2 (an opponent) plays a mood -- fires the bonus

        self::assertSame(4, $state->valueOf(4)); // 3 + 1
        self::assertNull($state->effectState(4, 'valueOverride'));
        self::assertSame(1, $state->chaosValueDeltaOf(4));
    }

    /** Multiple firings against the same card accumulate rather than overwrite. */
    public function testChaosValueDeltaAccumulatesAcrossRepeatedFirings(): void
    {
        $state = $this->boardState(hands: [1 => [4], 2 => [55]]);
        $state->moveHandToInPlay(1, 4); // Chivalry, value 3 (player 1 goes first, so its base value applies)
        $state->moveHandToInPlay(2, 55);
        $state->startRound(1);

        $effect = new Chaos120Effect();
        $effect->afterPlaying($state, 4, 1, new PlayerChoices([])); // arms
        $effect->onMoodPlayed($state, 4, 1, 2, 55); // +1
        $effect->afterPlaying($state, 4, 1, new PlayerChoices([])); // re-arms for a second trigger
        $effect->onMoodPlayed($state, 4, 1, 2, 55); // +1 again

        self::assertSame(2, $state->chaosValueDeltaOf(4));
        self::assertSame(5, $state->valueOf(4)); // 3 (base) + 1 + 1
        self::assertNull($state->effectState(4, 'valueOverride'));
    }
}
