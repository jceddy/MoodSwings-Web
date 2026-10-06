using System;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;
using Newtonsoft.Json.Linq;

namespace MoodSwings.Core
{
    /// <summary>One thing to pick in a choice field: a player, a mood, a card, a mode, a number.</summary>
    public sealed class ChoiceOption
    {
        /// <summary>What's sent to the server (an id, or a mode's text), as text.</summary>
        public string Id { get; set; }

        public string Label { get; set; }

        /// <summary>Moods are listed under whoever owns them; empty for other kinds.</summary>
        public string Group { get; set; }
    }

    /// <summary>
    /// The answers to a card's choices (or to a pending decision's question),
    /// built field by field. The server describes what to ask for; this works
    /// out the legal options for each field from the board, tracks what's been
    /// picked, says whether it's acceptable, and builds the <c>choices</c> body
    /// to send. It mirrors the web client's choices panel rule for rule (the
    /// server checks everything again, so these checks are only so a mistake is
    /// caught before sending). No UI in here.
    /// </summary>
    public sealed class ChoiceForm
    {
        private readonly GameState _state;
        private readonly BoardCard _card;
        private readonly bool _playing;
        private readonly List<ChoiceField> _creativityPicker;
        private readonly List<ChoiceField> _creativityNoCopyExtras;
        private readonly Dictionary<string, List<string>> _selected = new Dictionary<string, List<string>>();
        private List<ChoiceField> _fields;
        private bool _copyCostPayable = true;

        private ChoiceForm(GameState state, BoardCard card, bool playing, List<ChoiceField> fields)
        {
            _state = state;
            _card = card;
            _playing = playing;
            _fields = fields;

            if (card != null && card.EffectKey == "creativity" && card.CopySimulation != null)
            {
                _creativityPicker = fields.Where(f => f.Key == "copy_card_id").ToList();
                _creativityNoCopyExtras = fields.Where(f => f.Key != "copy_card_id").ToList();
            }

            InitializeDefaults(_fields, null);
        }

        /// <summary>The questions to ask before playing <paramref name="card"/> from your hand.</summary>
        public static ChoiceForm ForCard(GameState state, BoardCard card) =>
            new ChoiceForm(state, card, true, new List<ChoiceField>(card.ChoiceFields ?? new List<ChoiceField>()));

        /// <summary>The one question a pending decision is waiting on you to answer.</summary>
        public static ChoiceForm ForDecision(GameState state, PendingDecision decision)
        {
            // Duplicity's repeat offer is about a card already played; it can't be offered
            // as a choice to itself. For every other decision nothing is being played.
            BoardCard card = null;
            if (decision.DecisionType == "duplicity_repeat_offer" && decision.PlayedCardId.HasValue)
            {
                card = state.InPlay.FirstOrDefault(c => c.CardId == decision.PlayedCardId.Value)
                    ?? new BoardCard { CardId = decision.PlayedCardId.Value };
            }

            return new ChoiceForm(state, card, false, new List<ChoiceField> { decision.Field });
        }

        /// <summary>The card being played; null when this answers a pending decision.</summary>
        public BoardCard Card => _playing ? _card : null;

        /// <summary>The top-level fields to show. For Creativity they change once a mood to copy is picked.</summary>
        public IReadOnlyList<ChoiceField> Fields => _fields;

        /// <summary>Raised when the set of fields changed (Creativity chose something to copy).</summary>
        public event Action FieldsChanged;

        public static string PathOf(string prefix, ChoiceField field) =>
            string.IsNullOrEmpty(prefix) ? field.Key : prefix + "." + field.Key;

        // --- options ---------------------------------------------------------------------------

        public IReadOnlyList<ChoiceOption> OptionsFor(ChoiceField field)
        {
            switch (field.Type)
            {
                case "player": return PlayerOptions(field);
                case "mood": return MoodOptions(field);
                case "hand_card":
                    return _state.You.Hand
                        .Where(c => _card == null || c.CardId != _card.CardId)
                        .Where(c => MatchesCardFilter(c, field.Filter))
                        .Select(c => new ChoiceOption { Id = c.CardId.ToString(), Label = CardLabel(c) })
                        .ToList();
                case "discard_card":
                    // Grouped under whoever last owned each card, you first, so one player's cards aren't scattered.
                    return _state.DiscardPile
                        .Where(c => _card == null || c.CardId != _card.CardId)
                        .Where(c => MatchesCardFilter(c, field.Filter))
                        .OrderBy(c => OwnerRank(c.LastOwnerGamePlayerId))
                        .Select(c => new ChoiceOption
                        {
                            Id = c.CardId.ToString(),
                            Label = CardLabel(c),
                            Group = string.IsNullOrEmpty(c.LastOwnerName) ? PlayerName(c.LastOwnerGamePlayerId) : c.LastOwnerName,
                        })
                        .ToList();
                case "grant_choice":
                    return (field.RawOptions ?? new JArray())
                        .Select(o => new ChoiceOption { Id = (string)o["value"], Label = (string)o["label"] })
                        .ToList();
                case "mode":
                    return (field.RawOptions ?? new JArray())
                        .Select(o => (string)o)
                        .Select(value => new ChoiceOption { Id = value, Label = ModeLabel(field, value) })
                        .ToList();
                case "value":
                    var values = new List<int>();
                    for (var v = field.Min ?? 0; v <= (field.Max ?? 0); v++)
                    {
                        values.Add(v);
                    }

                    values.AddRange(field.ExtraValues ?? new List<int>());
                    return values.Distinct().Select(v => new ChoiceOption { Id = v.ToString(), Label = v.ToString() }).ToList();
                default:
                    return new List<ChoiceOption>();
            }
        }

        private List<ChoiceOption> PlayerOptions(ChoiceField field)
        {
            IEnumerable<BoardPlayer> players = _state.Players;
            if (field.CandidatePlayerIds != null)
            {
                players = players.Where(p => field.CandidatePlayerIds.Contains(p.GamePlayerId));
            }
            else
            {
                players = players
                    .Where(p => !p.Resigned)
                    .Where(p => field.Scope != "other" || p.GamePlayerId != _state.You.GamePlayerId)
                    .Where(p => !field.ExcludesTeammate || p.GamePlayerId != _state.You.TeammateGamePlayerId)
                    .Where(p => MatchesPlayerFilter(p, field.Filter));
            }

            return players.Select(p => new ChoiceOption { Id = p.GamePlayerId.ToString(), Label = p.Username }).ToList();
        }

        private List<ChoiceOption> MoodOptions(ChoiceField field)
        {
            var options = new List<ChoiceOption>();

            // The card being played can name itself only on some cards, and it isn't in play yet,
            // so it can't be found among the moods below.
            if (field.IncludesSelf && _card != null)
            {
                options.Add(new ChoiceOption
                {
                    Id = _card.CardId.ToString(),
                    Label = CardLabel(_card) + " [self]",
                    Group = PlayerName(_state.You.GamePlayerId),
                });
            }

            IEnumerable<BoardCard> moods = _state.InPlay.Where(c => _card == null || c.CardId != _card.CardId);
            if (field.CandidateCardIds != null)
            {
                moods = moods.Where(c => field.CandidateCardIds.Contains(c.CardId));
            }
            else
            {
                moods = moods
                    .Where(c =>
                    {
                        if (field.Scope == "own")
                        {
                            return c.OwnerGamePlayerId == _state.You.GamePlayerId;
                        }

                        return field.Scope != "other" || c.OwnerGamePlayerId != _state.You.GamePlayerId;
                    })
                    .Where(c => !field.ExcludesTeammate || c.OwnerGamePlayerId != _state.You.TeammateGamePlayerId)
                    .Where(c => MatchesCardFilter(c, field.Filter));
            }

            // One player's moods together -- yours first, then everyone else in seat order -- rather than in
            // the order they happened to enter play, which interleaves players. Within a player, play order.
            options.AddRange(moods.OrderBy(c => OwnerRank(c.OwnerGamePlayerId)).Select(c => new ChoiceOption
            {
                Id = c.CardId.ToString(),
                Label = CardLabel(c),
                Group = PlayerName(c.OwnerGamePlayerId),
            }));
            return options;
        }

        /// <summary>Where a player's cards come in a list: you first, then the others by seat. Nobody known goes last.</summary>
        private int OwnerRank(int? gamePlayerId)
        {
            if (gamePlayerId == _state.You.GamePlayerId)
            {
                return -1;
            }

            var player = BoardDisplay.PlayerById(_state, gamePlayerId);
            return player != null ? player.SeatOrder : int.MaxValue;
        }

        private static bool MatchesCardFilter(BoardCard card, ChoiceFilter filter)
        {
            if (filter == null)
            {
                return true;
            }

            if (filter.Colors != null && !filter.Colors.Contains(card.Color))
            {
                return false;
            }

            if (filter.Values != null && !filter.Values.Contains(card.Value))
            {
                return false;
            }

            if (filter.MinValue.HasValue && card.Value < filter.MinValue.Value)
            {
                return false;
            }

            if (filter.MaxValue.HasValue && card.Value > filter.MaxValue.Value)
            {
                return false;
            }

            if (filter.Parity == "odd" && card.Value % 2 == 0)
            {
                return false;
            }

            if (filter.Parity == "even" && card.Value % 2 != 0)
            {
                return false;
            }

            return !filter.HasDiceValue || card.HasDiceValue;
        }

        private bool MatchesPlayerFilter(BoardPlayer player, ChoiceFilter filter)
        {
            if (filter == null)
            {
                return true;
            }

            if (filter.MinHandCount.HasValue && player.HandCount < filter.MinHandCount.Value)
            {
                return false;
            }

            return !filter.MinMoodCount.HasValue
                || BoardDisplay.MoodsOf(_state, player.GamePlayerId).Count >= filter.MinMoodCount.Value;
        }

        /// <summary>"Name (color, value)", as the web client labels a card in a list.</summary>
        public static string CardLabel(BoardCard card) =>
            (card.Name ?? "?") + (card.HasUnusedPlayGrant ? " *" : string.Empty) + $" ({card.Color}, {card.Value})"
            + (card.IsCreativityCopy ? " [Creativity copy]" : string.Empty);

        private string PlayerName(int? gamePlayerId) => BoardDisplay.PlayerById(_state, gamePlayerId)?.Username ?? "?";

        private string ModeLabel(ChoiceField field, string value)
        {
            var label = char.ToUpperInvariant(value[0]) + value.Substring(1).Replace('_', ' ');
            if (field.Key == "direction")
            {
                var neighbor = DirectionNeighbor(value);
                if (neighbor != null)
                {
                    label += " (" + neighbor + ")";
                }
            }

            return label;
        }

        /// <summary>
        /// Who's to your left or right, which only means something with three or more
        /// players. Left is the next seat in turn order, as on the board.
        /// </summary>
        private string DirectionNeighbor(string direction)
        {
            if (_state.Players.Count < 3)
            {
                return null;
            }

            var active = _state.Players.Where(p => !p.Resigned).OrderBy(p => p.SeatOrder).ToList();
            var index = active.FindIndex(p => p.GamePlayerId == _state.You.GamePlayerId);
            if (index < 0 || active.Count < 2)
            {
                return null;
            }

            var offset = direction == "left" ? 1 : -1;
            return active[(index + offset + active.Count) % active.Count].Username;
        }

        // --- what's been picked ----------------------------------------------------------------

        /// <summary>The ids picked for a field, in order (a card_order field starts as the whole list).</summary>
        public IReadOnlyList<string> Selected(string path) =>
            _selected.TryGetValue(path, out var list) ? list : new List<string>();

        public bool IsSelected(string path, string id) => Selected(path).Contains(id);

        /// <summary>Picks one option of a single-choice field; picking the one already chosen clears it.</summary>
        public void Select(string path, string id)
        {
            var already = IsSelected(path, id);
            _selected[path] = already || string.IsNullOrEmpty(id) ? new List<string>() : new List<string> { id };
            AfterChange(path);
        }

        /// <summary>Adds or removes one option of a multiple-choice field.</summary>
        public void Toggle(string path, string id)
        {
            var list = _selected.TryGetValue(path, out var existing) ? existing : (_selected[path] = new List<string>());
            if (!list.Remove(id))
            {
                list.Add(id);
            }

            AfterChange(path);
        }

        public void SetChecked(string path, bool isChecked)
        {
            _selected[path] = isChecked ? new List<string> { "1" } : new List<string>();
        }

        public bool IsChecked(string path) => Selected(path).Count > 0;

        /// <summary>Moves one card of a card_order field earlier (negative) or later (positive).</summary>
        public void Move(string path, string id, int delta)
        {
            if (!_selected.TryGetValue(path, out var list))
            {
                return;
            }

            var from = list.IndexOf(id);
            var to = from + delta;
            if (from < 0 || to < 0 || to >= list.Count)
            {
                return;
            }

            list.RemoveAt(from);
            list.Insert(to, id);
        }

        private void AfterChange(string path)
        {
            if (_creativityPicker != null && path == "copy_card_id")
            {
                ApplyCreativityCopy();
            }
        }

        // Once Creativity has a mood to copy, the rest of its questions are that mood's own.
        private void ApplyCreativityCopy()
        {
            var picked = Selected("copy_card_id").FirstOrDefault();
            List<ChoiceField> extras;
            if (picked != null && _card.CopySimulation.TryGetValue(int.Parse(picked), out var simulation))
            {
                extras = simulation.ExtraFields ?? new List<ChoiceField>();
                _copyCostPayable = simulation.CostPayable;
            }
            else
            {
                extras = _creativityNoCopyExtras;
                _copyCostPayable = true;
            }

            _fields = _creativityPicker.Concat(extras).ToList();

            // Answers to questions that are no longer asked don't carry over.
            var kept = new HashSet<string>(_fields.Select(f => f.Key));
            foreach (var path in _selected.Keys.ToList())
            {
                if (!kept.Contains(path.Split('.')[0]))
                {
                    _selected.Remove(path);
                }
            }

            InitializeDefaults(extras, null);
            FieldsChanged?.Invoke();
        }

        private void InitializeDefaults(IEnumerable<ChoiceField> fields, string prefix)
        {
            foreach (var field in fields)
            {
                var path = PathOf(prefix, field);
                if (field.Type == "nested")
                {
                    InitializeDefaults(field.Fields ?? new List<ChoiceField>(), path);
                    continue;
                }

                if (_selected.ContainsKey(path))
                {
                    continue;
                }

                if (field.Type == "card_order")
                {
                    _selected[path] = (field.Cards ?? new List<OrderedCard>()).Select(c => c.CardId.ToString()).ToList();
                    continue;
                }

                if (field.Type == "bool" || field.Multi)
                {
                    continue;
                }

                var options = OptionsFor(field);
                var suggested = field.Default != null && field.Default.Type != JTokenType.Null ? field.Default.ToString() : null;
                if (suggested != null && options.Any(o => o.Id == suggested))
                {
                    _selected[path] = new List<string> { suggested };
                }
                else if (field.Required && options.Count == 1)
                {
                    // Nothing else to pick, so don't make the player pick it.
                    _selected[path] = new List<string> { options[0].Id };
                }
            }
        }

        // --- checking --------------------------------------------------------------------------

        /// <summary>Every required field has an answer.</summary>
        public bool RequiredFilled =>
            _fields
                .Where(f => f.Required)
                .Where(f => !(f.OptionalIfNoTargets && OptionsFor(f).Count == 0))
                .All(f => HasValue(f, f.Key));

        /// <summary>
        /// What's wrong with the answers so far, if anything -- a card that can't
        /// be played, a wrong number of picks, a pair that doesn't fit together --
        /// otherwise null. Incomplete answers aren't a problem; see <see cref="RequiredFilled"/>.
        /// </summary>
        public string Problem
        {
            get
            {
                if (_playing && !_card.IsPlayable)
                {
                    return "This card can't be played right now.";
                }

                if (!_copyCostPayable)
                {
                    return "That mood's own cost can't be paid right now, so it can't be copied.";
                }

                foreach (var (field, path) in Flatten(_fields, null))
                {
                    var message = FieldProblem(field, path);
                    if (message != null)
                    {
                        return message;
                    }
                }

                return null;
            }
        }

        public bool CanSubmit => RequiredFilled && Problem == null;

        private static IEnumerable<(ChoiceField field, string path)> Flatten(IEnumerable<ChoiceField> fields, string prefix)
        {
            foreach (var field in fields)
            {
                var path = PathOf(prefix, field);
                if (field.Type == "nested")
                {
                    foreach (var inner in Flatten(field.Fields ?? new List<ChoiceField>(), path))
                    {
                        yield return inner;
                    }
                }
                else
                {
                    yield return (field, path);
                }
            }
        }

        private bool HasValue(ChoiceField field, string path)
        {
            if (field.Type == "bool" || field.Type == "card_order")
            {
                return true;
            }

            return Selected(path).Count > 0;
        }

        private string FieldProblem(ChoiceField field, string path)
        {
            if (!field.Multi)
            {
                return null;
            }

            var picked = SelectedCandidates(field, path);
            return CountProblem(field.Count, Selected(path).Count) ?? ConstraintProblem(field, picked);
        }

        private static string CountProblem(ChoiceCount count, int picked)
        {
            if (count == null || (count.ZeroOk && picked == 0))
            {
                return null;
            }

            if (count.Min.HasValue && count.Max.HasValue && count.Min == count.Max && picked != count.Min)
            {
                return $"Choose exactly {count.Min}";
            }

            if (count.Min.HasValue && picked < count.Min.Value)
            {
                return $"Choose at least {count.Min}";
            }

            if (count.Max.HasValue && picked > count.Max.Value)
            {
                return $"Choose at most {count.Max}";
            }

            return null;
        }

        // What was picked, as the cards (or players) themselves. Anything not on the board yet
        // (the card being played naming itself) isn't found and so doesn't count towards these checks.
        private List<BoardCard> SelectedCandidates(ChoiceField field, string path)
        {
            var ids = Selected(path).Select(int.Parse).ToList();
            IEnumerable<BoardCard> source;
            switch (field.Type)
            {
                case "mood": source = _state.InPlay; break;
                case "hand_card": source = _state.You.Hand; break;
                case "discard_card": source = _state.DiscardPile; break;
                default: return new List<BoardCard>();
            }

            var all = source.ToList();
            return ids.Select(id => all.FirstOrDefault(c => c.CardId == id)).Where(c => c != null).ToList();
        }

        private string ConstraintProblem(ChoiceField field, List<BoardCard> picked)
        {
            var constraint = field.Constraint;
            if (constraint == null)
            {
                return null;
            }

            if (constraint.Type == "max_total_value")
            {
                // A mood's value can change once the card being played is in play, so the
                // server sends each one's value as it will be then.
                var total = picked.Sum(c =>
                    field.CandidateValues != null && field.CandidateValues.TryGetValue(c.CardId, out var v) ? v : c.Value);
                return total > constraint.Max
                    ? $"The combined value of the chosen moods cannot exceed {constraint.Max}"
                    : null;
            }

            if (picked.Count < 2)
            {
                return null;
            }

            switch (constraint.Type)
            {
                case "same_color_or_value":
                    return picked[0].Color != picked[1].Color && picked[0].Value != picked[1].Value
                        ? "The two chosen moods must share a color or have the same value"
                        : null;
                case "same_owner":
                    return picked[0].OwnerGamePlayerId != picked[1].OwnerGamePlayerId
                        ? "Both moods must belong to the same opponent"
                        : null;
                case "distinct_owners":
                    return picked.Select(c => c.OwnerGamePlayerId).Distinct().Count() != picked.Count
                        ? "You can only choose one mood per player"
                        : null;
                default:
                    return null;
            }
        }

        // --- sending ---------------------------------------------------------------------------

        /// <summary>The <c>choices</c> body for POST /games/play or /games/respond.</summary>
        public JObject BuildChoices() => BuildChoices(_fields, null);

        private JObject BuildChoices(IEnumerable<ChoiceField> fields, string prefix)
        {
            var choices = new JObject();
            foreach (var field in fields)
            {
                var path = PathOf(prefix, field);
                if (field.Type == "nested")
                {
                    var inner = BuildChoices(field.Fields ?? new List<ChoiceField>(), path);
                    if (inner.Count > 0)
                    {
                        choices[field.Key] = inner;
                    }

                    continue;
                }

                var picked = Selected(path);
                switch (field.Type)
                {
                    case "bool":
                        if (picked.Count > 0)
                        {
                            choices[field.Key] = true;
                        }

                        break;
                    case "card_order":
                        choices[field.Key] = new JArray(picked.Select(int.Parse));
                        break;
                    default:
                        if (picked.Count == 0)
                        {
                            break;
                        }

                        if (field.Multi)
                        {
                            choices[field.Key] = field.Type == "mode"
                                ? new JArray(picked)
                                : new JArray(picked.Select(int.Parse));
                        }
                        else
                        {
                            choices[field.Key] = field.Type == "mode" ? (JToken)picked[0] : int.Parse(picked[0]);
                        }

                        break;
                }
            }

            return choices;
        }

        /// <summary>
        /// Questions to put to the player before playing, for answers that are legal but
        /// would make the card do nothing -- almost always a missed click. Each is a
        /// yes/no question; ask them in order and don't play unless every one is yes.
        /// </summary>
        public IReadOnlyList<string> Confirmations()
        {
            var questions = new List<string>();
            if (!_playing)
            {
                return questions;
            }

            var choices = BuildChoices();

            // A card whose only question is an optional "pick one" and nothing was picked.
            if (_fields.Count == 1)
            {
                var only = _fields[0];
                var pickType = only.Type == "mood" || only.Type == "player" || only.Type == "hand_card" || only.Type == "discard_card";
                if (!only.Required && pickType && !choices.ContainsKey(only.Key))
                {
                    questions.Add($"You haven't selected a target for {_card.Name} -- its ability won't do anything. Play it anyway?");
                }
            }

            // Wrath and Rage with their one box left unchecked.
            var boxKey = _card.EffectKey == "wrath" ? "discard_all_other_moods"
                : _card.EffectKey == "rage" ? "discard_qualifying_moods"
                : null;
            if (boxKey != null && !choices.ContainsKey(boxKey))
            {
                var box = _fields.FirstOrDefault(f => f.Key == boxKey);
                if (box != null)
                {
                    questions.Add($"You haven't checked \"{box.Label}\" -- {_card.Name}'s ability won't do anything. Play it anyway?");
                }
            }

            // A target chosen for a card that does nothing without its mode.
            if (_fields.Any(f => f.RequiresMode != null && choices.ContainsKey(f.Key)) && !choices.ContainsKey("mode"))
            {
                questions.Add($"You've chosen a target for {_card.Name}, but haven't chosen a mode -- as submitted, its ability won't do anything. Play it anyway?");
            }

            return questions;
        }
    }
}
