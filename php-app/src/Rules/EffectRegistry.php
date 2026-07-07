<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\Exceptions\EffectNotImplementedException;

/**
 * Looks up the MoodEffect implementation for a card's effect_key. See
 * DefaultEffectRegistry for how the built-in effects are wired up.
 */
final class EffectRegistry
{
    /** @var array<string, MoodEffect> */
    private array $effects = [];

    public function register(string $effectKey, MoodEffect $effect): void
    {
        $this->effects[$effectKey] = $effect;
    }

    public function has(string $effectKey): bool
    {
        return isset($this->effects[$effectKey]);
    }

    public function for(string $effectKey): MoodEffect
    {
        return $this->effects[$effectKey]
            ?? throw new EffectNotImplementedException(
                "No rules engine implementation registered for effect_key '{$effectKey}'"
            );
    }
}
