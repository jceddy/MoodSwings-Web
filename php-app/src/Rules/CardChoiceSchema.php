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
 *     type: string,         // player|mood|hand_card|discard_card|mode|value|bool
 *     label: string,
 *     required: bool,       // true only for requireInt/requireString or a mandatory to-play cost
 *     multi?: bool,         // true for ints()-backed (possibly multiple) fields
 *     scope?: string,       // player: any|other -- mood: own|other|any
 *     options?: string[],   // mode only
 *     min?: int, max?: int, // value only
 * }
 *
 * Two known gaps, both intentionally out of scope here: Scorn's and
 * Validation's reactToAnotherPlay() choices (they fire while playing a
 * *different* card, not the schema for the card being played) and
 * Duplicity's nested PlayerChoices::sub() repeat-with-fresh-choices
 * mechanic (handled directly by MoodPlayService, not any MoodEffect).
 * Omitting a choice here just means that optional input is never sent,
 * which was already a legal (declining) choice for all of them.
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
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (boosts this mood\'s value to 5)'],
        ],
        'imagination' => [
            ['key' => 'color', 'type' => 'mode', 'required' => true, 'options' => self::FIVE_COLORS, 'label' => 'Color to declare for every mood in play'],
        ],
        'courage' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to discard (value 5+, up to 2, one per player)'],
        ],
        'conviction' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => true, 'label' => 'Mood to move to the bottom of the deck'],
        ],
        'zeal' => [
            ['key' => 'hand_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to bottom-deck and redraw'],
        ],
        'faith' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (must be green or blue)'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to suppress (required if discarding a card above)'],
        ],
        'guile' => [
            ['key' => 'discard_card_ids', 'type' => 'hand_card', 'multi' => true, 'required' => true, 'label' => 'Exactly 2 cards to discard (cost to play this card)'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'other', 'required' => true, 'label' => "An opponent's mood to take"],
        ],
        'envy' => [
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => true, 'label' => 'Your mood to discard (cost to play this card)'],
        ],
        'fascination' => [
            ['key' => 'give_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to give away (must be blue or black)'],
            ['key' => 'recipient_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Player to give it to (required if giving a card)'],
        ],
        'wonder' => [
            ['key' => 'color', 'type' => 'mode', 'required' => true, 'options' => self::FIVE_COLORS, 'label' => 'Color to declare'],
        ],
        'anger' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to discard (combined value 5 or less)'],
        ],
        'self_loathing' => [
            ['key' => 'discard_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => true, 'label' => 'Your mood(s) to discard (cost to play this card, one or more)'],
        ],
        'pacifism' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to suppress (up to 2, one per player)'],
        ],
        'repentance' => [
            ['key' => 'value', 'type' => 'value', 'required' => false, 'min' => 0, 'max' => 12, 'label' => 'Value to suppress (every mood showing it)'],
        ],
        'hate' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to move to the bottom of the deck'],
        ],
        'wrath' => [
            ['key' => 'discard_all_other_moods', 'type' => 'bool', 'required' => false, 'label' => 'Discard every other mood in play'],
        ],
        'rage' => [
            ['key' => 'discard_qualifying_moods', 'type' => 'bool', 'required' => false, 'label' => 'Discard every mood valued 3 or less'],
        ],
        'anxiety' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Odd-valued moods to return to hand (up to 2, one per player)'],
        ],
        'guilt' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => true, 'options' => ['single', 'all'], 'label' => 'Suppress one qualifying mood, or all of them'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to suppress (black or red; required if mode is single)'],
        ],
        'shame' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => "Card to discard (suppresses other moods sharing its color)"],
        ],
        'spite' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Even-valued moods to discard (up to 2, one per player)'],
        ],
        'rebellion' => [
            ['key' => 'value', 'type' => 'value', 'required' => true, 'min' => 0, 'max' => 3, 'label' => 'Value to discard (every mood showing it)'],
        ],
        'shock' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to discard (value 3 or less, up to 2, one per player)'],
        ],
        'bravado' => [
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Another of your moods to discard (unlocks an extra play)'],
        ],
        'neurosis' => [
            ['key' => 'hand_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => true, 'label' => 'Your mood(s) to return to hand (cost to play this card, one or more)'],
        ],
        'regret' => [
            ['key' => 'hand_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => true, 'label' => 'Exactly 2 of your moods to return to hand (cost to play this card)'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'other', 'required' => true, 'label' => "An opponent's mood to steal into your hand"],
        ],
        'cruelty' => [
            ['key' => 'opponent_player_ids', 'type' => 'player', 'scope' => 'other', 'multi' => true, 'required' => false, 'label' => 'Opponents to target (each must have 2+ moods)'],
        ],
        'indecisiveness' => [
            ['key' => 'opponent_player_ids', 'type' => 'player', 'scope' => 'other', 'multi' => true, 'required' => false, 'label' => 'Opponents to target (each must have 2+ moods)'],
        ],
        'rejection' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => '2 moods to discard (must share a color or value)'],
        ],
        'denial' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => '2 moods to return to hand (must share a color or value)'],
        ],
        'disorientation' => [
            ['key' => 'value', 'type' => 'value', 'required' => false, 'min' => 0, 'max' => 12, 'label' => 'Value to return to hand (every mood showing it)'],
        ],
        'panic' => [
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to return to hand (up to 2, one per player)'],
        ],
        'worry' => [
            ['key' => 'hand_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Your white or black mood to return to hand'],
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to return to hand (value 3 or less, up to 2; only if you returned a mood above)'],
        ],
        'contempt' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'options' => ['single', 'all'], 'label' => 'Suppress one qualifying mood, or all of them'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to suppress (green or white; required if mode is single)'],
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
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => false, 'label' => 'Player to target (must have cards in hand)'],
        ],
        'suspicion' => [
            ['key' => 'player_ids', 'type' => 'player', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Players to target'],
        ],
        'curiosity' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => false, 'label' => 'Player whose hand to reveal a card from'],
        ],
        'condescension' => [
            ['key' => 'give_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to give away'],
            ['key' => 'recipient_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Player to give it to (required if giving a card)'],
        ],
        'cynicism' => [
            ['key' => 'discard_card_id', 'type' => 'discard_card', 'required' => false, 'label' => 'Discard-pile card to give away'],
            ['key' => 'recipient_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Player to give it to (required if moving a card)'],
        ],
        'infatuation' => [
            ['key' => 'discard_mood_ids', 'type' => 'mood', 'scope' => 'own', 'multi' => true, 'required' => false, 'label' => '2 of your other moods to discard'],
        ],
        'hostility' => [
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Your black or green mood to discard'],
            ['key' => 'target_mood_ids', 'type' => 'mood', 'scope' => 'any', 'multi' => true, 'required' => false, 'label' => 'Moods to discard (value 3 or less, up to 2; only if you discarded a mood above)'],
        ],
        'malice' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => false, 'label' => 'Player to target (must have 2+ moods)'],
        ],
        'hesitation' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => true, 'options' => ['single', 'all'], 'label' => 'Discard one qualifying mood, or all of them'],
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to discard (red or green; required if mode is single)'],
        ],
        'nostalgia' => [
            ['key' => 'discard_card_id', 'type' => 'discard_card', 'required' => false, 'label' => 'Discard-pile card to take into your hand'],
        ],
        'angst' => [
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Your blue or red mood to discard'],
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
            ['key' => 'mode', 'type' => 'mode', 'required' => true, 'options' => ['refresh', 'rotate'], 'label' => 'Refresh your own hand, or rotate hands with everyone'],
            ['key' => 'direction', 'type' => 'mode', 'required' => false, 'options' => ['left', 'right'], 'label' => 'Direction to rotate (required if mode is rotate)'],
        ],
        'instability' => [
            ['key' => 'candidate_mood_ids', 'type' => 'mood', 'scope' => 'other', 'multi' => true, 'required' => false, 'label' => "2 of one opponent's moods (the engine picks one at random)"],
            ['key' => 'given_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => false, 'label' => 'Your mood to give in exchange (required if targeting an opponent above)'],
        ],
        'betrayal' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => true, 'label' => 'Your mood to give away'],
            ['key' => 'recipient_player_id', 'type' => 'player', 'scope' => 'other', 'required' => true, 'label' => 'Player to give it to'],
        ],
        'sneakiness' => [
            ['key' => 'opponent_player_id', 'type' => 'player', 'scope' => 'other', 'required' => true, 'label' => 'Opponent to swap scores with at scoring time'],
        ],
        'awe' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'any', 'required' => true, 'label' => 'Player who goes first next round'],
        ],
        'recklessness' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'other', 'required' => false, 'label' => "An opponent's mood to take (returns to them after scoring if you still hold it)"],
        ],
        'pride' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Player with more moods in play than you'],
        ],
        'corruption' => [
            ['key' => 'mode', 'type' => 'mode', 'required' => false, 'options' => ['cycle', 'double_win'], 'label' => 'Double your next win, or cycle discard-pile cards to the bottom of the deck'],
            ['key' => 'discard_card_ids', 'type' => 'discard_card', 'multi' => true, 'required' => false, 'label' => 'Up to 2 discard-pile cards to cycle (required if mode is cycle)'],
        ],
        'doubt' => [
            ['key' => 'reveal_card_ids', 'type' => 'hand_card', 'multi' => true, 'required' => false, 'label' => 'Hand cards to reveal (redrawn; their colors are banned next round)'],
        ],
        'generosity' => [
            ['key' => 'target_player_id', 'type' => 'player', 'scope' => 'other', 'required' => true, 'label' => 'Player to bank an extra play for on their next turn'],
        ],
        'arrogance' => [
            ['key' => 'opponent_player_id', 'type' => 'player', 'scope' => 'other', 'required' => false, 'label' => 'Opponent to target (one of their qualifying moods is taken at random)'],
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
            ['key' => 'discard_mood_id', 'type' => 'mood', 'scope' => 'own', 'required' => true, 'label' => 'Your mood to discard (cost to play this card)'],
        ],
        'bliss' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => true, 'label' => "Card to discard (cost to play this card; its color decides your scoring bonus)"],
        ],
        'encouragement' => [
            ['key' => 'target_mood_id', 'type' => 'mood', 'scope' => 'any', 'required' => false, 'label' => 'Mood to apply its dice value to (must have one printed)'],
        ],
        'embarrassment' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (base value 4, 5, or 6; boosts this mood\'s value to 5)'],
        ],
        'cheer' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (base value 0, 2, 4, or 6; boosts this mood\'s value to 5)'],
        ],
        'delight' => [
            ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'label' => 'Card to discard (base value 1, 3, or 5; boosts this mood\'s value to 5)'],
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
}
