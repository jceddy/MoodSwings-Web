<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Issue #192 follow-up: describes what ONE firing of a registered "risky"
 * Chaos Draft effect actually does (spawn a token, draw a card, or
 * permanently boost a mood's value -- see BoardState::turnStateSignatureCoarse()'s
 * own docblock for why these specific three primitives defeat the
 * ordinary exact-signature loop detector), in a shape
 * GameService::applyChaosLoopShortcut() can apply N times in a single
 * step instead of trusting the player to keep re-triggering the
 * underlying loop manually N times.
 *
 * Each registered effect's own loopShortcut() implementation resolves
 * any needed random/reactive target ONCE (the same targeting logic that
 * effect's own onMoodPlayed()/onMoodDiscarded()/onMoodSuppressed() would
 * already use for a single firing -- see ChaosMoodEffect::loopShortcut()'s
 * own docblock) and bakes it into the returned descriptor, so applying
 * the shortcut never has to re-derive "which mood" or "for which player"
 * itself.
 */
final class ChaosLoopShortcut
{
    public const KIND_TOKEN = 'token';

    public const KIND_DRAW = 'draw';

    public const KIND_VALUE_BOOST = 'value_boost';

    /**
     * Every effect_key GameService/BoardState treat as "risky" for this
     * mechanism -- the complete set audited for issue #192's own Chaos
     * Draft follow-up. Kept as one canonical list (rather than each call
     * site re-enumerating them) so registering a future risky effect is a
     * one-line addition here plus that effect's own loopShortcut()/
     * registerChaosEffectFired() wiring, nothing else.
     */
    public const REGISTERED_EFFECT_KEYS = [
        'chaos_026',
        'chaos_037',
        'chaos_040',
        'chaos_089',
        'chaos_011',
        'chaos_024',
        'chaos_016',
        'chaos_120',
    ];

    private function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly int $cap,
        public readonly ?int $tokenCatalogCardId = null,
        public readonly ?int $forPlayerId = null,
        public readonly ?int $valueBoostTargetCardId = null,
    ) {
    }

    /** $forPlayerId is who the spawned tokens belong to (this effect's own owner, for every registered token effect). */
    public static function token(int $tokenCatalogCardId, int $forPlayerId, string $tokenName, int $cap = 16): self
    {
        return new self(
            kind: self::KIND_TOKEN,
            label: "Create up to {$cap} {$tokenName} tokens, then end your turn.",
            cap: $cap,
            tokenCatalogCardId: $tokenCatalogCardId,
            forPlayerId: $forPlayerId,
        );
    }

    /** $cap is the player's own current deck size (BoardState::deck() count) -- drawing more than that is simply impossible, so it's the natural, self-adjusting limit rather than a fixed constant. */
    public static function draw(int $forPlayerId, int $cap): self
    {
        return new self(
            kind: self::KIND_DRAW,
            label: 'Draw up to ' . $cap . ' cards. Your turn will continue.',
            cap: $cap,
            forPlayerId: $forPlayerId,
        );
    }

    /** $valueBoostTargetCardId is whichever mood the live effect's own targeting logic already resolved (see this class's own docblock). */
    public static function valueBoost(int $valueBoostTargetCardId, int $cap = 65536): self
    {
        return new self(
            kind: self::KIND_VALUE_BOOST,
            label: "Permanently boost this mood's value by up to {$cap}, then end your turn.",
            cap: $cap,
            valueBoostTargetCardId: $valueBoostTargetCardId,
        );
    }
}
