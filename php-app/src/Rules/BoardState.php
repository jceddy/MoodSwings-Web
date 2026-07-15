<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use InvalidArgumentException;

/**
 * The full state of one game needed to resolve card effects and compute
 * scores: whose hand/the deck/the discard pile has which cards, and which
 * cards are currently in play (and how). This is a pure in-memory model --
 * loading it from and persisting it back to the game_cards/game_players
 * tables is the job of a future GameService, deliberately kept separate so
 * the rules themselves can be unit tested without a database.
 *
 * Card values are never cached: valueOf() always computes fresh from the
 * current state, which is what makes the Extended Rules' resolution order
 * (apply "while in play" effects, then "after playing" effects, repeating
 * until stable) work for free -- there's no stale value to invalidate.
 */
final class BoardState
{
    /** @var array<int, int[]> playerId => hand card ids (per-game instance ids, not catalog ids -- see BoardState's own $catalogCardIdFor constructor param docblock), in no particular order */
    private array $hands;

    /**
     * The 'no specific owner' deck key -- always used for zone='deck'
     * game_cards rows in a shared-deck game (owner_game_player_id NULL in
     * the DB), and as $decks' only key whenever $hasSeparateDecks is
     * false. Safe as a sentinel since game_players.id is an
     * AUTO_INCREMENT PK starting at 1, so no real player id is ever 0.
     */
    public const SHARED_DECK_KEY = 0;

    /**
     * @var array<int, int[]> deck key => card ids (per-game instance ids),
     * index 0 is the top of that deck. Keyed by SHARED_DECK_KEY alone (a
     * single entry) for an ordinary game with one shared pool; keyed by
     * each seated player's own game_player_id (one entry per player) for a
     * 'duel' game, where every player draws only from -- and bottoms cards
     * only onto -- their own deck, independently built by the same
     * deck-building rules as a single-player deck (so the same catalog
     * card can appear in both players' decks at once -- see
     * $catalogCardIdFor). See $hasSeparateDecks and deckKeyFor().
     */
    private array $decks;

    /** @var int[] discard pile card ids (per-game instance ids); an unordered set -- no card in the game cares about discard-pile order. Always a single shared pool, even in a 'duel' game -- see $discardOwners. Since instance ids are unique per physical card even when two players' catalog cards match, this can now legitimately contain two entries for the same printed card without ambiguity. */
    private array $discard;

    /**
     * @var array<int, int> cardId (instance id) => the player this
     * discard-pile card most recently belonged to (whoever's hand it was
     * discarded from, or whoever owned it in play) -- tracked purely so a
     * card later put back on the bottom of the deck (Altruism/Corruption)
     * can be returned to that same player's own deck in a 'duel' game, per
     * $hasSeparateDecks, without the discard pile itself needing to become
     * per-player too (it stays a single shared, ownerless-looking pool --
     * discardPile()/isInDiscardPile() never expose this map). Cleared the
     * moment a card actually leaves the discard pile (see
     * removeFromDiscard()), so it never lingers stale once the card's true
     * owner could change again. Keyed by instance id rather than catalog
     * id specifically so two different players' identical printed cards in
     * the pile at once never collide.
     */
    private array $discardOwners;

    /** @var array<int, MoodInPlay> cardId (instance id) => the mood currently in play. Keyed by instance id rather than catalog id so two players' identical printed cards can both be in play simultaneously without one clobbering the other. */
    private array $moodsInPlay = [];

    private ?int $currentPlayerId = null;

    /** Whoever took the first turn of the current round (e.g. for Chivalry/Triumph) -- distinct from currentPlayerId, which changes every turn. */
    private ?int $roundFirstPlayerId = null;

    /** The current round's number (game_rounds.round_number), used to stamp newly-played moods with when they were played -- see moveHandToInPlay()/moveDiscardToInPlay() and the 'playedInRound' effectState key (Patience, Glee, Doubt). */
    private ?int $currentRoundNumber = null;

    /** Vulnerability: "this mood's value is 7 if a card was put into the discard pile this round." A single round-wide flag rather than anything card-specific, since it has to reflect any discard by any player -- see moveHandToDiscard()/moveInPlayToDiscard() and discardedThisRound(). */
    private bool $discardedThisRound = false;

    /** @var array<int, array<string, mixed>> cardId => effectState staged during payToPlayCost(), before the card exists as a MoodInPlay -- see stagePrePlayEffectState(). */
    private array $pendingEffectState = [];

    /**
     * @var array<int, ?array{type?: string, values?: int[], source?: string, onUseEffectState?: array<string, mixed>, sourceCardId?: int}> one entry per
     * outstanding "play an additional mood" grant this turn. null means
     * unconditional AND ungranted -- only ever startTurn()'s own base
     * allowance (1, or 2 with Hurt Feelings), never a card's grant; every
     * grantExtraPlay() call is instead a restriction array (even if
     * $restriction itself was omitted) once 'sourceCardId' is folded in,
     * since a granted extra play always has a card to attribute it to. A
     * restriction array's absent 'type' means the grant only covers a card
     * matching it (e.g. Benevolence's "if it doesn't share a color with
     * any of your moods") -- see grantAllows(). The 'onUseEffectState' key
     * (Gluttony/Insecurity) tags whichever specific card ends up consuming
     * this grant with effectState to apply once it's played -- see
     * useGrantFor() and MoodPlayService. 'sourceCardId' is purely a UI
     * reminder-text concern (see GameService::describePlayGrant()) --
     * grantAllows() itself never reads it.
     */
    private array $playGrants = [];

    /**
     * @var int[] card ids revealed by a purely random ($cardId chosen via
     * array_rand(), not any submitted choice) effect this play -- Paranoia/
     * Curiosity. Transient, never persisted by BoardStateRepository: it
     * exists only so GameService can read it back (consumeRevealedCardIds())
     * immediately before logging the play's own mood_played event, and fold
     * it into that event's details so a player who wasn't the one who
     * played the card can still find out what got revealed, e.g. via a
     * recent-plays panel. Not used for a card revealed by an explicit
     * choice (Doubt's own reveal, Intimidation's target's own answer) --
     * those are already visible in the play's own submitted choices/
     * pending-decision answer, already logged as-is.
     */
    private array $pendingRevealedCardIds = [];

    /**
     * @var array<int, array{card_id:int, from_zone:string, to_zone:string, from_player_id:?int, to_player_id:?int}>
     * Every zone transition recorded this play/response/scoring pass, so
     * GameService can fold them into whichever event it's about to log
     * (see consumeCardMoves()) -- this is what lets a card moved by a
     * random/effect-internal choice (Cruelty, Indecisiveness, Altruism) or
     * an opponent's own answer (Malice's color cascade, Disillusionment,
     * Suspicion) show up in game history exactly like a card named in the
     * acting player's own submitted choices already does, without every
     * effect class needing to log anything itself.
     *
     * Deliberately NOT recorded here: moveHandToInPlay()/moveDiscardToInPlay()
     * (always the card actually being played -- already implicit in the
     * mood_played/pending_decision_created event's own card_id, so adding
     * it again here would just repeat the same fact on every single play),
     * and drawCard() (unlike every other zone this class moves cards
     * through, a hand a card is DRAWN into was never previously public --
     * recording it would leak which card a player drew to every other
     * player's history, something no other move recorded here does, since
     * every other source zone -- play, the discard pile, or a hand a card
     * is LEAVING via an effect -- is either already public or already
     * loggable the same way a submitted choice already is).
     */
    private array $pendingCardMoves = [];

    /**
     * @var array<int, array{card_id:int, from_player_id:int, to_player_id:int}>
     * Every ownership change recorded this play/response/scoring pass, via
     * the same consume-before-logging convention as $pendingCardMoves --
     * see consumeOwnershipChanges(). A mood already sitting in play never
     * has anything hidden about who owns it, so unlike $pendingCardMoves
     * this needs no zone-based exceptions: every giveInPlayToPlayer() call
     * is recorded, whether it's a card's own effect (Guile, Instability,
     * Avoidance, Chaos), a temporary "give it back later" swap (Arrogance,
     * Betrayal, Recklessness), or that swap's own later reversal.
     */
    private array $pendingOwnershipChanges = [];

    /**
     * @var int[] One entry (the drawing player's id) per successful
     * drawCard() call this play/response/scoring pass -- deliberately just
     * the player id, never the card itself (see drawCard()'s own docblock
     * for why revealing which card was drawn would leak hidden hand
     * information no other recorded move does). Same consume-before-
     * logging convention as $pendingCardMoves/$pendingOwnershipChanges.
     */
    private array $pendingDraws = [];

    /**
     * @var array<int, ?array{type?: string, values?: int[], source?: string, sourceCardId?: int}>
     * One entry per unit of grantExtraPlay(), mirroring what's pushed onto
     * $playGrants itself -- lets GameService announce a newly granted
     * extra play (source/restriction/zone) the moment it's created, not
     * just once it's eventually used (see consumeGrantsCreated()).
     */
    private array $pendingGrantsCreated = [];

    /**
     * @var array<int, array{requiresSourceInPlay: true, sourceCardId: int}>
     * One entry per still-outstanding 'requiresSourceInPlay' grant (Hope's
     * or Grace's own -- see grantIsActive()'s own docblock) orphaned by
     * cascadeMoodLeavingPlay() because the specific card that created it
     * just left play before anyone got around to using it -- lets
     * GameService announce the loss in the game's event log instead of the
     * grant just silently going stale (playsRemaining() dropping by one
     * with no explanation), which otherwise reads as a bug report waiting
     * to happen. Never populated for an ordinary grant (Stubbornness's, a
     * banked Generosity/Joy grant, or the base allowance), since none of
     * those are tied to their source card's continued presence in the
     * first place. Same consume-before-logging convention as
     * $pendingGrantsCreated/$pendingGrantUsed above.
     */
    private array $pendingGrantsLost = [];

    /**
     * The restriction descriptor useGrantFor() most recently consumed, if
     * it was an actual granted extra play (not the ordinary null-
     * restriction base allowance every turn already starts with) -- lets
     * GameService say which grant a play used, alongside the usual
     * "played from hand" wording. A single nullable value, not a queue
     * like the others above: MoodPlayService calls useGrantFor() at most
     * once per top-level playMood() (a Duplicity repeat never consumes a
     * second grant), so there's never more than one to report before the
     * next consume.
     *
     * @var ?array{type?: string, values?: int[], source?: string, sourceCardId?: int}
     */
    private ?array $pendingGrantUsed = null;

    /**
     * @param array<int, array{color:string,rarity:string,baseValue:int,altValue:?int,effectKey:string,hasToPlay:bool,hasWhileInPlay:bool,hasAfterPlaying:bool,rulesText:string}> $catalog catalog card id (cards.id) => catalog row
     * @param int[] $playerOrder seat order (turn order) for this game
     * @param array<int, int[]> $hands playerId => hand card ids
     * @param int[]|array<int, int[]> $deck a flat card-id list (index 0 =
     *     top) for a single shared deck -- the common case, $hasSeparateDecks
     *     false -- or, when $hasSeparateDecks is true, a map of each
     *     seated player's own game_player_id to their own flat card-id
     *     list (see $decks).
     * @param int[] $discard card ids
     * @param bool $hasSeparateDecks true for a 'duel' game, where every
     *     player draws from (and bottoms cards onto) their own deck
     *     instead of one shared pool -- see deckKeyFor().
     * @param array<int, int> $discardOwners cardId => last-known owner,
     *     only ever consulted when $hasSeparateDecks is true -- see
     *     $discardOwners' own docblock.
     * @param array<int, int> $catalogCardIdFor every $cardId used
     *     throughout this class is really a per-game *instance* id
     *     (game_cards.id once loaded from a real game -- see
     *     BoardStateRepository), not a catalog id: since a 'duel' game
     *     gives each player their own complete deck, the same catalog card
     *     can legitimately exist twice in one game (once per player), so
     *     catalog id alone can no longer identify a specific physical
     *     card. This map resolves an instance id back to the catalog id
     *     whose printed data (name/color/value/rules text) it should use
     *     -- see catalogRow(). Left empty by every pure in-memory test
     *     that never supplies it: catalogRow() then falls back to treating
     *     $cardId as already being a catalog id, exactly preserving
     *     behavior for any game/test where instance and catalog id
     *     coincide (i.e. everything except a duel with a genuinely
     *     duplicated catalog card).
     * @param array<int, int> $teamIdByPlayer Open Team Play's own
     *     game_players.team_id, playerId => team_id -- empty for every
     *     other format (and every pre-team-format test), in which case
     *     isTeammate() always returns false, exactly preserving "every
     *     other player is an opponent" for those. See isTeammate()'s own
     *     docblock and php-app/README.md's "Open Team Play" section for
     *     which cards this actually changes.
     */
    public function __construct(
        private readonly array $catalog,
        private readonly EffectRegistry $registry,
        private readonly array $playerOrder,
        array $hands = [],
        array $deck = [],
        array $discard = [],
        private readonly bool $hasSeparateDecks = false,
        array $discardOwners = [],
        private readonly array $catalogCardIdFor = [],
        private readonly array $teamIdByPlayer = [],
    ) {
        $this->hands = $hands;
        $this->decks = $hasSeparateDecks ? $deck : [self::SHARED_DECK_KEY => $deck];
        $this->discard = $discard;
        $this->discardOwners = $discardOwners;
    }

    /**
     * Whether $a and $b are teammates in Open Team Play -- always false
     * for every non-team game (empty $teamIdByPlayer) and for a player
     * compared against themselves, so a bare "!== $ownerId"/"!== $playerId"
     * self-exclusion check elsewhere can be extended to also exclude a
     * teammate just by adding "&& !$state->isTeammate(...)" alongside it,
     * without needing its own format check. See php-app/README.md's "Open
     * Team Play" section for exactly which cards this changes (a
     * teammate isn't an "opponent" for cards phrased that way) and which
     * don't (a card phrased as "another player"/"choose a player" with no
     * "opponent" wording already included teammates before team format
     * existed, and still does).
     */
    public function isTeammate(int $a, int $b): bool
    {
        if ($a === $b) {
            return false;
        }

        return isset($this->teamIdByPlayer[$a], $this->teamIdByPlayer[$b])
            && $this->teamIdByPlayer[$a] === $this->teamIdByPlayer[$b];
    }

    /** @return array{color:string,rarity:string,baseValue:int,altValue:?int,effectKey:string,hasToPlay:bool,hasWhileInPlay:bool,hasAfterPlaying:bool,rulesText:string} */
    public function catalogRow(int $cardId): array
    {
        $catalogId = $this->catalogCardIdFor[$cardId] ?? $cardId;
        return $this->catalog[$catalogId] ?? throw new InvalidArgumentException("Unknown card id {$catalogId}");
    }

    /**
     * The catalog id (cards.id) $cardId's printed data resolves to -- see
     * catalogRow() and $catalogCardIdFor's own docblock. Exposed separately
     * so callers that need the catalog id itself (e.g. to look up a card's
     * art asset, which is keyed by cards.id -- see web-static/README.md's
     * "Assets" section) don't have to re-derive it from catalogRow().
     */
    public function catalogCardId(int $cardId): int
    {
        return $this->catalogCardIdFor[$cardId] ?? $cardId;
    }

    /** @return int[] */
    public function playerOrder(): array
    {
        return $this->playerOrder;
    }

    // --- zones ---

    /** @return int[] */
    public function hand(int $playerId): array
    {
        return $this->hands[$playerId] ?? [];
    }

    public function isInHand(int $playerId, int $cardId): bool
    {
        return in_array($cardId, $this->hands[$playerId] ?? [], true);
    }

    public function isInPlay(int $cardId): bool
    {
        return isset($this->moodsInPlay[$cardId]);
    }

    /** @return array<int, MoodInPlay> */
    public function moodsInPlay(): array
    {
        return $this->moodsInPlay;
    }

    /** @return array<int, MoodInPlay> */
    public function moodsOwnedBy(int $playerId): array
    {
        return array_filter($this->moodsInPlay, static fn (MoodInPlay $mood) => $mood->ownerId === $playerId);
    }

    public function ownerOf(int $cardId): int
    {
        return $this->moodInPlay($cardId)->ownerId;
    }

    /** @return int[] */
    public function discardPile(): array
    {
        return $this->discard;
    }

    public function isInDiscardPile(int $cardId): bool
    {
        return in_array($cardId, $this->discard, true);
    }

    /**
     * $playerId is required whenever $hasSeparateDecks (there's no single
     * "the deck" to hand back without knowing whose); omit it only for a
     * shared-deck game, where every player's own key resolves to the same
     * pool anyway. Throws if omitted for a 'duel' game, rather than
     * silently guessing which player's deck was meant.
     *
     * @return int[] card ids, index 0 = top of that deck
     */
    public function deck(?int $playerId = null): array
    {
        if ($playerId === null && $this->hasSeparateDecks) {
            throw new InvalidArgumentException('A player id is required to read a specific deck in a game with separate decks');
        }

        $key = $playerId !== null ? $this->deckKeyFor($playerId) : self::SHARED_DECK_KEY;

        return $this->decks[$key] ?? [];
    }

    /**
     * Every deck, keyed the same way $decks itself is -- SHARED_DECK_KEY
     * alone for a shared-deck game, or each seated player's own
     * game_player_id for a 'duel' game. Exists purely for
     * BoardStateRepository::save() to persist every deck regardless of
     * how many there are; ordinary rules-engine code should use deck()/
     * drawCard()/the moveXToBottomOfDeck() family instead, which already
     * resolve the right one for a given player.
     *
     * @return array<int, int[]>
     */
    public function decks(): array
    {
        return $this->decks;
    }

    public function hasSeparateDecks(): bool
    {
        return $this->hasSeparateDecks;
    }

    /** The last-known owner of a card currently sitting in the discard pile, if tracked (see $discardOwners) -- only meaningful, and only ever consulted, in a 'duel' game. */
    public function discardOwnerOf(int $cardId): ?int
    {
        return $this->discardOwners[$cardId] ?? null;
    }

    /** Which $decks key $playerId's own deck lives under -- their own game_player_id in a 'duel' game, or the single shared key otherwise. */
    private function deckKeyFor(int $playerId): int
    {
        return $this->hasSeparateDecks ? $playerId : self::SHARED_DECK_KEY;
    }

    private function moodInPlay(int $cardId): MoodInPlay
    {
        return $this->moodsInPlay[$cardId] ?? throw new InvalidArgumentException("Card {$cardId} is not in play");
    }

    // --- movement between zones ---

    public function moveHandToInPlay(int $playerId, int $cardId, ?int $copiedCardId = null): void
    {
        $this->removeFromHand($playerId, $cardId);
        $this->moodsInPlay[$cardId] = new MoodInPlay($cardId, $playerId, $copiedCardId, effectState: $this->initialEffectState($cardId, 'hand', $playerId));
    }

    /** Harmony/Grief/Angst: plays a mood "from the discard pile" instead of from hand -- see BoardState::$playGrants' 'source' key and MoodPlayService. */
    public function moveDiscardToInPlay(int $playerId, int $cardId, ?int $copiedCardId = null): void
    {
        $this->removeFromDiscard($cardId);
        $this->moodsInPlay[$cardId] = new MoodInPlay($cardId, $playerId, $copiedCardId, effectState: $this->initialEffectState($cardId, 'discard', $playerId));
        // The card just became $playerId's own in-play mood, no longer a
        // discard-pile card with a "last owner" of its own -- see
        // removeFromDiscard(), which already cleared any stale entry.
    }

    /**
     * 'playedFromZone' persists (unlike $pendingCardMoves/$pendingRevealedCardIds,
     * which only ever need to survive to the end of the single request that
     * fired them) as ordinary effectState -- GameService reads it back to
     * say e.g. "played Harmony from discard" even for a play that pauses on
     * a RequiresOpponentDecision and only actually finishes several requests
     * later, once the mood is long since already sitting in play.
     *
     * @return array<string, mixed>
     */
    private function initialEffectState(int $cardId, string $fromZone, int $playerId): array
    {
        return ['playedFromZone' => $fromZone, ...$this->playedInRoundTag($playerId), ...$this->consumeStagedEffectState($cardId)];
    }

    /**
     * Patience/Glee: "this mood's value is 1/6 if you played it this
     * round" -- "you" means whoever *currently* owns it, so this tags both
     * which round it was played in AND who played it (PlayedThisRoundValueEffect
     * checks both against the round-in-progress and the mood's current
     * owner). Ownership can change after being played (Guile/Instability/
     * Betrayal/Recklessness/Arrogance/Avoidance/Chaos all reassign a
     * mood's owner via giveInPlayToPlayer() without re-playing it -- see
     * ChaosEffect's own docblock, "moods may change players, but their
     * after-playing effects don't happen again"), so the original player
     * has to be remembered separately from the (mutable) current owner,
     * not re-derived from it.
     *
     * @return array<string, mixed>
     */
    private function playedInRoundTag(int $playerId): array
    {
        return $this->currentRoundNumber !== null
            ? ['playedInRound' => $this->currentRoundNumber, 'playedByPlayerId' => $playerId]
            : [];
    }

    /**
     * Stashes an effectState key/value for $cardId to apply the moment it
     * actually enters play -- for a "to play this card" cost that needs to
     * capture something (e.g. Bliss recording the color of the card its
     * cost discards) before the card exists as a MoodInPlay to attach
     * effectState to normally; payToPlayCost() always runs before the
     * card is moved into play. Consumed (and cleared) by
     * moveHandToInPlay()/moveDiscardToInPlay() within that same call, so
     * nothing here needs to survive being reloaded from the database.
     */
    public function stagePrePlayEffectState(int $cardId, string $key, mixed $value): void
    {
        $this->pendingEffectState[$cardId][$key] = $value;
    }

    /** @return array<string, mixed> */
    private function consumeStagedEffectState(int $cardId): array
    {
        $staged = $this->pendingEffectState[$cardId] ?? [];
        unset($this->pendingEffectState[$cardId]);

        return $staged;
    }

    public function moveInPlayToDiscard(int $cardId): void
    {
        $this->discardOwners[$cardId] = $this->moodInPlay($cardId)->ownerId;
        unset($this->moodsInPlay[$cardId]);
        $this->discard[] = $cardId;
        $this->discardedThisRound = true;
        $this->recordMove($cardId, 'play', 'discard');
        $this->cascadeMoodLeavingPlay($cardId);
    }

    public function moveInPlayToHand(int $cardId): void
    {
        $owner = $this->moodInPlay($cardId)->ownerId;
        unset($this->moodsInPlay[$cardId]);
        $this->hands[$owner][] = $cardId;
        $this->recordMove($cardId, 'play', 'hand', toPlayerId: $owner);
        $this->cascadeMoodLeavingPlay($cardId);
    }

    /** Regret: takes a mood directly into $newOwnerId's hand regardless of who actually owns it -- distinct from moveInPlayToHand(), which always returns a mood to its own owner's hand. */
    public function moveInPlayToPlayersHand(int $cardId, int $newOwnerId): void
    {
        $this->moodInPlay($cardId);
        unset($this->moodsInPlay[$cardId]);
        $this->hands[$newOwnerId][] = $cardId;
        $this->recordMove($cardId, 'play', 'hand', toPlayerId: $newOwnerId);
        $this->cascadeMoodLeavingPlay($cardId);
    }

    public function moveInPlayToBottomOfDeck(int $cardId): void
    {
        // Read before unset() -- the mood's own current owner is whose
        // deck this bottoms onto in a 'duel' game (see deckKeyFor()); no
        // separate parameter needed since an in-play mood's owner is
        // already always tracked.
        $ownerId = $this->moodInPlay($cardId)->ownerId;
        unset($this->moodsInPlay[$cardId]);
        $this->decks[$this->deckKeyFor($ownerId)][] = $cardId;
        $this->recordMove($cardId, 'play', 'deck');
        $this->cascadeMoodLeavingPlay($cardId);
    }

    /**
     * Runs whenever $cardId itself (not just some other mood it suppressed
     * or stole) leaves play: lifts any suppression it was the source of
     * (see clearSuppressionsFrom()) and returns any mood tagged as "give
     * this back if you still have it when I leave play" (Arrogance) to its
     * original owner, provided the Arrogance player still actually holds
     * it -- see ArrogranceEffect. Also records (see $pendingGrantsLost) any
     * still-outstanding 'requiresSourceInPlay' grant $cardId was
     * responsible for -- grantIsActive() would silently start reading it
     * as inactive the instant $cardId is gone from $moodsInPlay (already
     * true by the time every caller gets here), so this is the one place
     * that can still tell "used" apart from "just went stale" while it
     * still matters.
     */
    private function cascadeMoodLeavingPlay(int $cardId): void
    {
        $this->clearSuppressionsFrom($cardId);

        foreach ($this->playGrants as $restriction) {
            if (($restriction['requiresSourceInPlay'] ?? false) && $restriction['sourceCardId'] === $cardId) {
                $this->pendingGrantsLost[] = $restriction;
            }
        }

        foreach ($this->moodsInPlay as $mood) {
            $stolen = $mood->effectState['returnsToOwnerIfCardLeavesPlay'] ?? null;
            if ($stolen === null || $stolen['sourceCardId'] !== $cardId) {
                continue;
            }

            $this->clearEffectState($mood->cardId, 'returnsToOwnerIfCardLeavesPlay');
            if ($mood->ownerId === $stolen['heldByPlayerId']) {
                $this->giveInPlayToPlayer($mood->cardId, $stolen['ownerId']);
            }
        }
    }

    public function moveHandToDiscard(int $playerId, int $cardId): void
    {
        $this->removeFromHand($playerId, $cardId);
        $this->discard[] = $cardId;
        $this->discardOwners[$cardId] = $playerId;
        $this->discardedThisRound = true;
        $this->recordMove($cardId, 'hand', 'discard', fromPlayerId: $playerId);
    }

    public function moveHandToBottomOfDeck(int $playerId, int $cardId): void
    {
        $this->removeFromHand($playerId, $cardId);
        $this->decks[$this->deckKeyFor($playerId)][] = $cardId;
        $this->recordMove($cardId, 'hand', 'deck', fromPlayerId: $playerId);
    }

    public function moveDiscardToHand(int $playerId, int $cardId): void
    {
        $this->removeFromDiscard($cardId);
        $this->hands[$playerId][] = $cardId;
        $this->recordMove($cardId, 'discard', 'hand', toPlayerId: $playerId);
    }

    /**
     * Altruism/Corruption: puts a discard-pile card on the bottom of the
     * deck. In a 'duel' game this goes to that specific card's own
     * $discardOwners entry -- the same player whose deck it would already
     * be sitting in if the discard pile were per-player too, per the
     * ruling this codebase follows (the discard pile itself stays a
     * single shared pool; only where a card lands once it leaves it is
     * player-scoped) -- never the acting player's deck, which is why
     * neither caller passes a player id here at all.
     */
    public function moveDiscardToBottomOfDeck(int $cardId): void
    {
        $ownerId = $this->discardOwners[$cardId] ?? null;
        $this->removeFromDiscard($cardId);
        $key = $ownerId !== null ? $this->deckKeyFor($ownerId) : self::SHARED_DECK_KEY;
        $this->decks[$key][] = $cardId;
        $this->recordMove($cardId, 'discard', 'deck');
    }

    public function giveInPlayToPlayer(int $cardId, int $newOwnerId): void
    {
        $oldOwnerId = $this->moodInPlay($cardId)->ownerId;
        $this->moodInPlay($cardId)->ownerId = $newOwnerId;
        $this->recordOwnershipChange($cardId, $oldOwnerId, $newOwnerId);
    }

    /** Fascination: hands a card directly from one player's hand to another's (e.g. "give it to another player"), rather than routing it through discard/deck. */
    public function giveHandCardToPlayer(int $fromPlayerId, int $toPlayerId, int $cardId): void
    {
        $this->removeFromHand($fromPlayerId, $cardId);
        $this->hands[$toPlayerId][] = $cardId;
        $this->recordMove($cardId, 'hand', 'hand', fromPlayerId: $fromPlayerId, toPlayerId: $toPlayerId);
    }

    public function drawCard(int $playerId): ?int
    {
        $key = $this->deckKeyFor($playerId);
        $cardId = isset($this->decks[$key]) ? array_shift($this->decks[$key]) : null;
        if ($cardId === null) {
            return null;
        }
        $this->hands[$playerId][] = $cardId;
        $this->pendingDraws[] = $playerId;

        return $cardId;
    }

    /**
     * Returns and clears every player id recorded via drawCard() since the
     * last call -- see $pendingDraws' own docblock. Same consume-before-
     * logging convention as consumeCardMoves()/consumeOwnershipChanges().
     *
     * @return int[]
     */
    public function consumeDraws(): array
    {
        $draws = $this->pendingDraws;
        $this->pendingDraws = [];

        return $draws;
    }

    private function removeFromHand(int $playerId, int $cardId): void
    {
        $hand = $this->hands[$playerId] ?? [];
        $index = array_search($cardId, $hand, true);
        if ($index === false) {
            throw new InvalidArgumentException("Card {$cardId} is not in player {$playerId}'s hand");
        }
        unset($hand[$index]);
        $this->hands[$playerId] = array_values($hand);
    }

    private function removeFromDiscard(int $cardId): void
    {
        $index = array_search($cardId, $this->discard, true);
        if ($index === false) {
            throw new InvalidArgumentException("Card {$cardId} is not in the discard pile");
        }
        unset($this->discard[$index]);
        $this->discard = array_values($this->discard);
        unset($this->discardOwners[$cardId]);
    }

    // --- persistence hydration ---
    //
    // These two methods exist for a GameService/repository to reconstruct
    // a BoardState exactly as it was persisted, bypassing the normal
    // hand-to-play or fresh-turn transitions (which don't apply when the
    // mood is already in play, or the turn is already partway through, by
    // the time a request loads the state back from the database).

    /** @param array<string, mixed> $effectState */
    public function restoreMoodInPlay(
        int $cardId,
        int $ownerId,
        ?int $copiedCardId,
        bool $isSuppressed,
        ?string $suppressionExpiry,
        ?int $suppressionSourceCardId,
        array $effectState,
    ): void {
        $this->moodsInPlay[$cardId] = new MoodInPlay(
            $cardId,
            $ownerId,
            $copiedCardId,
            $isSuppressed,
            $suppressionExpiry,
            $suppressionSourceCardId,
            $effectState,
        );
    }

    /** @param array<int, ?array{type?: string, values?: int[], source?: string, onUseEffectState?: array<string, mixed>, sourceCardId?: int, requiresSourceInPlay?: bool}> $playGrants */
    public function restoreTurnState(?int $currentPlayerId, array $playGrants, ?int $roundFirstPlayerId, ?int $currentRoundNumber = null, bool $discardedThisRound = false): void
    {
        $this->currentPlayerId = $currentPlayerId;
        $this->playGrants = $playGrants;
        $this->roundFirstPlayerId = $roundFirstPlayerId;
        $this->currentRoundNumber = $currentRoundNumber;
        $this->discardedThisRound = $discardedThisRound;
    }

    /** Vulnerability: whether any card has been put into the discard pile so far this round -- see moveHandToDiscard()/moveInPlayToDiscard(). */
    public function discardedThisRound(): bool
    {
        return $this->discardedThisRound;
    }

    // --- suppression ---

    public function suppress(int $cardId, string $expiry, ?int $sourceCardId = null): void
    {
        $mood = $this->moodInPlay($cardId);
        $mood->isSuppressed = true;
        $mood->suppressionExpiry = $expiry;
        $mood->suppressionSourceCardId = $sourceCardId;
    }

    public function isSuppressed(int $cardId): bool
    {
        return $this->moodInPlay($cardId)->isSuppressed;
    }

    /**
     * The reverse of a suppressed mood's own suppressionSourceCardId --
     * every mood currently suppressed *by* $sourceCardId. Purely a UI
     * reminder-text lookup (see GameService's "affecting" field on each
     * in-play card's serialization): the target's own suppressionSourceCardId
     * already drives the actual suppression, this just answers it from the
     * source's side.
     *
     * @return int[]
     */
    public function suppressedByCardId(int $sourceCardId): array
    {
        $result = [];
        foreach ($this->moodsInPlay as $cardId => $mood) {
            if ($mood->isSuppressed && $mood->suppressionSourceCardId === $sourceCardId) {
                $result[] = $cardId;
            }
        }

        return $result;
    }

    /** See $pendingRevealedCardIds' own docblock. Called by Paranoia/Curiosity at the point they pick their random hand card. */
    public function recordRevealedCard(int $cardId): void
    {
        $this->pendingRevealedCardIds[] = $cardId;
    }

    /**
     * Returns and clears every card id recorded via recordRevealedCard()
     * since the last call -- GameService calls this immediately before
     * logging a play's own mood_played event, so it's always scoped to
     * exactly the play (or Duplicity-repeated plays) that just resolved.
     *
     * @return int[]
     */
    public function consumeRevealedCardIds(): array
    {
        $ids = $this->pendingRevealedCardIds;
        $this->pendingRevealedCardIds = [];

        return $ids;
    }

    private function recordMove(int $cardId, string $fromZone, string $toZone, ?int $fromPlayerId = null, ?int $toPlayerId = null): void
    {
        $this->pendingCardMoves[] = [
            'card_id' => $cardId,
            'from_zone' => $fromZone,
            'to_zone' => $toZone,
            'from_player_id' => $fromPlayerId,
            'to_player_id' => $toPlayerId,
        ];
    }

    /**
     * Returns and clears every move recorded via recordMove() since the
     * last call -- see $pendingCardMoves' own docblock. Mirrors
     * consumeRevealedCardIds()'s own call convention: GameService calls
     * this immediately before logging whichever event this play/response/
     * scoring pass is about to produce.
     *
     * @return array<int, array{card_id:int, from_zone:string, to_zone:string, from_player_id:?int, to_player_id:?int}>
     */
    public function consumeCardMoves(): array
    {
        $moves = $this->pendingCardMoves;
        $this->pendingCardMoves = [];

        return $moves;
    }

    private function recordOwnershipChange(int $cardId, int $fromPlayerId, int $toPlayerId): void
    {
        $this->pendingOwnershipChanges[] = [
            'card_id' => $cardId,
            'from_player_id' => $fromPlayerId,
            'to_player_id' => $toPlayerId,
        ];
    }

    /**
     * Returns and clears every ownership change recorded via
     * giveInPlayToPlayer() since the last call -- see
     * $pendingOwnershipChanges' own docblock. Same consume-before-logging
     * convention as consumeCardMoves()/consumeRevealedCardIds().
     *
     * @return array<int, array{card_id:int, from_player_id:int, to_player_id:int}>
     */
    public function consumeOwnershipChanges(): array
    {
        $changes = $this->pendingOwnershipChanges;
        $this->pendingOwnershipChanges = [];

        return $changes;
    }

    /** Clears every suppression whose source is $sourceCardId (e.g. that mood left play). */
    public function clearSuppressionsFrom(int $sourceCardId): void
    {
        foreach ($this->moodsInPlay as $mood) {
            if ($mood->suppressionSourceCardId === $sourceCardId) {
                $mood->isSuppressed = false;
                $mood->suppressionExpiry = null;
                $mood->suppressionSourceCardId = null;
            }
        }
    }

    /** Clears every suppression that only lasts "until the end of this round". */
    public function clearEndOfRoundSuppressions(): void
    {
        foreach ($this->moodsInPlay as $mood) {
            if ($mood->suppressionExpiry === 'end_of_round') {
                $mood->isSuppressed = false;
                $mood->suppressionExpiry = null;
                $mood->suppressionSourceCardId = null;
            }
        }
    }

    // --- per-mood effect state (modal choices, one-time value overrides) ---

    public function setEffectState(int $cardId, string $key, mixed $value): void
    {
        $this->moodInPlay($cardId)->effectState[$key] = $value;
    }

    public function effectState(int $cardId, string $key): mixed
    {
        return $this->moodsInPlay[$cardId]->effectState[$key] ?? null;
    }

    /** Unsets a specific effectState key so a one-shot marker (e.g. an after-scoring tag) doesn't reapply next round once it's been resolved. */
    public function clearEffectState(int $cardId, string $key): void
    {
        unset($this->moodInPlay($cardId)->effectState[$key]);
    }

    /** A one-time score change from an "after playing" effect (e.g. Dignity's "value becomes 5"), as opposed to a continuously recomputed "while in play" value. */
    public function setValueOverride(int $cardId, int $value): void
    {
        $this->setEffectState($cardId, 'valueOverride', $value);
    }

    // --- effective identity, color, and value ---

    /** Creativity plays as a copy of another card; every other mood is just itself. */
    public function effectiveCardId(int $cardId): int
    {
        return $this->moodsInPlay[$cardId]->copiedCardId ?? $cardId;
    }

    /**
     * A mood's color, honoring Imagination ("While in play, all moods are
     * the chosen color and no other colors") if one is in play. Imagination
     * itself never changes a card's *printed* color, only what other
     * color-counting effects perceive -- see the catalog row's own color
     * for the printed value.
     */
    public function colorOf(int $cardId): string
    {
        foreach ($this->moodsInPlay as $mood) {
            $row = $this->catalogRow($this->effectiveCardId($mood->cardId));
            if ($row['effectKey'] === 'imagination') {
                $color = $mood->effectState['color'] ?? null;
                if ($color !== null) {
                    return $color;
                }
            }
        }

        return $this->catalogRow($this->effectiveCardId($cardId))['color'];
    }

    /**
     * This mood's current score value: 0 if suppressed, its stored
     * one-time override if it has one, its live "while in play"
     * computation if it has that ability, otherwise its flat base value.
     */
    public function valueOf(int $cardId): int
    {
        $mood = $this->moodInPlay($cardId);
        if ($mood->isSuppressed) {
            return 0;
        }
        if (array_key_exists('valueOverride', $mood->effectState)) {
            return $mood->effectState['valueOverride'];
        }

        $row = $this->catalogRow($this->effectiveCardId($cardId));

        // Encouragement/Idealism: "uses the dice total in its top right
        // corner or lower left corner, whichever is higher, to determine
        // its value" -- alt_value is the printed "dice" number, so this
        // overrides the card's own computed value entirely (rather than
        // just adding to it) for as long as either card applies.
        if ($row['altValue'] !== null && $this->diceValueBoosterCardId($cardId) !== null) {
            return max($row['baseValue'], $row['altValue']);
        }

        if ($row['hasWhileInPlay']) {
            return $this->registry->for($row['effectKey'])->computeValue($this, $cardId);
        }

        return $row['baseValue'];
    }

    /**
     * What $targetCardId's value would be if $hypotheticalCardId (still in
     * hand/discard, about to be played by $hypotheticalOwnerId) were
     * already in play alongside everything actually in play right now.
     *
     * Needed because a still-in-hand card's own choice_fields (see
     * GameService::serializeCard()) sometimes filter targets by value --
     * e.g. Shock's "value of 3 or less" -- and a target's dynamic value
     * (Ambivalence's "3 if there are two or more red and/or green moods,"
     * or any other PairedColorThresholdEffect/computeValue() that scans
     * moodsInPlay()) can depend on the very card being played: playing a
     * red Shock alongside an already-in-play green Joy tips Ambivalence's
     * own red-or-green count from 1 to 2, dropping its value to 3 -- but
     * only once Shock is *actually* in play, which MoodPlayService only
     * does moments before calling ShockEffect::afterPlaying() (by which
     * point $this correctly reflects it -- see moveHandToInPlay()'s own
     * call site). Without this, a target that only qualifies once the
     * played card counts would look ineligible right up until the instant
     * it's actually played, blocking the choice_fields UI from ever
     * offering it even though the server would accept it.
     *
     * Never mutates $this beyond the scope of this one call: adds a
     * throwaway MoodInPlay for $hypotheticalCardId, computes, then removes
     * it again (a try/finally, so an exception mid-computation can't leave
     * the phantom entry behind).
     */
    public function valueOfAsIfAlsoInPlay(int $targetCardId, int $hypotheticalCardId, int $hypotheticalOwnerId): int
    {
        if (isset($this->moodsInPlay[$hypotheticalCardId])) {
            // Already actually in play (e.g. Creativity copying a card
            // that's also the one being evaluated) -- valueOf() already
            // reflects it, no phantom needed.
            return $this->valueOf($targetCardId);
        }

        $this->moodsInPlay[$hypotheticalCardId] = new MoodInPlay($hypotheticalCardId, $hypotheticalOwnerId);
        try {
            return $this->valueOf($targetCardId);
        } finally {
            unset($this->moodsInPlay[$hypotheticalCardId]);
        }
    }

    /**
     * The card id of the Encouragement or Idealism currently applying
     * $cardId's dice value, or null if neither currently does. Encouragement
     * tags one specific chosen mood (its 'boostedMoodId' effectState key);
     * Idealism blankets every mood its owner controls. A mood without a
     * printed alt_value ("dice") is never affected by either, regardless of
     * whether it's targeted/owned, but this method doesn't check that
     * itself -- valueOf() only calls it once it's already confirmed $cardId
     * has one, and GameService's "affecting"/"affected by" reminder-text
     * lookup (see its in_play serialization) checks has_dice_value
     * separately before showing anything either direction.
     */
    public function diceValueBoosterCardId(int $cardId): ?int
    {
        $ownerId = $this->moodInPlay($cardId)->ownerId;
        foreach ($this->moodsInPlay as $boosterCardId => $mood) {
            $effectKey = $this->catalogRow($this->effectiveCardId($mood->cardId))['effectKey'];
            if ($effectKey === 'encouragement' && ($mood->effectState['boostedMoodId'] ?? null) === $cardId) {
                return $boosterCardId;
            }
            if ($effectKey === 'idealism' && $mood->ownerId === $ownerId) {
                return $boosterCardId;
            }
        }

        return null;
    }

    // --- turn / plays-remaining ---

    public function currentPlayerId(): ?int
    {
        return $this->currentPlayerId;
    }

    public function startTurn(int $playerId, bool $hasHurtFeelings = false): void
    {
        $this->currentPlayerId = $playerId;
        $this->playGrants = array_fill(0, $hasHurtFeelings ? 2 : 1, null);
    }

    /**
     * Marks $playerId as whoever took the first turn of the current round
     * (e.g. for Chivalry/Triumph) -- called once per round, unlike
     * startTurn() which happens every turn. $roundNumber is optional since
     * most tests don't care about it; production code instead gets it via
     * restoreTurnState(), since this method is never called outside tests
     * (GameService/BoardStateRepository populate round state directly from
     * the database).
     */
    public function startRound(int $playerId, ?int $roundNumber = null): void
    {
        $this->roundFirstPlayerId = $playerId;
        $this->currentRoundNumber = $roundNumber;
    }

    public function currentRoundNumber(): ?int
    {
        return $this->currentRoundNumber;
    }

    /**
     * Colors banned from being played this round by any player, per Doubt's
     * "during the next round, players can't play moods that share a color
     * with any of the revealed cards" -- active for exactly the round
     * immediately after Doubt's own 'playedInRound', using the same tag
     * moveHandToInPlay()/moveDiscardToInPlay() stamp on every mood (see
     * DoubtEffect).
     *
     * @return string[]
     */
    public function bannedColorsThisRound(): array
    {
        $banned = [];
        foreach ($this->moodsInPlay as $mood) {
            $bannedColors = $mood->effectState['bannedColors'] ?? null;
            $playedInRound = $mood->effectState['playedInRound'] ?? null;
            if ($bannedColors !== null && $playedInRound !== null && $this->currentRoundNumber === $playedInRound + 1) {
                array_push($banned, ...$bannedColors);
            }
        }

        return array_values(array_unique($banned));
    }

    public function roundFirstPlayerId(): ?int
    {
        return $this->roundFirstPlayerId;
    }

    /**
     * Whoever should go first *next* round, per a currently-in-play mood
     * that overrides the normal "round winner goes first" rule. Two
     * distinct effectState keys feed this, since they have different
     * lifetimes: Honor's 'firstPlayerOverride' is perpetual -- "while in
     * play, the chosen player goes first each round" -- so it's simply
     * never cleared, and is automatically self-correcting if Honor later
     * leaves play, with nothing separate to clean up. Awe's
     * 'oneTimeFirstPlayerOverride' only covers the round immediately after
     * it's played ("you choose which player goes first next round"), so
     * GameService::skipScoringAndAdvance() explicitly clears it once
     * consumed -- otherwise Awe staying in play would keep overriding
     * every future round too, which its text doesn't support. GameService
     * checks this when starting a new round; the first override found
     * wins (no defined tie-break if both happen to be in play at once).
     */
    public function firstPlayerOverride(): ?int
    {
        foreach ($this->moodsInPlay as $mood) {
            if (array_key_exists('firstPlayerOverride', $mood->effectState)) {
                return $mood->effectState['firstPlayerOverride'];
            }
            if (array_key_exists('oneTimeFirstPlayerOverride', $mood->effectState)) {
                return $mood->effectState['oneTimeFirstPlayerOverride'];
            }
        }

        return null;
    }

    /**
     * Grants $count additional plays this turn. $restriction is null for
     * an otherwise-unconditional grant (e.g. Charity); otherwise it's a
     * descriptor (see grantAllows()) that whatever card is played to use
     * this grant must satisfy -- e.g. Benevolence grants
     * ['type' => 'does_not_share_color_with_your_moods']. $sourceCardId is
     * every effect's own $cardId, folded into the stored descriptor as
     * 'sourceCardId' regardless of whether $restriction itself was given,
     * purely so GameService can later name which card is responsible for
     * this specific outstanding play (see describePlayGrant()) -- the one
     * caller that doesn't pass it is BoardStateTest's own generic
     * "does a grant exist at all" test, and startTurn()'s own base
     * allowance is never granted through this method at all. A
     * restriction may also carry 'requiresSourceInPlay' => true (Hope's
     * and Grace's own same-turn bonus) -- see grantIsActive()'s own
     * docblock for what that does.
     *
     * @param ?array{type?: string, values?: int[], source?: string, requiresSourceInPlay?: bool} $restriction
     */
    public function grantExtraPlay(int $count = 1, ?array $restriction = null, ?int $sourceCardId = null): void
    {
        if ($sourceCardId !== null) {
            $restriction = [...($restriction ?? []), 'sourceCardId' => $sourceCardId];
        }

        for ($i = 0; $i < $count; $i++) {
            $this->playGrants[] = $restriction;
            $this->pendingGrantsCreated[] = $restriction;
        }
    }

    /**
     * Returns and clears every restriction descriptor recorded via
     * grantExtraPlay() since the last call -- see $pendingGrantsCreated'
     * own docblock. Same consume-before-logging convention as
     * consumeCardMoves()/consumeOwnershipChanges()/consumeDraws().
     * Deliberately never populated by computeFreshGrants()'s own
     * perpetual (Hope/Grace/Stubbornness) or banked (Generosity/Joy)
     * recomputation at the start of a future turn, since those bypass
     * this method entirely -- see GameService::computeFreshGrants().
     *
     * @return array<int, ?array{type?: string, values?: int[], source?: string, sourceCardId?: int}>
     */
    public function consumeGrantsCreated(): array
    {
        $grants = $this->pendingGrantsCreated;
        $this->pendingGrantsCreated = [];

        return $grants;
    }

    /**
     * Returns and clears every restriction descriptor cascadeMoodLeavingPlay()
     * recorded as lost since the last call -- see $pendingGrantsLost' own
     * docblock. Same consume-before-logging convention as
     * consumeGrantsCreated()/consumeGrantUsed().
     *
     * @return array<int, array{requiresSourceInPlay: true, sourceCardId: int}>
     */
    public function consumeGrantsLost(): array
    {
        $grants = $this->pendingGrantsLost;
        $this->pendingGrantsLost = [];

        return $grants;
    }

    public function playsRemaining(): int
    {
        return count(array_filter($this->playGrants, fn (?array $g) => $this->grantIsActive($g)));
    }

    /** @return array<int, ?array{type?: string, values?: int[], source?: string, onUseEffectState?: array<string, mixed>}> */
    public function pendingPlayGrants(): array
    {
        return array_values(array_filter($this->playGrants, fn (?array $g) => $this->grantIsActive($g)));
    }

    /** Whether any outstanding grant this turn -- restricted or not -- would allow playing $cardId. */
    public function hasUsablePlayGrant(int $cardId, int $playerId): bool
    {
        foreach ($this->playGrants as $restriction) {
            if ($this->grantIsActive($restriction) && $this->grantAllows($restriction, $cardId, $playerId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes one outstanding grant that permits playing $cardId (see
     * hasUsablePlayGrant()), returning the restriction descriptor it
     * consumed (or null for an unconditional grant, or if none matched) so
     * callers can react to a grant-specific tag -- see the
     * 'onUseEffectState' key.
     *
     * $preferredSourceCardId, when given, restricts the search to the one
     * grant sourced from that specific card (0 standing in for the base
     * allowance, which has no 'sourceCardId' of its own -- see
     * usableGrants()) -- how a player picks which of 2+ usable grants to
     * spend when GameService's own 'grant_source_card_id' choice field
     * offered one. Left null (the default), this behaves exactly as
     * before: whichever usable grant happens to come first.
     *
     * @return ?array{type?: string, values?: int[], source?: string, onUseEffectState?: array<string, mixed>}
     */
    public function useGrantFor(int $cardId, int $playerId, ?int $preferredSourceCardId = null): ?array
    {
        foreach ($this->playGrants as $index => $restriction) {
            if (!$this->grantIsActive($restriction)) {
                continue;
            }
            if ($preferredSourceCardId !== null && ($restriction['sourceCardId'] ?? 0) !== $preferredSourceCardId) {
                continue;
            }
            if ($this->grantAllows($restriction, $cardId, $playerId)) {
                unset($this->playGrants[$index]);
                $this->playGrants = array_values($this->playGrants);

                if ($restriction !== null) {
                    $this->pendingGrantUsed = $restriction;
                }

                return $restriction;
            }
        }

        return null;
    }

    /**
     * Every currently-usable grant for playing $cardId (grantIsActive()
     * and grantAllows() both satisfied), deduplicated by 'sourceCardId' --
     * the base allowance's own bare nulls (there can be more than one at
     * once, with Hurt Feelings) collapse into a single entry, since
     * they're indistinguishable to a player choosing between them. Order
     * follows $playGrants' own order. Exposed so GameService can offer an
     * explicit "which grant do you want to use" choice whenever 2+ come
     * back -- see 'grant_source_card_id' in php-app/README.md -- instead
     * of always silently consuming whichever happens to come first.
     *
     * @return array<int, ?array{type?: string, values?: int[], source?: string, sourceCardId?: int}>
     */
    public function usableGrants(int $cardId, int $playerId): array
    {
        $grants = [];
        $seenKeys = [];
        foreach ($this->playGrants as $restriction) {
            if (!$this->grantIsActive($restriction) || !$this->grantAllows($restriction, $cardId, $playerId)) {
                continue;
            }
            $key = $restriction['sourceCardId'] ?? 'base';
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;
            $grants[] = $restriction;
        }

        return $grants;
    }

    /**
     * Whether $restriction (an entry in $playGrants) still actually
     * counts. True for every ordinary grant, but a grant tagged
     * 'requiresSourceInPlay' -- Hope's and Grace's own bonus, both the
     * same-turn one (grantExtraPlay(), the moment either card is played)
     * and every future turn's perpetual one
     * (GameService::computeFreshGrants()) -- only counts while its own
     * 'sourceCardId' is still actually in play. If that specific Hope or
     * Grace is discarded, returned to hand, or otherwise leaves play
     * before the player gets around to using the play it granted, the
     * grant is lost, not merely un-attributed. Stubbornness's own
     * perpetual grant is deliberately never tagged this way -- once
     * computeFreshGrants() grants it at the start of a turn, it persists
     * for that turn even if Stubbornness itself later leaves play, unlike
     * Hope/Grace. Neither is the base allowance (always null) or a banked
     * Generosity/Joy grant, both unaffected by this distinction.
     */
    private function grantIsActive(?array $restriction): bool
    {
        if ($restriction === null || !($restriction['requiresSourceInPlay'] ?? false)) {
            return true;
        }

        return $this->isInPlay($restriction['sourceCardId']);
    }

    /**
     * Returns and clears $pendingGrantUsed -- see its own docblock. Unlike
     * the other consume*() methods here, a null return doesn't mean
     * "nothing happened since the last call" as much as "either nothing
     * consumed a grant, or it consumed the ordinary base allowance" --
     * GameService only cares about the latter distinction (an actual
     * granted extra play worth announcing) either way, so both collapse to
     * the same "say nothing" outcome for its purposes.
     *
     * @return ?array{type?: string, values?: int[], source?: string, sourceCardId?: int}
     */
    public function consumeGrantUsed(): ?array
    {
        $grant = $this->pendingGrantUsed;
        $this->pendingGrantUsed = null;

        return $grant;
    }

    /** Consumes one grant regardless of restriction -- for tests/setup that don't care which. MoodPlayService uses useGrantFor() instead, since it must consume a grant that actually permits the card being played. */
    public function consumePlay(): void
    {
        if ($this->playGrants !== []) {
            array_shift($this->playGrants);
        }
    }

    /**
     * @param ?array{type?: string, values?: int[], source?: string} $restriction
     */
    private function grantAllows(?array $restriction, int $cardId, int $playerId): bool
    {
        // 'source' defaults to 'hand'; Harmony/Grief/Angst grant plays
        // sourced from the discard pile instead, so a hand-sourced grant
        // must not match a card that's actually sitting in the discard
        // pile, and vice versa -- except Melancholy, which lets its owner
        // treat the whole discard pile as part of their hand for every
        // play (not just a dedicated bonus one), so a hand-sourced grant
        // is allowed to match a discard-pile card for that specific player.
        $source = $restriction['source'] ?? 'hand';
        $cardInDiscard = $this->isInDiscardPile($cardId);
        if ($source === 'discard') {
            if (!$cardInDiscard) {
                return false;
            }
        } elseif ($cardInDiscard && !$this->playerHasMoodInPlay($playerId, 'melancholy')) {
            return false;
        }

        if ($restriction === null || !isset($restriction['type'])) {
            return true;
        }

        return match ($restriction['type']) {
            'shares_color_with_your_moods' => $this->sharesColorWithOwnMoods($cardId, $playerId),
            'does_not_share_color_with_your_moods' => !$this->sharesColorWithOwnMoods($cardId, $playerId),
            'base_value_in' => in_array($this->catalogRow($cardId)['baseValue'], $restriction['values'], true),
            // Intimidation: the grant only ever covers the one specific
            // card it revealed, not any card sharing some trait with it.
            'specific_card_ids' => in_array($cardId, $restriction['values'], true),
            default => throw new InvalidArgumentException("Unknown play grant restriction type '{$restriction['type']}'"),
        };
    }

    private function sharesColorWithOwnMoods(int $cardId, int $playerId): bool
    {
        $color = $this->colorOf($cardId);
        foreach ($this->moodsOwnedBy($playerId) as $mood) {
            if ($this->colorOf($mood->cardId) === $color) {
                return true;
            }
        }

        return false;
    }

    /** Whether $playerId currently has a mood with effect_key $effectKey in play (e.g. Melancholy, or GameService checking for Hope/Grace/Stubbornness at the start of a turn). */
    public function playerHasMoodInPlay(int $playerId, string $effectKey): bool
    {
        return $this->countMoodsInPlayWithEffectiveKey($playerId, $effectKey) > 0;
    }

    /**
     * How many of $playerId's own in-play moods have $effectKey as their
     * EFFECTIVE (copy-aware) effect key -- e.g. a real Duplicity plus a
     * Creativity currently copying Duplicity both count. Most callers only
     * need playerHasMoodInPlay()'s boolean; Duplicity's repeat-offer needs
     * the actual count, since each such mood grants its own independent
     * "may repeat" decision (see MoodPlayService::continueAfterPlayingChain()).
     */
    public function countMoodsInPlayWithEffectiveKey(int $playerId, string $effectKey): int
    {
        $count = 0;
        foreach ($this->moodsOwnedBy($playerId) as $mood) {
            if ($this->catalogRow($this->effectiveCardId($mood->cardId))['effectKey'] === $effectKey) {
                $count++;
            }
        }

        return $count;
    }
}
