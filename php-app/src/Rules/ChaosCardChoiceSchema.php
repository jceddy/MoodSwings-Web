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
 * same as an unregistered key.
 */
final class ChaosCardChoiceSchema
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private const SCHEMA = [
        'chaos_006' => [
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => true, 'label' => 'Mood to move to the bottom of the deck (owner draws a card)', 'scope' => 'any', 'includes_self' => true],
        ],
        'chaos_007' => [
            ['key' => 'target_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => 'Up to two players (each discards one qualifying mood: value 5+)', 'scope' => 'any', 'count' => ['max' => 2, 'zero_ok' => true]],
        ],
        'chaos_008' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card (base value 0-3) to discard -- boosts this mood to 5', 'filter' => ['values' => [0, 1, 2, 3]]],
        ],
        'chaos_012' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (green or blue)', 'filter' => ['colors' => ['green', 'blue']]],
            ['key' => 'suppress_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'Mood to suppress (required if discarding a card above)', 'scope' => 'any', 'includes_self' => true],
        ],
        'chaos_014' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'label' => 'Suppress one black/red mood, or all of them', 'options' => ['single', 'all']],
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'Mood to suppress (black or red; required if mode is single)', 'scope' => 'any', 'includes_self' => true, 'filter' => ['colors' => ['black', 'red']]],
        ],
        'chaos_020' => [
            ['key' => 'target_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => 'Up to two players (each has one mood suppressed)', 'scope' => 'any', 'count' => ['max' => 2, 'zero_ok' => true]],
        ],
        'chaos_022' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => false, 'label' => 'Player with more moods than you', 'scope' => 'any', 'filter' => ['more_moods_than_viewer' => true]],
        ],
        'chaos_023' => [
            ['key' => 'value', 'type' => 'value', 'required' => false, 'min' => 0, 'max' => 12, 'label' => 'Value to suppress (every other mood showing it)'],
        ],
        'chaos_025' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => "Card to discard (its color determines what gets suppressed)"],
        ],
        'chaos_028' => [
            ['key' => 'target_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => 'Up to two players (each returns one qualifying mood to hand: odd value)', 'scope' => 'any', 'count' => ['max' => 2, 'zero_ok' => true]],
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
        'chaos_036' => [
            ['key' => 'hand_card_ids', 'type' => 'hand_card', 'required' => false, 'multi' => true, 'label' => 'Hand cards to reveal, bottom-deck and redraw (bans their colors next round)', 'count' => ['zero_ok' => true]],
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
            ['key' => 'target_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => "Up to two players (each returns one mood to hand; can't target this mood on yourself)", 'scope' => 'any', 'count' => ['max' => 2, 'zero_ok' => true]],
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
        'chaos_053' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard for an additional play'],
        ],
        'chaos_054' => [
            ['key' => 'discard_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your blue/red moods to discard (grants a play from the discard pile)', 'scope' => 'own', 'includes_self' => true, 'filter' => ['colors' => ['blue', 'red']]],
        ],
        'chaos_056' => [
            ['key' => 'mood_card_id', 'type' => 'mood', 'required' => false, 'label' => "Opponent's mood to reduce (discarded instead if it would go below 0)", 'scope' => 'other', 'excludes_teammate' => true],
        ],
        'chaos_058' => [
            ['key' => 'hand_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to give away'],
            ['key' => 'recipient_player_id', 'type' => 'player', 'required' => false, 'label' => 'Player to receive the card (required if giving a card)', 'scope' => 'other'],
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
            ['key' => 'target_player_id', 'type' => 'player', 'required' => false, 'label' => 'Other player to force reveal + give a random hand card', 'scope' => 'other'],
        ],
        'chaos_068' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => true, 'label' => 'Player (2+ moods) whose two random moods trigger a color-match discard', 'scope' => 'any', 'filter' => ['min_mood_count' => 2]],
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
            ['key' => 'target_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => 'Up to two players (each discards one qualifying mood: even value)', 'scope' => 'any', 'count' => ['max' => 2, 'zero_ok' => true]],
        ],
        'chaos_078' => [
            ['key' => 'target_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => 'Players who each discard a random hand card', 'scope' => 'any', 'count' => ['zero_ok' => true]],
        ],
        'chaos_080' => [
            ['key' => 'mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Any number of moods (total value 5 or less) to discard', 'scope' => 'any', 'includes_self' => true, 'count' => ['zero_ok' => true], 'constraint' => ['type' => 'max_total_value', 'max' => 5]],
        ],
        'chaos_082' => [
            ['key' => 'opponent_player_id', 'type' => 'player', 'required' => false, 'label' => "Opponent whose white/blue mood you'll take (random; returns when this mood leaves play)", 'scope' => 'other', 'excludes_teammate' => true],
        ],
        'chaos_084' => [
            ['key' => 'discard_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your other moods to discard for an extra play', 'scope' => 'own'],
        ],
        'chaos_086' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => true, 'label' => 'Other player who gives you a random hand card', 'scope' => 'other'],
        ],
        'chaos_087' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card (base value 4-6) to discard -- boosts this mood to 5', 'filter' => ['values' => [4, 5, 6]]],
        ],
        'chaos_094' => [
            ['key' => 'discard_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your black/green moods to discard', 'scope' => 'own', 'includes_self' => true, 'filter' => ['colors' => ['black', 'green']]],
            ['key' => 'other_mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Up to two moods (value 3 or less) to discard', 'scope' => 'any', 'includes_self' => true, 'filter' => ['max_value' => 3], 'count' => ['max' => 2, 'zero_ok' => true]],
        ],
        'chaos_095' => [
            ['key' => 'discard_mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Two of your other moods to discard (boosts this mood to 9)', 'scope' => 'own', 'count' => ['min' => 2, 'max' => 2, 'zero_ok' => true]],
        ],
        'chaos_096' => [
            ['key' => 'opponent_mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => "Two of the same opponent's moods (one taken at random)", 'scope' => 'other', 'excludes_teammate' => true, 'count' => ['min' => 2, 'max' => 2, 'zero_ok' => true], 'constraint' => ['type' => 'same_owner']],
            ['key' => 'own_mood_card_id', 'type' => 'mood', 'required' => false, 'label' => 'One of your moods to give back (required if choosing opponent moods above)', 'scope' => 'own'],
        ],
        'chaos_098' => [
            ['key' => 'confirm', 'type' => 'bool', 'required' => false, 'label' => 'Put all other moods with value 2 or less into the discard pile'],
        ],
        'chaos_099' => [
            ['key' => 'value', 'type' => 'value', 'required' => true, 'min' => 0, 'max' => 3, 'label' => 'Value (0-3) -- discards every other mood showing it'],
        ],
        'chaos_101' => [
            ['key' => 'target_player_ids', 'type' => 'player', 'required' => false, 'multi' => true, 'label' => 'Up to two players (each discards one qualifying mood: value 3 or less)', 'scope' => 'any', 'count' => ['max' => 2, 'zero_ok' => true]],
        ],
        'chaos_103' => [
            ['key' => 'return_mood_card_ids', 'type' => 'mood', 'required' => false, 'multi' => true, 'label' => 'Any number of your other moods to return to hand (grants that many extra plays)', 'scope' => 'own', 'count' => ['zero_ok' => true]],
        ],
        'chaos_105' => [
            ['key' => 'confirm', 'type' => 'bool', 'required' => false, 'label' => 'Put all other moods into the discard pile'],
        ],
        'chaos_106' => [
            ['key' => 'hand_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Hand card to bottom-deck (then draw)'],
        ],
        'chaos_107' => [
            ['key' => 'target_player_id', 'type' => 'player', 'required' => true, 'label' => 'Player to go first next round', 'scope' => 'any'],
        ],
        'chaos_110' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card (base value 0, 2, 4, or 6) to discard -- boosts this mood to 5', 'filter' => ['values' => [0, 2, 4, 6]]],
        ],
        'chaos_111' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card (base value 1, 3, or 5) to discard -- boosts this mood to 5', 'filter' => ['values' => [1, 3, 5]]],
        ],
        'chaos_118' => [
            ['key' => 'hand_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Blue/black card to reveal and give away', 'filter' => ['colors' => ['blue', 'black']]],
            ['key' => 'recipient_player_id', 'type' => 'player', 'required' => false, 'label' => 'Player to receive the card (required if giving a card)', 'scope' => 'other'],
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
