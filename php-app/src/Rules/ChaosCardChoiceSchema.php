<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * The chaos-effect counterpart to CardChoiceSchema (see that class's own
 * docblock for the full field-shape DSL, reused verbatim here). Describes,
 * per chaos_effects.effect_key, which keys an ATTACHED chaos effect reads
 * out of PlayerChoices::sub('chaos') -- the namespaced sub-bag documented on
 * ChaosMoodEffect's own interface docblock.
 *
 * This existed as a design gap since Chaos Draft first shipped (issue
 * #405): ~60 of the 133 chaos effects read a player choice via
 * $choices->int()/requireInt()/string()/requireString()/bool()/ints(), but
 * with no schema declaring those fields, GameService::serializeCard() had
 * nothing to expose to the frontend and no UI ever rendered them -- every
 * such effect silently no-opped (its "if you do"/"you may choose..." never
 * had anything to act on). See GameService::serializeCard()'s own
 * 'chaos_choice_fields'-adjacent comment for how this gets wired in: a
 * single synthetic `nested` field (key 'chaos') wrapping this array is
 * appended to the card's own choice_fields, which lets the existing
 * generic nested-field machinery (buildFieldRow()/buildChoicesFromFields()
 * in game.js, already used for Duplicity's repeat-with-fresh-choices
 * sub-form) render and collect it with no frontend-specific code at all --
 * buildChoicesFromFields() nests the result under choices['chaos'], exactly
 * where PlayerChoices::sub('chaos') expects to read it from.
 *
 * A chaos effect key not listed here reads no PlayerChoices value at all
 * (computeValue()-only formulas; unconditional grants/discards; effects
 * resolved without any choice, e.g. "put ALL other moods into the discard
 * pile" with no selection involved) -- forEffectKey() returns [] for those,
 * same as an unregistered key. chaos_008/012/025/036/053/058/087/106/110/
 * 111/118 are a second reason for a missing (or partial) entry: each reads
 * a hand card from the ACTING PLAYER'S OWN hand, which used to be
 * collected here, up front, alongside the host card's own choices -- but
 * that value can go stale if the host card's own printed effect changes
 * the acting player's hand first (issue #405 follow-up, reported live for
 * chaos_058: attaching it to Rationalization and choosing Rationalization's
 * own "rotate hands" mode threw "Card is not in your hand," since the
 * whole hand had already been swapped away by the time the chosen card was
 * validated). Each now defers its own hand-card choice to a self-targeted
 * `ChaosRequiresOpponentDecision` pending decision instead, asked only
 * after the host card's own afterPlaying() has fully resolved -- see
 * ChaosEffects/ChaosDiscardValueToBoostSelfEffect's own docblock for the
 * full reasoning. chaos_058/118 keep a SYNCHRONOUS `recipient_player_id`
 * entry here (who to give a card to doesn't depend on hand contents, so
 * there's no reason to defer it) which doubles as the up-front "would you
 * like to?" gate for the deferred hand-card choice that follows.
 */
final class ChaosCardChoiceSchema
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private const SCHEMA = [
        'chaos_006' => [
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => true, 'label' => 'Mood to move to the bottom of the deck (owner draws a card)', 'scope' => 'any', 'includes_self' => true],
        ],
        'chaos_007' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Moods to put into the discard pile (value 5+, up to 2, one per player)', 'scope' => 'any', 'filter' => ['min_value' => 5], 'count' => ['max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'chaos_014' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'label' => 'Suppress one black/red mood, or all of them', 'options' => ['single', 'all']],
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'Mood to suppress (black or red; required if mode is single)', 'scope' => 'any', 'includes_self' => true, 'filter' => ['colors' => ['black', 'red']]],
        ],
        'chaos_020' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Moods to suppress (up to 2, one per player)', 'scope' => 'any', 'count' => ['max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'chaos_022' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => false, 'label' => 'Player with more moods than you', 'scope' => 'any', 'filter' => ['more_moods_than_viewer' => true]],
        ],
        'chaos_023' => [
            ['key' => 'value', 'type' => 'value', 'required' => false, 'min' => 0, 'max' => 12, 'label' => 'Value to suppress (every other mood showing it)'],
        ],
        'chaos_028' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Odd-valued moods to return to hand (up to 2, one per player)', 'scope' => 'any', 'filter' => ['parity' => 'odd'], 'count' => ['max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'chaos_029' => [
            ['key' => 'direction', 'type' => 'mode', 'required' => true, 'label' => 'Direction to pass moods', 'options' => ['left', 'right']],
        ],
        'chaos_031' => [
            ['key' => 'direction', 'type' => 'mode', 'required' => true, 'label' => 'Direction to pass hand cards', 'options' => ['left', 'right']],
        ],
        'chaos_033' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => false, 'label' => 'Player to force a random hand reveal', 'scope' => 'any'],
        ],
        'chaos_034' => [
            ['key' => 'mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Two other moods (matching color/value returns both to hand)', 'scope' => 'any', 'count' => ['min' => 2, 'max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'same_color_or_value']],
        ],
        'chaos_035' => [
            ['key' => 'value', 'type' => 'value', 'required' => false, 'min' => 0, 'max' => 12, 'label' => 'Value to return to hand (every other mood showing it)'],
        ],
        'chaos_038' => [
            ['key' => 'return_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your other moods to return to hand', 'scope' => 'own'],
        ],
        'chaos_041' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'label' => 'Return one red/green mood to hand, or all of them', 'options' => ['single', 'all']],
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'Mood to return to hand (red or green; required if mode is single)', 'scope' => 'any', 'includes_self' => true, 'filter' => ['colors' => ['red', 'green']]],
        ],
        'chaos_043' => [
            ['key' => 'opponent_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => 'Opponents (2+ moods) who each return a random mood to hand', 'scope' => 'other', 'excludes_teammate' => true, 'filter' => ['min_mood_count' => 2], 'count' => ['zero_ok' => true]],
        ],
        'chaos_046' => [
            ['key' => 'return_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your moods to return to hand', 'scope' => 'own', 'includes_self' => true],
            ['key' => 'bottom_top_card', 'type' => 'bool', 'required' => false, 'label' => 'Bottom your revealed top deck card (only if you returned a mood)'],
        ],
        'chaos_048' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => "Moods to return to hand (up to 2, one per player; can't target this mood on yourself)", 'scope' => 'any', 'count' => ['max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'chaos_049' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'label' => 'Redraw your hand, or pass all hands directionally', 'options' => ['redraw', 'pass']],
            ['key' => 'direction', 'type' => 'mode', 'required' => false, 'label' => 'Direction to pass hands (required if mode is pass)', 'options' => ['left', 'right']],
        ],
        'chaos_050' => [
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => false, 'label' => "Opponent's mood to target", 'scope' => 'other', 'excludes_teammate' => true],
        ],
        'chaos_051' => [
            ['key' => 'opponent_player_id', 'type' => 'player', 'required' => true, 'label' => 'Opponent to swap scores with after scoring', 'scope' => 'other', 'excludes_teammate' => true],
        ],
        'chaos_052' => [
            ['key' => 'return_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your white/black moods to return to hand', 'scope' => 'own', 'includes_self' => true, 'filter' => ['colors' => ['white', 'black']]],
            ['key' => 'other_mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Up to two other moods (value 3 or less) to return to hand', 'scope' => 'any', 'filter' => ['max_value' => 3], 'count' => ['max' => 2, 'zero_ok' => true]],
        ],
        'chaos_054' => [
            ['key' => 'discard_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your blue/red moods to discard (grants a play from the discard pile)', 'scope' => 'own', 'includes_self' => true, 'filter' => ['colors' => ['blue', 'red']]],
        ],
        'chaos_056' => [
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => false, 'label' => "Opponent's mood to reduce (discarded instead if it would go below 0)", 'scope' => 'other', 'excludes_teammate' => true],
        ],
        'chaos_058' => [
            // 'hand_card_id' deliberately has no entry here anymore --
            // issue #405 follow-up (reported live): choosing which card to
            // give away has to happen AFTER this mood's own afterPlaying()
            // resolves (see Chaos058Effect's own docblock), not up front
            // alongside this field, so it's asked as a follow-up pending
            // decision instead. This field alone is still the up-front
            // "would you like to give a card away, and to whom" gate.
            ['key' => 'recipient_player_id', 'type' => 'player', 'required' => false, 'label' => 'Player to receive a card from your hand (you choose which after confirming)', 'scope' => 'other'],
        ],
        'chaos_059' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'label' => 'Discard one green/white mood, or all of them', 'options' => ['single', 'all']],
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'Mood to discard (green or white; required if mode is single)', 'scope' => 'any', 'includes_self' => true, 'filter' => ['colors' => ['green', 'white']]],
        ],
        'chaos_060' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'label' => 'Recycle discard pile cards, or award an extra round win', 'options' => ['recycle', 'extra_win']],
            ['key' => 'discard_card_ids', 'type' => 'discard_card', 'required' => false, 'multi' => true, 'label' => 'Up to two discard pile cards to bottom-deck and redraw', 'count' => ['max' => 2, 'zero_ok' => true]],
        ],
        'chaos_061' => [
            ['key' => 'opponent_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => 'Opponents (2+ moods) who each discard a random mood', 'scope' => 'other', 'excludes_teammate' => true, 'filter' => ['min_mood_count' => 2], 'count' => ['zero_ok' => true]],
        ],
        'chaos_062' => [
            ['key' => 'discard_card_id', 'type' => 'discard_card', 'required' => false, 'label' => 'Discard pile card to give away'],
            ['key' => 'opponent_player_id', 'type' => 'player', 'required' => false, 'label' => 'Opponent to receive the card (required if giving a card)', 'scope' => 'other', 'excludes_teammate' => true],
        ],
        'chaos_066' => [
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'Mood to move to the bottom of the deck (then you draw)', 'scope' => 'any', 'includes_self' => true],
        ],
        'chaos_067' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => false, 'label' => 'Other player who reveals a card of their choice from their hand', 'scope' => 'other'],
        ],
        'chaos_068' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => true, 'label' => 'Player (2+ moods) who chooses two of their moods to trigger a color-match discard', 'scope' => 'any', 'filter' => ['min_mood_count' => 2]],
        ],
        'chaos_071' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => false, 'label' => 'Player (1+ hand cards) to bottom-deck a random hand card (then you draw)', 'scope' => 'any', 'filter' => ['min_hand_count' => 1]],
        ],
        'chaos_073' => [
            ['key' => 'mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Two other moods (matching color/value discards both)', 'scope' => 'any', 'count' => ['min' => 2, 'max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'same_color_or_value']],
        ],
        'chaos_075' => [
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => false, 'label' => "Opponent's mood (value less than this mood's) to discard", 'scope' => 'other', 'excludes_teammate' => true],
        ],
        'chaos_076' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Even-valued moods to put into the discard pile (up to 2, one per player)', 'scope' => 'any', 'filter' => ['parity' => 'even'], 'count' => ['max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'chaos_078' => [
            ['key' => 'target_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => 'Players who each choose a card from their hand to discard', 'scope' => 'any', 'count' => ['zero_ok' => true]],
        ],
        'chaos_080' => [
            ['key' => 'mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Any number of moods (total value 5 or less) to discard', 'scope' => 'any', 'includes_self' => true, 'count' => ['zero_ok' => true], 'constraint' => ['type' => 'max_total_value', 'max' => 5]],
        ],
        'chaos_082' => [
            ['key' => 'opponent_player_id', 'type' => 'player', 'required' => false, 'label' => "Opponent who chooses one of their white/blue moods to give up (returns when this mood leaves play)", 'scope' => 'other', 'excludes_teammate' => true],
        ],
        'chaos_084' => [
            ['key' => 'discard_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your other moods to discard for an extra play', 'scope' => 'own'],
        ],
        'chaos_086' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => true, 'label' => 'Other player who chooses a card from their hand to give you', 'scope' => 'other'],
        ],
        'chaos_094' => [
            ['key' => 'discard_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your black/green moods to discard', 'scope' => 'own', 'includes_self' => true, 'filter' => ['colors' => ['black', 'green']]],
            ['key' => 'other_mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Up to two moods (value 3 or less) to discard', 'scope' => 'any', 'includes_self' => true, 'filter' => ['max_value' => 3], 'count' => ['max' => 2, 'zero_ok' => true]],
        ],
        'chaos_095' => [
            ['key' => 'discard_mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Two of your other moods to discard (boosts this mood to 9)', 'scope' => 'own', 'count' => ['min' => 2, 'max' => 2, 'zero_ok' => true]],
        ],
        'chaos_096' => [
            ['key' => 'opponent_mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => "Two of the same opponent's moods (they choose which one to give up)", 'scope' => 'other', 'excludes_teammate' => true, 'count' => ['min' => 2, 'max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'same_owner']],
            ['key' => 'own_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your moods to give back (required if choosing opponent moods above)', 'scope' => 'own'],
        ],
        'chaos_098' => [
            ['key' => 'confirm', 'type' => 'bool', 'required' => false, 'label' => 'Put all other moods with value 2 or less into the discard pile'],
        ],
        'chaos_099' => [
            ['key' => 'value', 'type' => 'value', 'required' => true, 'min' => 0, 'max' => 3, 'label' => 'Value (0-3) -- discards every other mood showing it'],
        ],
        'chaos_101' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Moods to put into the discard pile (value 3 or less, up to 2, one per player)', 'scope' => 'any', 'filter' => ['max_value' => 3], 'count' => ['max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'distinct_owners']],
        ],
        'chaos_103' => [
            ['key' => 'return_mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Any number of your other moods to return to hand (grants that many extra plays)', 'scope' => 'own', 'count' => ['zero_ok' => true]],
        ],
        'chaos_105' => [
            ['key' => 'confirm', 'type' => 'bool', 'required' => false, 'label' => 'Put all other moods into the discard pile'],
        ],
        'chaos_107' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => true, 'label' => 'Player to go first next round', 'scope' => 'any'],
        ],
        'chaos_118' => [
            // 'hand_card_id' deliberately has no entry here anymore -- see
            // chaos_058's own comment above (same reason, same fix).
            ['key' => 'recipient_player_id', 'type' => 'player', 'required' => false, 'label' => 'Player to receive a card from your hand (you choose which after confirming)', 'scope' => 'other'],
        ],
        'chaos_128' => [
            ['key' => 'discard_card_id', 'type' => 'discard_card', 'required' => false, 'label' => 'Discard pile card to take into your hand (an extra play is granted regardless)'],
        ],
        'chaos_133' => [
            ['key' => 'color', 'type' => 'mode', 'required' => true, 'label' => 'Color (counts matching moods in play + discard pile, +2 value each)', 'options' => ['white', 'blue', 'black', 'red', 'green']],
        ],
    ];

    public static function forEffectKey(?string $effectKey): array
    {
        if ($effectKey === null) {
            return [];
        }

        return self::SCHEMA[$effectKey] ?? [];
    }
}
