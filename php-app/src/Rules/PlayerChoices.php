<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use MoodSwings\Rules\Exceptions\InvalidChoiceException;

/**
 * The choices a player submits alongside playing a mood: targets, chosen
 * colors/numbers, whether to pay an optional cost, etc. Card effects read
 * from this rather than declaring their own bespoke parameter lists, since
 * with 133 distinct effects each wanting different inputs, a single
 * loosely-typed bag of named values is far more tractable than a
 * dedicated method signature per card.
 *
 * This is deliberately a plain value bag with no "is this choice legal
 * for this card" validation -- each MoodEffect is responsible for
 * validating the choices it reads (see InvalidChoiceException), since
 * only the effect knows its own rules.
 */
final class PlayerChoices
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null;
    }

    public function int(string $key): ?int
    {
        return $this->has($key) ? (int) $this->values[$key] : null;
    }

    public function requireInt(string $key): int
    {
        return $this->int($key) ?? throw new InvalidChoiceException("Missing required choice '{$key}'");
    }

    /** @return int[] */
    public function ints(string $key): array
    {
        return array_map(intval(...), $this->values[$key] ?? []);
    }

    public function string(string $key): ?string
    {
        return $this->has($key) ? (string) $this->values[$key] : null;
    }

    public function requireString(string $key): string
    {
        return $this->string($key) ?? throw new InvalidChoiceException("Missing required choice '{$key}'");
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    /**
     * A nested choices bag stored under $key -- e.g. Duplicity repeating
     * another mood's after-playing effect needs to submit a *second* set
     * of choices for that repeat within the same request/same flat
     * top-level bag, since a card's own choices (like Dignity's specific
     * card to discard) usually can't be reused verbatim a second time.
     */
    public function sub(string $key): self
    {
        $values = $this->values[$key] ?? [];

        return new self(is_array($values) ? $values : []);
    }
}
