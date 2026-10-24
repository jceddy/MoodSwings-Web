using System;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;
using Newtonsoft.Json.Linq;

namespace MoodSwings.Core
{
    /// <summary>A card the sample game uses: what the table needs to draw it (see tools/make_tour_cards.py).</summary>
    public sealed class SampleCard
    {
        public string Name { get; set; }

        /// <summary>Which printed card it is, and so which art.</summary>
        public int CatalogCardId { get; set; }

        public string Color { get; set; }

        public int BaseValue { get; set; }

        /// <summary>The value printed upside down in the other corner, or null when the card has none.</summary>
        public int? AltValue { get; set; }

        public string RulesText { get; set; }
    }

    /// <summary>A mood on the sample table.</summary>
    public sealed class SampleMood
    {
        public string Card { get; set; }

        /// <summary>Lying on its side, worth 0.</summary>
        public bool Suppressed { get; set; }

        /// <summary>On its alternate value, so it is turned upside down.</summary>
        public bool Alt { get; set; }

        /// <summary>The alternate value was locked in by the card's "After playing this mood" text (as opposed to a live condition).</summary>
        public bool Locked { get; set; }

        public SampleMood Copy() => (SampleMood)MemberwiseClone();
    }

    public sealed class SampleSeat
    {
        public string Name { get; set; }

        /// <summary>The seat you are shown from (along the bottom).</summary>
        public bool IsYou { get; set; }

        /// <summary>Rounds won so far.</summary>
        public int Wins { get; set; }

        /// <summary>Cards in hand.</summary>
        public int Hand { get; set; }

        public List<SampleMood> Moods { get; set; } = new List<SampleMood>();

        public SampleSeat Copy() => new SampleSeat
        {
            Name = Name, IsYou = IsYou, Wins = Wins, Hand = Hand, Moods = Moods.Select(m => m.Copy()).ToList(),
        };
    }

    /// <summary>The table at one moment of the sample game.</summary>
    public sealed class SampleState
    {
        public int Round { get; set; }

        /// <summary>Whose turn it is, or null between rounds.</summary>
        public string Turn { get; set; }

        public SampleSeat You { get; set; }

        public SampleSeat Them { get; set; }

        /// <summary>The shared discard pile, newest last.</summary>
        public List<string> Discard { get; set; } = new List<string>();

        public IEnumerable<SampleSeat> Seats => new[] { Them, You };

        public SampleState Copy() => new SampleState
        {
            Round = Round, Turn = Turn, You = You.Copy(), Them = Them.Copy(), Discard = Discard.ToList(),
        };
    }

    /// <summary>One step of the sample game: the table, what to look at on it, and what is being said about it.</summary>
    public sealed class SampleStep
    {
        public string Title { get; set; }

        public string Commentary { get; set; }

        /// <summary>The rules this step brings up, one short line each.</summary>
        public List<string> Notes { get; set; } = new List<string>();

        public SampleState State { get; set; }

        /// <summary>What to frame: "score:MarkBot", "mood:Superiority" or "discard".</summary>
        public List<string> Focus { get; set; } = new List<string>();
    }

    /// <summary>
    /// A short game between two bots, played out one move at a time for the welcome tour: MarkBot (you, along the bottom) and
    /// AriBot, a Traditional game with the Structure deck, five rounds. Nothing here talks to a server -- every table is
    /// worked out from the cards (<see cref="LoadCards"/>) and what happened. UI-free.
    /// </summary>
    public static class SampleGame
    {
        public const string You = "MarkBot";
        public const string Them = "AriBot";
        public const int WinsNeeded = 3;

        private static Dictionary<string, SampleCard> _cards = new Dictionary<string, SampleCard>();
        private static List<SampleStep> _steps;

        public static bool CardsLoaded => _cards.Count > 0;

        /// <summary>Reads the cards (Assets/Resources/Tour/sample-cards.json): {"cards": [{name, catalog_card_id, color, base_value, alt_value, rules_text}]}.</summary>
        public static void LoadCards(string json)
        {
            var cards = new Dictionary<string, SampleCard>();
            foreach (var entry in JObject.Parse(json)["cards"])
            {
                var card = new SampleCard
                {
                    Name = (string)entry["name"],
                    CatalogCardId = (int)entry["catalog_card_id"],
                    Color = (string)entry["color"],
                    BaseValue = (int)entry["base_value"],
                    AltValue = entry["alt_value"] != null && entry["alt_value"].Type != JTokenType.Null ? (int?)entry["alt_value"] : null,
                    RulesText = (string)entry["rules_text"],
                };
                cards[card.Name] = card;
            }

            _cards = cards;
        }

        public static SampleCard Card(string name) =>
            _cards.TryGetValue(name, out var card) ? card : throw new KeyNotFoundException("The sample game has no card named " + name);

        public static bool HasCard(string name) => _cards.ContainsKey(name);

        /// <summary>What a mood is worth right now.</summary>
        public static int Value(SampleMood mood)
        {
            if (mood.Suppressed)
            {
                return 0;
            }

            var card = Card(mood.Card);
            return mood.Alt && card.AltValue.HasValue ? card.AltValue.Value : card.BaseValue;
        }

        public static int Score(SampleSeat seat) => seat.Moods.Sum(Value);

        /// <summary>The mood as the board's card drawing wants it, so it turns, lies down and gets its badge exactly as a real one does.</summary>
        public static BoardCard ToBoardCard(SampleMood mood, int cardId)
        {
            var card = Card(mood.Card);
            return new BoardCard
            {
                CardId = cardId,
                CatalogCardId = card.CatalogCardId,
                Name = card.Name,
                Color = card.Color,
                BaseColor = card.Color,
                Value = Value(mood),
                BaseValue = card.BaseValue,
                AltValue = card.AltValue,
                HasDiceValue = card.AltValue.HasValue,
                RulesText = card.RulesText,
                IsSuppressed = mood.Suppressed,
                ValueLocked = mood.Alt && mood.Locked,
                WhileInPlayAltValueActive = mood.Alt && !mood.Locked && !mood.Suppressed,
            };
        }

        /// <summary>The seat a focus key points at ("score:MarkBot"), the card ("mood:Laziness"), or null.</summary>
        public static SampleSeat SeatOf(SampleState state, string name) => state.Seats.FirstOrDefault(s => s.Name == name);

        public static IReadOnlyList<SampleStep> Steps => _steps ?? (_steps = Build());

        /// <summary>Which move of the game a step is (1 for the first), or 0 if it is not one of them.</summary>
        public static int NumberOf(SampleStep step) => step == null ? 0 : Steps.ToList().IndexOf(step) + 1;

        // --- the game ----------------------------------------------------------------------------------

        private sealed class Table
        {
            public SampleState State = new SampleState
            {
                Round = 1,
                You = new SampleSeat { Name = SampleGame.You, IsYou = true, Hand = 5 },
                Them = new SampleSeat { Name = SampleGame.Them, Hand = 5 },
            };

            public readonly List<SampleStep> Steps = new List<SampleStep>();

            public SampleSeat Seat(string name) => SeatOf(State, name);

            public SampleMood Mood(string name, string card) => Seat(name).Moods.First(m => m.Card == card);

            /// <summary>A card from the hand into play.</summary>
            public Table Play(string seat, string card, bool alt = false, bool locked = false)
            {
                Seat(seat).Hand--;
                Seat(seat).Moods.Add(new SampleMood { Card = card, Alt = alt, Locked = locked });
                return this;
            }

            /// <summary>A mood already in play goes to the discard pile.</summary>
            public Table Discard(string seat, string card)
            {
                Seat(seat).Moods.Remove(Mood(seat, card));
                State.Discard.Add(card);
                return this;
            }

            /// <summary>A card from the hand straight to the discard pile.</summary>
            public Table DiscardFromHand(string seat, string card)
            {
                Seat(seat).Hand--;
                State.Discard.Add(card);
                return this;
            }

            /// <summary>A card from the discard pile into play.</summary>
            public Table PlayFromDiscard(string seat, string card)
            {
                State.Discard.Remove(card);
                Seat(seat).Moods.Add(new SampleMood { Card = card });
                return this;
            }

            public Table Give(string from, string to, string card)
            {
                var mood = Mood(from, card);
                Seat(from).Moods.Remove(mood);
                Seat(to).Moods.Add(mood);
                return this;
            }

            public Table ToHand(string seat, string card)
            {
                Seat(seat).Moods.Remove(Mood(seat, card));
                Seat(seat).Hand++;
                return this;
            }

            public Table Alt(string seat, string card, bool on)
            {
                Mood(seat, card).Alt = on;
                return this;
            }

            public Table Suppress(string seat, string card, bool on)
            {
                Mood(seat, card).Suppressed = on;
                return this;
            }

            public Table Draw(string seat)
            {
                Seat(seat).Hand++;
                return this;
            }

            public Table Turn(string seat)
            {
                State.Turn = seat;
                return this;
            }

            public Table WinRound(string seat)
            {
                Seat(seat).Wins++;
                State.Turn = null;
                return this;
            }

            public Table NewRound(string first)
            {
                State.Round++;
                State.Turn = first;
                return this;
            }

            public Table Snap(string title, string commentary, string[] notes, params string[] focus)
            {
                Steps.Add(new SampleStep
                {
                    Title = title,
                    Commentary = commentary,
                    Notes = notes.ToList(),
                    Focus = focus.ToList(),
                    State = State.Copy(),
                });
                return this;
            }
        }

        private static readonly string[] NoNotes = new string[0];

        private static List<SampleStep> Build()
        {
            var t = new Table();

            // --- round one ---
            t.Turn(You).Snap(
                "Setting up",
                "MarkBot (you, along the bottom of the table) and AriBot (across from you) are playing a Traditional game with the "
                + "Structure deck. Each player draws 5 cards. It is decided at random who goes first, and it is MarkBot.",
                new[] { "On your turn, play 1 card." },
                "score:" + You, "score:" + Them);

            t.Play(You, "Laziness").Turn(Them).Snap(
                "MarkBot plays Laziness",
                "In the top right corner of Laziness there is a die with four dots in it: Laziness has a value of four. There is no "
                + "rules text on it, so that is all it does. MarkBot's scoreboard shows 4. Now it is AriBot's turn.",
                NoNotes,
                "mood:Laziness", "score:" + You);

            t.Play(Them, "Charity").Snap(
                "AriBot plays Charity",
                "Charity is worth one point and says: After playing this mood, you may play an additional mood this turn. The words "
                + "in bold are instructions: AriBot gets to play another mood this turn.",
                new[] { "Bold text on a card gives you additional instructions." },
                "mood:Charity");

            t.Play(Them, "Superiority").Snap(
                "AriBot plays Superiority",
                "Superiority says: While in play, this mood's value is 7 if you have more moods than each other player. Moods are "
                + "the cards in play, not cards in your hand, in the discard pile or in the deck. Superiority has a black die, "
                + "while Charity and Laziness have a white die. A white die means the score never changes: that is the score. "
                + "A black die means the score can change, so pay attention to the card.",
                new[] { "A black die means the score can change." },
                "mood:Superiority", "mood:Charity", "mood:Laziness");

            t.Alt(Them, "Superiority", true).Snap(
                "Superiority goes up to 7",
                "AriBot has two moods and MarkBot has one, so Superiority's condition is met and it is worth seven, shown by "
                + "turning the card upside down. AriBot's scoreboard shows 8, against MarkBot's 4.",
                NoNotes,
                "mood:Superiority", "score:" + Them, "score:" + You);

            t.WinRound(Them).Snap(
                "AriBot wins round one",
                "AriBot has 8 points and MarkBot has 4, so AriBot wins the round.",
                new[]
                {
                    "The highest score wins the round.",
                    "Win 3 rounds to win the game.",
                    "The winner of a round goes first in the next one, and the first player wins ties.",
                    "The loser of a round draws a card.",
                },
                "score:" + Them);

            // --- round two ---
            t.NewRound(Them).Draw(You).Play(Them, "Serenity").Turn(You).Snap(
                "Round two: AriBot plays Serenity",
                "MarkBot lost round one, so it drew a card, and AriBot, the winner, goes first. Serenity is another card with a "
                + "While in play effect: this mood's value is 6 if you have an even number of moods (including this one). This is "
                + "AriBot's third mood, and three is odd, so Serenity stays at its base value of 3. AriBot's scoreboard shows 11. "
                + "Now it is MarkBot's turn.",
                NoNotes,
                "mood:Serenity", "score:" + Them);

            t.Play(You, "Rage").Discard(Them, "Serenity").Discard(Them, "Charity").Alt(Them, "Superiority", false).Turn(null).Snap(
                "MarkBot plays Rage",
                "Rage says: After playing this mood, you may put all other moods with a value of 3 or less into the discard pile. "
                + "There is one discard pile, shared by everybody. AriBot has Serenity and Charity, both worth 3 or less, so both "
                + "go into the discard pile. Rage says all other moods, so Rage does not put itself there. AriBot lost two moods, so "
                + "it no longer has more moods than MarkBot, and Superiority goes back to 3, upright again. MarkBot has 6 points "
                + "and AriBot has 3.",
                NoNotes,
                "mood:Rage", "discard", "mood:Superiority", "score:" + You, "score:" + Them);

            t.WinRound(You).Snap(
                "MarkBot wins round two",
                "MarkBot has 6 points to AriBot's 3, so MarkBot wins round two and gets its first round win. It is 1 to 1.",
                NoNotes,
                "score:" + You);

            // --- round three ---
            t.NewRound(You).Draw(Them).Play(You, "Harmony").Snap(
                "Round three: MarkBot plays Harmony",
                "AriBot lost, so it drew a card, and MarkBot, the winner, goes first. Harmony is worth 2 and says: After playing "
                + "this mood, you may play an additional mood this turn from the discard pile.",
                NoNotes,
                "mood:Harmony", "discard");

            t.PlayFromDiscard(You, "Charity").Snap(
                "MarkBot plays Charity from the discard pile",
                "MarkBot plays Charity from the discard pile. Charity says: After playing this mood, you may play an additional "
                + "mood this turn.",
                NoNotes,
                "mood:Charity", "discard");

            t.Play(You, "Betrayal").Give(You, Them, "Charity").Turn(Them).Snap(
                "MarkBot plays Betrayal",
                "Betrayal is worth 6 and says: After playing this mood, give one of your moods to another player. After scoring, "
                + "that mood becomes yours again. MarkBot gives Charity to AriBot. MarkBot's scoreboard shows 14 points and "
                + "AriBot's shows 4. Now it is AriBot's turn.",
                NoNotes,
                "mood:Betrayal", "mood:Charity", "score:" + You, "score:" + Them);

            t.Play(Them, "Indifference").Snap(
                "AriBot plays Indifference",
                "Indifference is worth 4 points, so AriBot now has 8. MarkBot has 14 and AriBot has 8.",
                NoNotes,
                "mood:Indifference", "score:" + Them);

            t.WinRound(You).Give(Them, You, "Charity").Snap(
                "MarkBot wins round three",
                "MarkBot wins its second round. One more round win and MarkBot wins the game. After scoring, Betrayal's text "
                + "brings Charity back: it becomes MarkBot's again.",
                new[] { "Win 3 rounds to win the game." },
                "score:" + You, "mood:Charity");

            // --- round four ---
            t.NewRound(You).Draw(Them).Play(You, "Cheer", alt: true, locked: true).DiscardFromHand(You, "Doubt").Turn(Them).Snap(
                "Round four: MarkBot plays Cheer",
                "AriBot lost, so it drew a card, and MarkBot goes first. Cheer says: After playing this mood, you may discard a "
                + "card from your hand with a 0, 2, 4, or 6 in its top right corner. If you do, this value becomes 5. MarkBot "
                + "discards Doubt, and Cheer is worth 5 rather than 3. Cheer has a value in each corner, so MarkBot chooses which "
                + "one to use: 5, shown by turning the card upside down. MarkBot's scoreboard shows 20.",
                NoNotes,
                "mood:Cheer", "discard", "score:" + You);

            t.Play(Them, "Kindness").Snap(
                "AriBot plays Kindness",
                "Kindness says: After playing this mood, you may play an additional mood this turn if it has a 1, 3, or 5 in its "
                + "top right corner.",
                NoNotes,
                "mood:Kindness");

            t.Play(Them, "Meekness").Suppress(You, "Cheer", true).Suppress(You, "Betrayal", true).Turn(null).Snap(
                "AriBot plays Meekness",
                "Meekness is worth 1 and says: After playing this mood, suppress all moods with a value of 5 or more. Those moods "
                + "remain suppressed for as long as you have this mood. MarkBot has a 5 and a 6, so they are turned sideways. A "
                + "suppressed mood is still on the table, but its value is zero.",
                new[] { "Suppressed moods do not count toward the score.", "A suppressed mood is turned sideways." },
                "mood:Cheer", "mood:Betrayal", "score:" + You);

            t.Snap(
                "Meekness keeps working",
                "The top right corner of Meekness has an indicator next to the value die. That means the card is doing something "
                + "while it is in play: here it keeps MarkBot's moods locked down. If MarkBot ever gets rid of Meekness it gets "
                + "its points back, but for now Meekness is here to stay, and AriBot gets an additional three points. MarkBot has "
                + "9 points and AriBot has 10.",
                NoNotes,
                "mood:Meekness", "score:" + Them, "score:" + You);

            t.WinRound(Them).Draw(You).Snap(
                "AriBot wins round four",
                "At the end of round four MarkBot has 9 points and AriBot has 10, so AriBot gets its second round win. It is 2 to 2, "
                + "so whoever wins the next round wins the whole game. Since MarkBot lost, it draws a card.",
                NoNotes,
                "score:" + Them, "score:" + You);

            // --- round five ---
            t.NewRound(Them).Play(Them, "Self-Loathing").Discard(Them, "Kindness").Turn(You).Snap(
                "Round five: AriBot plays Self-Loathing",
                "AriBot won round four, so it goes first. Self-Loathing is worth 6 points and says: To play this card, put one or "
                + "more of your moods into the discard pile. Because it says \"to play this card\", there is something AriBot must "
                + "do to put it into play: here, putting a mood into the discard pile. Meekness is worth the fewest points among "
                + "AriBot's moods, but it is keeping MarkBot's moods locked down, so AriBot puts Kindness into the discard pile "
                + "instead, netting AriBot 4 points.",
                NoNotes,
                "mood:Self-Loathing", "discard", "score:" + Them);

            t.Play(You, "Panic").Turn(null).Snap(
                "MarkBot plays Panic",
                "On MarkBot's final turn it has one card, and it plays it: Panic, worth 1. Panic says: After playing this mood, "
                + "choose up to two players. For each chosen player, put one of their moods into their hand. You can't put this "
                + "mood into your hand this way. MarkBot chooses AriBot and can put any of its moods back in its hand. "
                + "Self-Loathing has the most points, but Meekness is locking down more points' worth of moods on MarkBot's side.",
                NoNotes,
                "mood:Panic", "mood:Self-Loathing", "mood:Meekness");

            t.ToHand(Them, "Meekness").Suppress(You, "Cheer", false).Suppress(You, "Betrayal", false).Snap(
                "Meekness returns to AriBot's hand",
                "Meekness goes back to AriBot's hand, so Cheer and Betrayal are no longer suppressed and are worth their points "
                + "again. MarkBot's scoreboard shows 21, AriBot's shows 13.",
                NoNotes,
                "mood:Cheer", "mood:Betrayal", "score:" + You, "score:" + Them);

            t.WinRound(You).Snap(
                "MarkBot wins the game",
                "MarkBot wins the final round, its third round win, and with it the game: 3 rounds to 2.",
                new[] { "Win 3 rounds to win the game." },
                "score:" + You);

            return t.Steps;
        }
    }
}
