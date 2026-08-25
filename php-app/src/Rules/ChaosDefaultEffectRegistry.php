<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\ChaosEffects\Chaos001Effect;
use MoodSwings\Rules\ChaosEffects\Chaos006Effect;
use MoodSwings\Rules\ChaosEffects\Chaos010Effect;
use MoodSwings\Rules\ChaosEffects\Chaos011Effect;
use MoodSwings\Rules\ChaosEffects\Chaos012Effect;
use MoodSwings\Rules\ChaosEffects\Chaos014Effect;
use MoodSwings\Rules\ChaosEffects\Chaos015Effect;
use MoodSwings\Rules\ChaosEffects\Chaos016Effect;
use MoodSwings\Rules\ChaosEffects\Chaos019Effect;
use MoodSwings\Rules\ChaosEffects\Chaos022Effect;
use MoodSwings\Rules\ChaosEffects\Chaos023Effect;
use MoodSwings\Rules\ChaosEffects\Chaos024Effect;
use MoodSwings\Rules\ChaosEffects\Chaos025Effect;
use MoodSwings\Rules\ChaosEffects\Chaos026Effect;
use MoodSwings\Rules\ChaosEffects\Chaos029Effect;
use MoodSwings\Rules\ChaosEffects\Chaos030Effect;
use MoodSwings\Rules\ChaosEffects\Chaos031Effect;
use MoodSwings\Rules\ChaosEffects\Chaos033Effect;
use MoodSwings\Rules\ChaosEffects\Chaos034Effect;
use MoodSwings\Rules\ChaosEffects\Chaos035Effect;
use MoodSwings\Rules\ChaosEffects\Chaos036Effect;
use MoodSwings\Rules\ChaosEffects\Chaos037Effect;
use MoodSwings\Rules\ChaosEffects\Chaos038Effect;
use MoodSwings\Rules\ChaosEffects\Chaos039Effect;
use MoodSwings\Rules\ChaosEffects\Chaos040Effect;
use MoodSwings\Rules\ChaosEffects\Chaos041Effect;
use MoodSwings\Rules\ChaosEffects\Chaos042Effect;
use MoodSwings\Rules\ChaosEffects\Chaos043Effect;
use MoodSwings\Rules\ChaosEffects\Chaos046Effect;
use MoodSwings\Rules\ChaosEffects\Chaos049Effect;
use MoodSwings\Rules\ChaosEffects\Chaos050Effect;
use MoodSwings\Rules\ChaosEffects\Chaos051Effect;
use MoodSwings\Rules\ChaosEffects\Chaos052Effect;
use MoodSwings\Rules\ChaosEffects\Chaos053Effect;
use MoodSwings\Rules\ChaosEffects\Chaos054Effect;
use MoodSwings\Rules\ChaosEffects\Chaos056Effect;
use MoodSwings\Rules\ChaosEffects\Chaos057Effect;
use MoodSwings\Rules\ChaosEffects\Chaos058Effect;
use MoodSwings\Rules\ChaosEffects\Chaos059Effect;
use MoodSwings\Rules\ChaosEffects\Chaos060Effect;
use MoodSwings\Rules\ChaosEffects\Chaos061Effect;
use MoodSwings\Rules\ChaosEffects\Chaos062Effect;
use MoodSwings\Rules\ChaosEffects\Chaos064Effect;
use MoodSwings\Rules\ChaosEffects\Chaos066Effect;
use MoodSwings\Rules\ChaosEffects\Chaos067Effect;
use MoodSwings\Rules\ChaosEffects\Chaos068Effect;
use MoodSwings\Rules\ChaosEffects\Chaos069Effect;
use MoodSwings\Rules\ChaosEffects\Chaos071Effect;
use MoodSwings\Rules\ChaosEffects\Chaos073Effect;
use MoodSwings\Rules\ChaosEffects\Chaos075Effect;
use MoodSwings\Rules\ChaosEffects\Chaos078Effect;
use MoodSwings\Rules\ChaosEffects\Chaos079Effect;
use MoodSwings\Rules\ChaosEffects\Chaos080Effect;
use MoodSwings\Rules\ChaosEffects\Chaos082Effect;
use MoodSwings\Rules\ChaosEffects\Chaos084Effect;
use MoodSwings\Rules\ChaosEffects\Chaos085Effect;
use MoodSwings\Rules\ChaosEffects\Chaos086Effect;
use MoodSwings\Rules\ChaosEffects\Chaos089Effect;
use MoodSwings\Rules\ChaosEffects\Chaos091Effect;
use MoodSwings\Rules\ChaosEffects\Chaos094Effect;
use MoodSwings\Rules\ChaosEffects\Chaos095Effect;
use MoodSwings\Rules\ChaosEffects\Chaos096Effect;
use MoodSwings\Rules\ChaosEffects\Chaos097Effect;
use MoodSwings\Rules\ChaosEffects\Chaos098Effect;
use MoodSwings\Rules\ChaosEffects\Chaos099Effect;
use MoodSwings\Rules\ChaosEffects\Chaos100Effect;
use MoodSwings\Rules\ChaosEffects\Chaos102Effect;
use MoodSwings\Rules\ChaosEffects\Chaos103Effect;
use MoodSwings\Rules\ChaosEffects\Chaos105Effect;
use MoodSwings\Rules\ChaosEffects\Chaos106Effect;
use MoodSwings\Rules\ChaosEffects\Chaos107Effect;
use MoodSwings\Rules\ChaosEffects\Chaos108Effect;
use MoodSwings\Rules\ChaosEffects\Chaos116Effect;
use MoodSwings\Rules\ChaosEffects\Chaos118Effect;
use MoodSwings\Rules\ChaosEffects\Chaos120Effect;
use MoodSwings\Rules\ChaosEffects\Chaos121Effect;
use MoodSwings\Rules\ChaosEffects\Chaos124Effect;
use MoodSwings\Rules\ChaosEffects\Chaos128Effect;
use MoodSwings\Rules\ChaosEffects\Chaos133Effect;
use MoodSwings\Rules\ChaosEffects\ChaosActOnChosenPlayersMoodEffect;
use MoodSwings\Rules\ChaosEffects\ChaosAdditiveCountValueEffect;
use MoodSwings\Rules\ChaosEffects\ChaosConditionalValueEffect;
use MoodSwings\Rules\ChaosEffects\ChaosDiscardedThisRoundValueEffect;
use MoodSwings\Rules\ChaosEffects\ChaosDiscardValueToBoostSelfEffect;
use MoodSwings\Rules\ChaosEffects\ChaosGrantExtraPlayEffect;
use MoodSwings\Rules\ChaosEffects\ChaosPairedColorThresholdEffect;
use MoodSwings\Rules\ChaosEffects\ChaosPlayedThisRoundValueEffect;
use MoodSwings\Rules\ChaosEffects\ChaosSimpleAfterPlayingEffect;
use MoodSwings\Rules\ChaosEffects\ChaosTokenSpawnEffect;
use MoodSwings\Rules\ChaosEffects\ChaosWentFirstValueEffect;

/**
 * Builds the fully-populated ChaosEffectRegistry for the whole 133-effect
 * Chaos Draft pool (issue #405) -- the chaos_effects.effect_key analog of
 * DefaultEffectRegistry::build(). Token catalog card ids (134-138) match
 * migration 0183's own INSERT order: Smugness/white=134, Unconcern/
 * blue=135, Passivity/black=136, Tedium/red=137, Idleness/green=138.
 *
 * A large share of the pool reduces to one of a handful of reusable
 * parameterized classes (see Rules/ChaosEffects/Chaos*Effect.php's own
 * class docblocks for which real cards each one mirrors); the rest are
 * bespoke one-class-per-effect implementations, following the same
 * convention DefaultEffectRegistry/Rules/Effects/*.php already use for
 * the 133 real printed cards.
 */
final class ChaosDefaultEffectRegistry
{
    private const SMUGNESS_TOKEN = 134;
    private const UNCONCERN_TOKEN = 135;
    private const PASSIVITY_TOKEN = 136;
    private const TEDIUM_TOKEN = 137;
    private const IDLENESS_TOKEN = 138;

    public static function build(): ChaosEffectRegistry
    {
        $registry = new ChaosEffectRegistry();

        $registry->register('chaos_001', new Chaos001Effect());
        $registry->register('chaos_002', new ChaosGrantExtraPlayEffect(1, ['type' => 'does_not_share_color_with_your_moods']));
        $registry->register('chaos_003', new ChaosGrantExtraPlayEffect());
        $registry->register('chaos_004', new ChaosWentFirstValueEffect(false, 5));
        $registry->register('chaos_005', new ChaosTokenSpawnEffect(self::SMUGNESS_TOKEN));
        $registry->register('chaos_006', new Chaos006Effect());
        $registry->register('chaos_007', new ChaosActOnChosenPlayersMoodEffect('discard', 2, fn ($state, $c) => $state->valueOf($c) >= 5));
        $registry->register('chaos_008', new ChaosDiscardValueToBoostSelfEffect([0, 1, 2, 3], 5));
        $registry->register('chaos_009', new ChaosPairedColorThresholdEffect('red', 'black', 3));
        $registry->register('chaos_010', new Chaos010Effect());
        $registry->register('chaos_011', new Chaos011Effect());
        $registry->register('chaos_012', new Chaos012Effect());
        $registry->register('chaos_013', new ChaosGrantExtraPlayEffect(1, ['type' => 'base_value_in', 'values' => [0, 2, 4, 6]]));
        $registry->register('chaos_014', new Chaos014Effect());
        $registry->register('chaos_015', new Chaos015Effect());
        $registry->register('chaos_016', new Chaos016Effect());
        $registry->register('chaos_017', new ChaosGrantExtraPlayEffect(1, ['type' => 'base_value_in', 'values' => [1, 3, 5]]));
        $registry->register('chaos_018', new ChaosPairedColorThresholdEffect('green', 'blue', 6));
        $registry->register('chaos_019', new Chaos019Effect());
        $registry->register('chaos_020', new ChaosActOnChosenPlayersMoodEffect('suppress', 2));
        $registry->register('chaos_021', new ChaosPlayedThisRoundValueEffect(1));
        $registry->register('chaos_022', new Chaos022Effect());
        $registry->register('chaos_023', new Chaos023Effect());
        $registry->register('chaos_024', new Chaos024Effect());
        $registry->register('chaos_025', new Chaos025Effect());
        $registry->register('chaos_026', new Chaos026Effect());
        $registry->register('chaos_027', new ChaosPairedColorThresholdEffect('red', 'green', 3));
        $registry->register('chaos_028', new ChaosActOnChosenPlayersMoodEffect('hand', 2, fn ($state, $c) => $state->valueOf($c) % 2 === 1));
        $registry->register('chaos_029', new Chaos029Effect());
        $registry->register('chaos_030', new Chaos030Effect());
        $registry->register('chaos_031', new Chaos031Effect());
        $registry->register('chaos_032', new ChaosSimpleAfterPlayingEffect(fn ($state, $cardId, $playerId, $choices) => $state->drawCard($playerId)));
        $registry->register('chaos_033', new Chaos033Effect());
        $registry->register('chaos_034', new Chaos034Effect());
        $registry->register('chaos_035', new Chaos035Effect());
        $registry->register('chaos_036', new Chaos036Effect());
        $registry->register('chaos_037', new Chaos037Effect());
        $registry->register('chaos_038', new Chaos038Effect());
        $registry->register('chaos_039', new Chaos039Effect());
        $registry->register('chaos_040', new Chaos040Effect());
        $registry->register('chaos_041', new Chaos041Effect());
        $registry->register('chaos_042', new Chaos042Effect());
        $registry->register('chaos_043', new Chaos043Effect());
        $registry->register('chaos_044', new ChaosTokenSpawnEffect(self::UNCONCERN_TOKEN));
        $registry->register('chaos_045', new ChaosGrantExtraPlayEffect(1, ['onUseEffectState' => ['afterScoring' => ['action' => 'return_to_hand', 'condition' => 'always']]]));
        $registry->register('chaos_046', new Chaos046Effect());
        $registry->register('chaos_047', new ChaosPairedColorThresholdEffect('white', 'black', 6));
        $registry->register('chaos_048', new ChaosActOnChosenPlayersMoodEffect('hand', 2, excludeThisCard: true));
        $registry->register('chaos_049', new Chaos049Effect());
        $registry->register('chaos_050', new Chaos050Effect());
        $registry->register('chaos_051', new Chaos051Effect());
        $registry->register('chaos_052', new Chaos052Effect());
        $registry->register('chaos_053', new Chaos053Effect());
        $registry->register('chaos_054', new Chaos054Effect());
        $registry->register('chaos_055', new ChaosTokenSpawnEffect(self::PASSIVITY_TOKEN));
        $registry->register('chaos_056', new Chaos056Effect());
        $registry->register('chaos_057', new Chaos057Effect());
        $registry->register('chaos_058', new Chaos058Effect());
        $registry->register('chaos_059', new Chaos059Effect());
        $registry->register('chaos_060', new Chaos060Effect());
        $registry->register('chaos_061', new Chaos061Effect());
        $registry->register('chaos_062', new Chaos062Effect());
        $registry->register('chaos_063', new ChaosPairedColorThresholdEffect('green', 'white', 3));
        $registry->register('chaos_064', new Chaos064Effect());
        $registry->register('chaos_065', new ChaosGrantExtraPlayEffect(2, ['source' => 'discard']));
        $registry->register('chaos_066', new Chaos066Effect());
        $registry->register('chaos_067', new Chaos067Effect());
        $registry->register('chaos_068', new Chaos068Effect());
        $registry->register('chaos_069', new Chaos069Effect());
        $registry->register('chaos_070', new ChaosConditionalValueEffect(fn ($state, $c) => self::discardPileHasColorPair($state), 8));
        $registry->register('chaos_071', new Chaos071Effect());
        $registry->register('chaos_072', new ChaosPairedColorThresholdEffect('blue', 'red', 6));
        $registry->register('chaos_073', new Chaos073Effect());
        $registry->register('chaos_074', new ChaosAdditiveCountValueEffect(fn ($state, $c) => count($state->discardPile())));
        $registry->register('chaos_075', new Chaos075Effect());
        $registry->register('chaos_076', new ChaosActOnChosenPlayersMoodEffect('discard', 2, fn ($state, $c) => $state->valueOf($c) % 2 === 0));
        $registry->register('chaos_077', new ChaosConditionalValueEffect(fn ($state, $c) => self::hasMoreMoodsThanEachOther($state, $c), 7));
        $registry->register('chaos_078', new Chaos078Effect());
        $registry->register('chaos_079', new Chaos079Effect());
        $registry->register('chaos_080', new Chaos080Effect());
        $registry->register('chaos_081', new ChaosConditionalValueEffect(fn ($state, $c) => self::anyOpponentHasHandCountAtLeast($state, $c, 3), 5));
        $registry->register('chaos_082', new Chaos082Effect());
        $registry->register('chaos_083', new ChaosTokenSpawnEffect(self::TEDIUM_TOKEN));
        $registry->register('chaos_084', new Chaos084Effect());
        $registry->register('chaos_085', new Chaos085Effect());
        $registry->register('chaos_086', new Chaos086Effect());
        $registry->register('chaos_087', new ChaosDiscardValueToBoostSelfEffect([4, 5, 6], 5));
        $registry->register('chaos_088', new ChaosPairedColorThresholdEffect('black', 'green', 6));
        $registry->register('chaos_089', new Chaos089Effect());
        $registry->register('chaos_090', new ChaosPairedColorThresholdEffect('white', 'blue', 3));
        $registry->register('chaos_091', new Chaos091Effect());
        $registry->register('chaos_092', new ChaosPlayedThisRoundValueEffect(6));
        $registry->register('chaos_093', new ChaosGrantExtraPlayEffect(1, ['onUseEffectState' => ['afterScoring' => ['action' => 'discard', 'condition' => 'always']]]));
        $registry->register('chaos_094', new Chaos094Effect());
        $registry->register('chaos_095', new Chaos095Effect());
        $registry->register('chaos_096', new Chaos096Effect());
        $registry->register('chaos_097', new Chaos097Effect());
        $registry->register('chaos_098', new Chaos098Effect());
        $registry->register('chaos_099', new Chaos099Effect());
        $registry->register('chaos_100', new Chaos100Effect());
        $registry->register('chaos_101', new ChaosActOnChosenPlayersMoodEffect('discard', 2, fn ($state, $c) => $state->valueOf($c) <= 3));
        $registry->register('chaos_102', new Chaos102Effect());
        $registry->register('chaos_103', new Chaos103Effect());
        $registry->register('chaos_104', new ChaosWentFirstValueEffect(true, 5));
        $registry->register('chaos_105', new Chaos105Effect());
        $registry->register('chaos_106', new Chaos106Effect());
        $registry->register('chaos_107', new Chaos107Effect());
        $registry->register('chaos_108', new Chaos108Effect());
        $registry->register('chaos_109', new ChaosConditionalValueEffect(fn ($state, $c) => self::hasMoreColorsThanEachOther($state, $c), 7));
        $registry->register('chaos_110', new ChaosDiscardValueToBoostSelfEffect([0, 2, 4, 6], 5));
        $registry->register('chaos_111', new ChaosDiscardValueToBoostSelfEffect([1, 3, 5], 5));
        $registry->register('chaos_112', new ChaosConditionalValueEffect(fn ($state, $c) => self::threeOrMoreShareAColor($state), 6));
        $registry->register('chaos_113', new ChaosPairedColorThresholdEffect('blue', 'black', 3));
        $registry->register('chaos_114', new ChaosGrantExtraPlayEffect(1, ['type' => 'shares_color_with_your_moods']));
        $registry->register('chaos_115', new ChaosPairedColorThresholdEffect('red', 'white', 6));
        $registry->register('chaos_116', new Chaos116Effect());
        $registry->register('chaos_117', new ChaosAdditiveCountValueEffect(fn ($state, $c) => count($state->moodsInPlay())));
        $registry->register('chaos_118', new Chaos118Effect());
        $registry->register('chaos_119', new ChaosConditionalValueEffect(fn ($state, $c) => self::eachPlayerHasMoodCountAtLeast($state, 3), 7));
        $registry->register('chaos_120', new Chaos120Effect());
        $registry->register('chaos_121', new Chaos121Effect());
        $registry->register('chaos_122', new ChaosConditionalValueEffect(fn ($state, $c) => self::somePlayerHasBothColors($state, 'red', 'white'), 8));
        $registry->register('chaos_123', new ChaosGrantExtraPlayEffect(1, ['source' => 'discard']));
        $registry->register('chaos_124', new Chaos124Effect());
        $registry->register('chaos_125', new ChaosSimpleAfterPlayingEffect(fn ($state, $cardId, $playerId, $choices) => $state->bankExtraPlay($playerId, $cardId)));
        $registry->register('chaos_126', new ChaosTokenSpawnEffect(self::IDLENESS_TOKEN));
        $registry->register('chaos_127', new ChaosConditionalValueEffect(fn ($state, $c) => self::allFiveColorsPresent($state), 12));
        $registry->register('chaos_128', new Chaos128Effect());
        $registry->register('chaos_129', new ChaosConditionalValueEffect(fn ($state, $c) => count($state->moodsOwnedBy($state->ownerOf($c))) % 2 === 0, 6));
        $registry->register('chaos_130', new ChaosAdditiveCountValueEffect(fn ($state, $c) => count($state->hand($state->ownerOf($c)))));
        $registry->register('chaos_131', new ChaosConditionalValueEffect(fn ($state, $c) => count($state->moodsOwnedBy($state->ownerOf($c))) % 2 === 1, 6));
        $registry->register('chaos_132', new ChaosDiscardedThisRoundValueEffect(7));
        $registry->register('chaos_133', new Chaos133Effect());

        return $registry;
    }

    private static function discardPileHasColorPair(BoardState $state): bool
    {
        $counts = [];
        foreach ($state->discardPile() as $discardedCardId) {
            $color = $state->colorOf($discardedCardId);
            $counts[$color] = ($counts[$color] ?? 0) + 1;
            if ($counts[$color] >= 2) {
                return true;
            }
        }

        return false;
    }

    private static function hasMoreMoodsThanEachOther(BoardState $state, int $cardId): bool
    {
        $ownerId = $state->ownerOf($cardId);
        $ownCount = count($state->moodsOwnedBy($ownerId));
        foreach ($state->activePlayerOrder() as $otherPlayerId) {
            if ($otherPlayerId !== $ownerId && count($state->moodsOwnedBy($otherPlayerId)) >= $ownCount) {
                return false;
            }
        }

        return true;
    }

    private static function anyOpponentHasHandCountAtLeast(BoardState $state, int $cardId, int $threshold): bool
    {
        $ownerId = $state->ownerOf($cardId);
        foreach ($state->activePlayerOrder() as $otherPlayerId) {
            if ($otherPlayerId !== $ownerId && !$state->isTeammate($ownerId, $otherPlayerId) && count($state->hand($otherPlayerId)) >= $threshold) {
                return true;
            }
        }

        return false;
    }

    private static function hasMoreColorsThanEachOther(BoardState $state, int $cardId): bool
    {
        $ownerId = $state->ownerOf($cardId);
        $ownColors = self::distinctColorsFor($state, $ownerId);
        foreach ($state->activePlayerOrder() as $otherPlayerId) {
            if ($otherPlayerId !== $ownerId && self::distinctColorsFor($state, $otherPlayerId) >= $ownColors) {
                return false;
            }
        }

        return true;
    }

    private static function distinctColorsFor(BoardState $state, int $playerId): int
    {
        $colors = [];
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            $colors[$state->colorOf($mood->cardId)] = true;
        }

        return count($colors);
    }

    private static function threeOrMoreShareAColor(BoardState $state): bool
    {
        $counts = [];
        foreach ($state->moodsInPlay() as $mood) {
            $color = $state->colorOf($mood->cardId);
            $counts[$color] = ($counts[$color] ?? 0) + 1;
            if ($counts[$color] >= 3) {
                return true;
            }
        }

        return false;
    }

    private static function eachPlayerHasMoodCountAtLeast(BoardState $state, int $threshold): bool
    {
        foreach ($state->activePlayerOrder() as $playerId) {
            if (count($state->moodsOwnedBy($playerId)) < $threshold) {
                return false;
            }
        }

        return true;
    }

    private static function somePlayerHasBothColors(BoardState $state, string $colorA, string $colorB): bool
    {
        foreach ($state->activePlayerOrder() as $playerId) {
            $colors = [];
            foreach ($state->moodsOwnedBy($playerId) as $mood) {
                $colors[$state->colorOf($mood->cardId)] = true;
            }
            if (isset($colors[$colorA], $colors[$colorB])) {
                return true;
            }
        }

        return false;
    }

    private static function allFiveColorsPresent(BoardState $state): bool
    {
        $colors = [];
        foreach ($state->moodsInPlay() as $mood) {
            $colors[$state->colorOf($mood->cardId)] = true;
        }

        return count($colors) >= 5;
    }
}
