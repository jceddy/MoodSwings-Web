<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\Exceptions\EffectNotImplementedException;

/**
 * Looks up the ChaosMoodEffect implementation for a chaos_effects.effect_key
 * (issue #405) -- the exact same role EffectRegistry plays for a card's own
 * printed cards.effect_key, kept as a separate registry (rather than one
 * combined map) since the two key spaces are independent: 'chaos_042' and
 * an ordinary card's own effect_key like 'harmony' could otherwise collide
 * if a future chaos effect ever happened to reuse a card's own slug.
 *
 * Unlike EffectRegistry, an unregistered key here is expected during
 * incremental rollout (see the "implement all 133 effects" stage of issue
 * #405's own implementation) -- has() lets a caller check first rather
 * than needing to catch EffectNotImplementedException for every
 * not-yet-implemented effect while the pool is still being built out.
 */
final class ChaosEffectRegistry
{
    /** @var array<string, ChaosMoodEffect> */
    private array $effects = [];

    public function register(string $effectKey, ChaosMoodEffect $effect): void
    {
        $this->effects[$effectKey] = $effect;
    }

    public function has(string $effectKey): bool
    {
        return isset($this->effects[$effectKey]);
    }

    public function for(string $effectKey): ChaosMoodEffect
    {
        return $this->effects[$effectKey]
            ?? throw new EffectNotImplementedException(
                "No rules engine implementation registered for chaos effect_key '{$effectKey}'"
            );
    }
}
