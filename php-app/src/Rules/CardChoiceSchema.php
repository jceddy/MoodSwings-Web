<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Describes, per effect_key, which PlayerChoices keys a card's effect
 * actually reads -- so a UI can render a form tailored to the one card
 * being played instead of one generic form covering every card in the
 * 133-card pool. This is presentation metadata, but it has to live next to
 * the effect classes it describes (DefaultEffectRegistry.php, Effects/*.php)
 * since it's derived directly from what each one reads via PlayerChoices,
 * and a card-name-only key scheme wouldn't work: some cards reuse the same
 * key for a different concept (e.g. 'discard_card_id' means a HAND card for
 * Cheer/Dignity/Bliss but a DISCARD PILE card for Nostalgia/Cynicism), so
 * this has to be keyed by effect_key, not by key name.
 *
 * Field shape: array{
 *     key: string,          // exact PlayerChoices key the effect reads
 *     type: string,         // player|mood|hand_card|discard_card|mode|value|bool|nested
 *     label: string,
 *     required: bool,       // true only for requireInt/requireString or a mandatory to-play cost
 *     multi?: bool,         // true for ints()-backed (possibly multiple) fields
 *     scope?: string,       // player: any|other -- mood: own|other|any
 *     options?: string[],   // mode only
 *     min?: int, max?: int, // value only
 *     allow_extra_values?: bool, // value only: this field's own max is a practical default (the highest
 *                           // printed base value in the catalog), not a rule the effect itself enforces --
 *                           // when set, GameService::withExtraOutOfRangeValues() appends any actually
 *                           // achievable value above max (from a mood currently in play, as if this card
 *                           // were already played too) as this field's own extra_values, since a picker
 *                           // capped at max would otherwise never offer a legitimately reachable choice.
 *                           // Repentance's own field is the only one set this way -- Rebellion's field is
 *                           // also 'value'-typed but its 0-3 range comes directly from its own printed
 *                           // rules text ("choose 0, 1, 2, or 3"), a real rule that must never be widened.
 *     fields?: array,       // nested only: this field's own sub-fields, same shape as this array --
 *                           // one level deep only (see Duplicity below)
 *     filter?: array{       // narrows a dropdown to choices the effect will actually accept,
 *         colors?: string[],             // mood/hand_card: candidate's color must be one of these
 *         values?: int[],                // hand_card: candidate's (base) value must be one of these
 *         min_value?: int, max_value?: int, // mood: candidate's (live) value must fall in this range
 *         parity?: 'odd'|'even',          // mood: candidate's (live) value must have this parity
 *         has_dice_value?: bool,          // mood: candidate must have a printed dice/alt value
 *         min_hand_count?: int,           // player: candidate must hold at least this many hand cards
 *         min_mood_count?: int,           // player: candidate must own at least this many moods in play
 *         more_moods_than_viewer?: bool,  // player: candidate must own more moods than you once this card is in play
 *     },                    // mirrors each effect class's own InvalidChoiceException checks exactly --
 *                           // see CardChoiceSchemaTest for the source cross-references. A field with
 *                           // no filter key has no such narrowing (every scope-eligible candidate is legal).
 *     excludes_teammate?: bool, // player/mood: a static per-effect_key flag (like the rest of this schema,
 *                           // not computed per-request) marking the handful of cards whose printed text
 *                           // says "opponent" rather than "another player"/"any player" -- in Open Team
 *                           // Play, a teammate isn't a legal choice for these even though scope: 'other'
 *                           // alone wouldn't exclude them (see BoardState::isTeammate() and
 *                           // php-app/README.md's "Open Team Play" section for exactly which cards this
 *                           // applies to and which don't). A no-op outside team format: there's no
 *                           // teammate to exclude, so fieldOptions() narrowing on it never removes anything.
 *     count?: array{        // multi fields only: how many selections are legal
 *         min?: int,             // fewer than this is illegal (unless zero_ok and none are selected)
 *         max?: int,             // more than this is illegal
 *         zero_ok?: bool,        // selecting none is always legal even if min is set (an optional effect
 *                                // that's "0, or exactly/at-least min" rather than "always at least min")
 *     },
 *     constraint?: array{   // multi fields only: a relationship the selected candidates must satisfy
 *                           // together, checked once 2+ are selected (except max_total_value, checked
 *                           // from the first selection on) -- mirrors the effect's own cross-candidate
 *                           // InvalidChoiceException check, not a per-candidate filter
 *         type: 'same_color_or_value'|'same_owner'|'distinct_owners'|'max_total_value',
 *         max?: int,             // max_total_value only
 *     },
 *     stage?: 'cost',        // marks a field as belonging to payToPlayCost() rather than
 *                            // afterPlaying() -- only Guile's discard_card_ids and Regret's
 *                            // hand_mood_ids mix the two; afterPlayingFields() below excludes
 *                            // these when building Duplicity's repeat sub-form, since a repeat
 *                            // only ever re-invokes afterPlaying(), never the cost again.
 * }
 *
 * Scorn's reactToAnotherPlay() choice doesn't fit the per-effect_key
 * SCHEMA above -- it fires while playing a *different* card, triggered
 * by a mood the acting player already has in play, as part of the *same*
 * request (see MoodEffect::reactToAnotherPlay()). REACTIONS below holds
 * its fixed field shape; GameService::serializeCard() decides, per hand
 * card, whether to append it -- it knows the viewer's own in-play moods
 * (playerHasMoodInPlay()) and the specific card being offered, which is
 * exactly what's needed to fill in Scorn's color filter (must match
 * *this* card's color). Validation's own reaction needs no such field at
 * all: like its first grant, it's unconditional (see ValidationEffect's
 * own docblock for why "you may" doesn't mean "opt into the grant
 * existing") -- ValidationEffect::reactToAnotherPlay() just calls
 * grantExtraPlay() outright whenever the played card's base value is 0
 * or 1, no PlayerChoices input needed.
 *
 * Duplicity's repeat-with-fresh-choices mechanic also doesn't fit the
 * per-effect_key SCHEMA above, but for a different reason: it isn't
 * decided as part of the triggering play's own request at all -- it's a
 * genuine mid-play pause offered to the acting player themselves *after*
 * the played card's afterPlaying() resolves, via the same
 * PendingDecisionRequest/game_pending_decision_batches machinery the
 * ten RequiresOpponentDecision cards use (see MoodPlayService::
 * continueAfterPlayingChain()/duplicityRepeatOfferRequest()). REACTIONS'
 * 'duplicity' entry supplies just the offer's label; its nested 'choices'
 * sub-field is built from afterPlayingFields() below, against the played
 * card's own effect_key.
 *
 * Creativity's copy_card_id choice (see the 'creativity' entry below)
 * isn't known until its own panel is open, so a Scorn reaction to a
 * Creativity play can't be precomputed here against Creativity's own
 * (ability-less) row the way this schema handles every other card --
 * GameService::creativityCopySimulation() covers this instead, reusing
 * reactionFields() but parameterized per in-play candidate rather than by
 * Creativity's own raw catalog row; see php-app/README.md. A Duplicity
 * repeat of a Creativity copy needs no such precomputation, since it's
 * resolved after the play completes, against whatever effect_key the
 * copy actually resolved to.
 *
 * Cards with no printed ability, and cards whose effect never reads
 * PlayerChoices at all (pure computeValue() formulas; unconditional
 * grants; effects resolved entirely outside MoodEffect, e.g. Grace/Hope/
 * Stubbornness/Melancholy), simply have no entry below -- forEffectKey()
 * returns [] for them, same as an unregistered/no-ability card.
 */
final class CardChoiceSchema
{
    private const FIVE_COLORS = ['white', 'blue', 'black', 'red', 'green'];

    /** @var array<string, array<int, array<string, mixed>>> */
    private const SCHEMA = [
        'dignity' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (boosts this mood\'s value to 5)', 'filter' => ['values' => [0, 1, 2, 3]]],
        ],
        'imagination' => [
            ['key' => 'color', 'type' => 'mode', 'required' => true, 'options' => self::FIVE_COLORS, 'label' => 'Color to declare for every mood in play'],
        ],
        'courage' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to put into the discard pile (value 5+, up to 2, one per player)', 'filter' => ['min_value' => 5], 'count' => ['max' => 2], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'conviction' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => true, 'label' => 'Mood to move to the bottom of the deck'],
        ],
        'zeal' => [
            ['key' => 'hand_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to bottom-deck and redraw'],
        ],
        'faith' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (must be green or blue)', 'filter' => ['colors' => ['green', 'blue']]],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to suppress (required if discarding a card above)'],
        ],
        'guile' => [
            ['key' => 'discard_card_ids', 'type' => 'hand_card', 'multi' => true, 'required' => true, 'label' => 'Exactly 2 cards to discard (cost to play this card)', 'count' => ['min' => 2, 'max' => 2], 'stage' => 'cost'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'other', 'required' => true, 'label' => "An opponent's mood to take", 'excludes_teammate' => true],
        ],
        'envy' => [
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => true, 'label' => 'Your mood to put into the discard pile (cost to play this card)'],
        ],
        'fascination' => [
            ['key' => 'give_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to give away (must be blue or black)', 'filter' => ['colors' => ['blue', 'black']]],
            ['key' => 'recipient_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Player to give it to (required if giving a card)'],
        ],
        'wonder' => [
            ['key' => 'color', 'type' => 'mode', 'required' => true, 'options' => self::FIVE_COLORS, 'label' => 'Color to declare'],
        ],
        'anger' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to put into the discard pile (combined value 5 or less)', 'constraint' => ['type' => 'max_total_value', 'max' => 5]],
        ],
        'self_loathing' => [
            ['key' => 'discard_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => true, 'label' => 'Your mood(s) to put into the discard pile (cost to play this card, one or more)', 'count' => ['min' => 1]],
        ],
        'pacifism' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to suppress (up to 2, one per player)', 'count' => ['max' => 2], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'repentance' => [
            ['key' => 'value', 'type' => 'value', 'required' => false, 'min' => 0, 'max' => 12, 'allow_extra_values' => true, 'label' => 'Value to suppress (every mood showing it)'],
        ],
        'hate' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to move to the bottom of the deck'],
        ],
        'wrath' => [
            ['key' => 'discard_all_other_moods', 'type' => 'bool', 'required' => false, 'label' => 'Put every other mood in play into the discard pile'],
        ],
        'rage' => [
            ['key' => 'discard_qualifying_moods', 'type' => 'bool', 'required' => false, 'label' => 'Put every mood valued 3 or less into the discard pile'],
        ],
        'anxiety' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Odd-valued moods to return to hand (up to 2, one per player)', 'filter' => ['parity' => 'odd'], 'count' => ['max' => 2], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'guilt' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => true, 'options' => ['single', 'all'], 'label' => 'Suppress one qualifying mood, or all of them'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to suppress (black or red; required if mode is single)', 'filter' => ['colors' => ['black', 'red']]],
        ],
        'shame' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => "Card to discard (suppresses other moods sharing its color)"],
        ],
        'spite' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Even-valued moods to put into the discard pile (up to 2, one per player)', 'filter' => ['parity' => 'even'], 'count' => ['max' => 2], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'rebellion' => [
            ['key' => 'value', 'type' => 'value', 'required' => true, 'min' => 0, 'max' => 3, 'label' => 'Value to put into the discard pile (every mood showing it)'],
        ],
        'shock' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to put into the discard pile (value 3 or less, up to 2, one per player)', 'filter' => ['max_value' => 3], 'count' => ['max' => 2], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'bravado' => [
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Another of your moods to put into the discard pile (unlocks an extra play)'],
        ],
        'neurosis' => [
            ['key' => 'hand_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => true, 'label' => 'Your mood(s) to return to hand (cost to play this card, one or more)', 'count' => ['min' => 1]],
        ],
        'regret' => [
            ['key' => 'hand_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => true, 'label' => 'Exactly 2 of your moods to return to hand (cost to play this card)', 'count' => ['min' => 2, 'max' => 2], 'stage' => 'cost'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'other', 'required' => true, 'label' => "An opponent's mood to steal into your hand", 'excludes_teammate' => true],
        ],
        'cruelty' => [
            ['key' => 'opponent_player_ids', 'type' => 'player', 'scope' => 'other', 'multi' => true, 'required' => false, 'label' => 'Opponents to target (each must have 2+ moods)', 'filter' => ['min_mood_count' => 2], 'excludes_teammate' => true],
        ],
        'indecisiveness' => [
            ['key' => 'opponent_player_ids', 'type' => 'player', 'scope' => 'other', 'multi' => true, 'required' => false, 'label' => 'Opponents to target (each must have 2+ moods)', 'filter' => ['min_mood_count' => 2], 'excludes_teammate' => true],
        ],
        'rejection' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => '2 moods to put into the discard pile (must share a color or value)', 'count' => ['min' => 2, 'max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'same_color_or_value']],
        ],
        'denial' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => '2 moods to return to hand (must share a color or value)', 'count' => ['min' => 2, 'max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'same_color_or_value']],
        ],
        'disorientation' => [
            ['key' => 'value', 'type' => 'value', 'required' => false, 'min' => 0, 'max' => 12, 'label' => 'Value to return to hand (every mood showing it)'],
        ],
        'panic' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to return to hand (up to 2, one per player)', 'count' => ['max' => 2], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'worry' => [
            ['key' => 'hand_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Your white or black mood to return to hand', 'filter' => ['colors' => ['white', 'black']]],
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to return to hand (value 3 or less, up to 2; only if you returned a mood above)', 'filter' => ['max_value' => 3], 'count' => ['max' => 2]],
        ],
        'contempt' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'options' => ['single', 'all'], 'label' => 'Put one qualifying mood into the discard pile, or all of them'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to put into the discard pile (green or white; required if mode is single)', 'filter' => ['colors' => ['green', 'white']]],
        ],
        'ambition' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (unlocks an extra play)'],
        ],
        'thrill' => [
            ['key' => 'hand_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => false, 'label' => 'Your moods to return to hand (each grants an extra play)'],
        ],
        'fear' => [
            ['key' => 'hand_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Your mood to return to hand'],
        ],
        'paranoia' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => false, 'label' => 'Player to target (must have cards in hand)', 'filter' => ['min_hand_count' => 1]],
        ],
        'suspicion' => [
            ['key' => 'player_ids', 'type' => 'player', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Players to target', 'filter' => ['min_hand_count' => 1]],
        ],
        'curiosity' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => false, 'label' => 'Player whose hand to reveal a card from', 'filter' => ['min_hand_count' => 1]],
        ],
        'condescension' => [
            ['key' => 'give_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to give away'],
            ['key' => 'recipient_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Player to give it to (required if giving a card)'],
        ],
        'cynicism' => [
            ['key' => 'discard_card_id', 'type' => 'discard_card', 'required' => false, 'label' => 'Discard-pile card to give away'],
            ['key' => 'recipient_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Player to give it to (required if moving a card)', 'excludes_teammate' => true],
        ],
        'infatuation' => [
            ['key' => 'discard_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => false, 'label' => '2 of your other moods to put into the discard pile'],
        ],
        'hostility' => [
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Your black or green mood to put into the discard pile', 'filter' => ['colors' => ['black', 'green']]],
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to put into the discard pile (value 3 or less, up to 2; only if you put a mood into the discard pile above)', 'filter' => ['max_value' => 3], 'count' => ['max' => 2]],
        ],
        'malice' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => false, 'label' => 'Player to target (must have 2+ moods)', 'filter' => ['min_mood_count' => 2]],
        ],
        'hesitation' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => true, 'options' => ['single', 'all'], 'label' => "Return one qualifying mood to its player's hand, or all of them"],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => "Mood to return to its player's hand (red or green; required if mode is single)", 'filter' => ['colors' => ['red', 'green']]],
        ],
        'nostalgia' => [
            ['key' => 'discard_card_id', 'type' => 'discard_card', 'required' => false, 'label' => 'Discard-pile card to take into your hand'],
        ],
        'angst' => [
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Your blue or red mood to put into the discard pile', 'filter' => ['colors' => ['blue', 'red']]],
        ],
        'honor' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => true, 'label' => 'Player who goes first every round from now on'],
        ],
        'avoidance' => [
            ['key' => 'direction', 'type' => 'mode', 'required' => true, 'options' => ['left', 'right'], 'label' => 'Direction to pass a mood around the table'],
        ],
        'confusion' => [
            ['key' => 'direction', 'type' => 'mode', 'required' => true, 'options' => ['left', 'right'], 'label' => 'Direction to pass a hand card around the table'],
        ],
        'rationalization' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'options' => ['refresh', 'rotate'], 'label' => 'Refresh your own hand, or rotate hands with everyone (optional)'],
            ['key' => 'direction', 'type' => 'mode', 'required' => false, 'options' => ['left', 'right'], 'label' => 'Direction to rotate (required if mode is rotate)'],
        ],
        'instability' => [
            // No 'given_mood_id' entry here -- like Betrayal, Instability
            // itself is a valid answer for "one of your moods to give in
            // exchange" (its own printed text doesn't exclude it), but it
            // isn't in play yet at the moment this panel is filled out, so
            // a static field sourced from the current board could never
            // legally offer it. See InstabilityEffect, which defers that
            // choice to a second pending-decision step the acting player
            // answers immediately after Instability has actually entered
            // play, right after the opponent's own first-step answer.
            ['key' => 'candidate_mood_ids', 'type' => 'mood', 'scope' => 'other', 'multi' => true, 'required' => false, 'label' => "2 of one opponent's moods (they choose which one to give up)", 'count' => ['min' => 2, 'max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'same_owner']],
        ],
        'betrayal' => [
            // No 'target_mood_id' entry here -- unlike every other "your
            // own mood" choice, Betrayal itself is a valid answer (its
            // own printed text doesn't exclude itself), but it isn't in
            // play yet at the moment this panel is filled out, so a
            // static field sourced from what's currently on the board
            // could never legally offer it. See BetrayalEffect, which
            // defers that choice to a pending decision the acting player
            // answers immediately after Betrayal has actually entered
            // play, the same mechanism used for an opponent's own answer
            // elsewhere in this table -- just targeting the acting player.
            ['key' => 'recipient_player_id', 'type' => 'player', 'scope' => 'other', 'required' => true, 'label' => 'Player to give it to'],
        ],
        'sneakiness' => [
            ['key' => 'opponent_player_id', 'type' => 'player', 'scope' => 'other', 'required' => true, 'label' => 'Opponent to swap scores with at scoring time', 'excludes_teammate' => true],
        ],
        'awe' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => true, 'label' => 'Player who goes first next round'],
        ],
        'recklessness' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'other', 'required' => false, 'label' => "An opponent's mood to take (returns to them after scoring if you still hold it)"],
        ],
        // No 'pride' entry here -- unlike every other "choose a player"
        // field, which player values qualify isn't knowable until Pride
        // itself is already in play (its own mood counts toward "more
        // moods than you"), so a static field sourced from the board as it
        // stands before this play could offer a player who'd only turn out
        // to be tied. See PrideEffect, which defers this choice to a
        // pending decision the acting player answers immediately after
        // Pride has actually entered play, with the qualifying candidates
        // computed server-side against the real post-play board.
        'corruption' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'options' => ['cycle', 'double_win'], 'label' => 'Double your next win, or cycle discard-pile cards to the bottom of the deck'],
            ['key' => 'discard_card_ids', 'type' => 'discard_card', 'multi' => true, 'required' => false, 'label' => 'Up to 2 discard-pile cards to cycle (required if mode is cycle)', 'count' => ['max' => 2]],
        ],
        'doubt' => [
            ['key' => 'reveal_card_ids', 'type' => 'hand_card', 'multi' => true, 'required' => false, 'label' => 'Hand cards to reveal (redrawn; their colors are banned next round)'],
        ],
        'generosity' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'other', 'required' => true, 'label' => 'Player to bank an extra play for on their next turn', 'excludes_teammate' => true],
        ],
        'arrogance' => [
            ['key' => 'opponent_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Opponent to target (they choose one of their qualifying moods to give up)'],
        ],
        'scorn' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => true, 'label' => 'Mood to suppress until end of round'],
        ],
        'compulsion' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'other', 'required' => true, 'label' => 'Player to target'],
        ],
        'intimidation' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Player to target (their revealed card grants you a restricted extra play)'],
        ],
        'exhilaration' => [
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => true, 'label' => 'Your mood to put into the discard pile (cost to play this card)'],
        ],
        'bliss' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => true, 'label' => "Card to discard (cost to play this card; its color decides your scoring bonus)"],
        ],
        'encouragement' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to apply its dice value to (must have one printed)', 'filter' => ['has_dice_value' => true]],
        ],
        'embarrassment' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (base value 4, 5, or 6; boosts this mood\'s value to 5)', 'filter' => ['values' => [4, 5, 6]]],
        ],
        'cheer' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (base value 0, 2, 4, or 6; boosts this mood\'s value to 5)', 'filter' => ['values' => [0, 2, 4, 6]]],
        ],
        'delight' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (base value 1, 3, or 5; boosts this mood\'s value to 5)', 'filter' => ['values' => [1, 3, 5]]],
        ],
        'creativity' => [
            ['key' => 'copy_card_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => "Play as a copy of a mood currently in play (optional -- otherwise it's just a blue card worth 0)"],
        ],
    ];

    /**
     * Templates for Scorn's reactToAnotherPlay() field, for Duplicity's
     * repeat offer, and for Enthusiasm's/Passion's scoring-time decisions,
     * keyed by the *reacting* card's effect_key. Scorn's template is
     * filled in by GameService::serializeCard() (color filter, appended
     * only while Scorn is in play) before being appended to a hand card's
     * own choice_fields -- it fires as part of the *triggering* play's
     * own request. Validation has no entry here: its own reaction is
     * unconditional (see ValidationEffect's own docblock), so there's no
     * player choice to template. Duplicity's is instead used by
     * MoodPlayService::duplicityRepeatOfferRequest() to label a post-play
     * PendingDecisionRequest offered to the acting player themselves,
     * since a repeat is no longer decided as part of the original play
     * request at all. Enthusiasm's and Passion's are used the same way by
     * GameService::scoringDecisionRequest(), but at round-end rather than
     * mid-play -- unlike Exhilaration/Bliss (printed with no "may" at
     * all, so always applied automatically), these two are genuinely
     * optional and not always correct to take (see RoundScorer's own
     * docblock for why -- Sneakiness's score swap is the reason a bigger
     * pre-swap score isn't always better).
     *
     * @var array<string, array<string, mixed>>
     */
    private const REACTIONS = [
        'scorn' => [
            'key' => 'scorn_suppress_target',
            'type' => 'mood',
            'scope' => 'any',
            'required' => false,
            'label' => "Scorn's reaction: mood to suppress until end of round (must share this card's color)",
        ],
        'duplicity' => [
            'key' => 'duplicity_repeat',
            'type' => 'bool',
            'required' => false,
            'label' => "Duplicity's reaction: repeat this mood's own effect with a fresh set of choices",
        ],
        'enthusiasm' => [
            'key' => 'take_bonus',
            'type' => 'bool',
            'required' => false,
            'label' => "Enthusiasm's bonus: score your own highest-valued mood an extra time",
        ],
        'passion' => [
            'key' => 'target_mood_id',
            'type' => 'mood',
            'scope' => 'other',
            'required' => false,
            'label' => "Passion's bonus: score one of your opponents' moods as though it were yours",
        ],
    ];

    /** @return array<int, array<string, mixed>> */
    public static function forEffectKey(?string $effectKey): array
    {
        if ($effectKey === null) {
            return [];
        }

        return self::SCHEMA[$effectKey] ?? [];
    }

    /**
     * forEffectKey() minus any 'cost' stage field -- what Duplicity's
     * repeat re-invokes is only ever afterPlaying(), never the to-play
     * cost (already paid once, when the card was originally played).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function afterPlayingFields(string $effectKey): array
    {
        return array_values(array_filter(
            self::forEffectKey($effectKey),
            static fn (array $field): bool => ($field['stage'] ?? null) !== 'cost'
        ));
    }

    /** @return ?array<string, mixed> */
    public static function reactionTemplate(string $reactorEffectKey): ?array
    {
        return self::REACTIONS[$reactorEffectKey] ?? null;
    }
}
