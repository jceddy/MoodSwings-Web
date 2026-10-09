using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Make a tournament: a name, what is played, the bracket, who may join and how many. A Power Duel tournament also
    /// takes the deck you will play. On success it opens the new tournament.
    /// </summary>
    public sealed class NewTournamentScreen : ListScreen
    {
        private TournamentSetup _setup;
        private Button _create;
        private Text _summary;
        private bool _busy;

        protected override string Title => "New tournament";

        protected override bool StatusBelowList => true;

        public TournamentSetup Setup => _setup;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _setup = new TournamentSetup();
            _busy = false;
            SetStatus(string.Empty);

            AppServices.Friends.Changed += Rebuild;
            AppServices.Decklists.Changed += Rebuild;
            Rebuild();
            Run(LoadChoices);
        }

        public override void OnHidden()
        {
            AppServices.Friends.Changed -= Rebuild;
            AppServices.Decklists.Changed -= Rebuild;
        }

        protected override void BuildBelow(RectTransform column, UiTheme theme)
        {
            _summary = UiFactory.Label(column, string.Empty, 24, AppServices.Theme.textMuted);
            _summary.gameObject.name = "Setup summary";
            UiFactory.Size(_summary.gameObject, height: 36f);
            _create = UiFactory.Button(column, "Create tournament", theme, () => Run(CreateAsync));
            _create.gameObject.name = "Create tournament";
        }

        /// <summary>The friends to invite and the decks to enter; the screen redraws as each arrives.</summary>
        private async Task LoadChoices()
        {
            var friends = AppServices.Friends.RefreshAsync();
            var decks = AppServices.Decklists.RefreshAsync();
            await friends;
            await decks;
        }

        private void Rebuild()
        {
            if (List == null || _setup == null)
            {
                return;
            }

            _setup.Normalize();
            ClearList();
            var theme = AppServices.Theme;

            var namePanel = UiFactory.Panel(List, theme);
            var name = UiFactory.Input(namePanel.transform, "Tournament name", theme);
            name.gameObject.name = "Tournament name field";
            name.text = _setup.Name;
            name.onValueChanged.AddListener(text => _setup.Name = text);

            UiFactory.SectionTitle(List, theme, "Format");
            AddChoice(theme, TournamentSetup.Formats, _setup.FormatId, id =>
            {
                _setup.FormatId = id;
                Rebuild();
            });

            if (_setup.IsGridDraft)
            {
                UiFactory.SectionTitle(List, theme, "Grid Draft");
                AddChoice(theme, TournamentSetup.GridModes, _setup.GridMode, id =>
                {
                    _setup.GridMode = id;
                    Rebuild();
                });
            }

            if (_setup.UsesDeck)
            {
                UiFactory.SectionTitle(List, theme, "Your deck");
                AddDeckChoice(theme);
                var sideboard = UiFactory.Panel(List, theme);
                var toggle = UiFactory.Toggle(sideboard.transform, "Allow sideboarding", theme, _setup.AllowSideboarding);
                toggle.gameObject.name = "Sideboarding toggle";
                UiFactory.ToggleDescription(sideboard.transform, theme, "Between the games of a match, players may swap cards from their deck's sideboard.");
                toggle.onValueChanged.AddListener(on => _setup.AllowSideboarding = on);
            }

            UiFactory.SectionTitle(List, theme, "Bracket");
            if (_setup.IsPlayoffPods)
            {
                var note = UiFactory.Label(List, "Single elimination: every pod's bracket, and the final one, are always single elimination.", 24, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(note.gameObject, height: 70f);
            }
            else
            {
                AddChoice(theme, TournamentSetup.Brackets, _setup.BracketType, id =>
                {
                    _setup.BracketType = id;
                    Refresh();
                });
            }

            UiFactory.SectionTitle(List, theme, "Players");
            AddPlayerCounts(theme);

            UiFactory.SectionTitle(List, theme, "Who can join");
            AddChoice(theme, TournamentSetup.Registrations, _setup.Registration, id =>
            {
                _setup.Registration = id;
                Rebuild();
            });
            if (_setup.IsInviteOnly)
            {
                AddInvites(theme);
            }

            Refresh();
        }

        private void Refresh()
        {
            if (_summary != null)
            {
                _summary.text = _setup.Summary();
            }
        }

        private void AddChoice(UiTheme theme, System.Collections.Generic.IReadOnlyList<TournamentChoice> choices, string selected, System.Action<string> chosen)
        {
            var panel = UiFactory.Panel(List, theme);
            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = false;
            foreach (var choice in choices)
            {
                var id = choice.Id;
                NewGameScreen.AddRadio(theme, panel, group, choice.Label, choice.Description, selected == id, () => chosen(id));
            }
        }

        private void AddPlayerCounts(UiTheme theme)
        {
            var panel = UiFactory.Panel(List, theme);
            AddStepper(theme, panel, "At least", "min", () => _setup.MinParticipants, v => _setup.MinParticipants = v);
            AddStepper(theme, panel, "At most", "max", () => _setup.MaxParticipants, v => _setup.MaxParticipants = v);
            UiFactory.ToggleDescription(panel.transform, theme,
                $"{TournamentSetup.MinPlayers} to {TournamentSetup.MaxPlayers} players, counting you. You start it once enough have joined.");
        }

        private void AddStepper(UiTheme theme, VerticalLayoutGroup panel, string caption, string key, System.Func<int> get, System.Action<int> set)
        {
            var row = UiFactory.Row(panel.transform, "Stepper " + key, 16f, TextAnchor.MiddleLeft);
            UiFactory.Size(row.gameObject, height: UiFactory.ControlHeight);
            var title = UiFactory.Label(row.transform, caption, 28, theme.textPrimary, TextAnchor.MiddleLeft);
            UiFactory.Size(title.gameObject, 200f, UiFactory.ControlHeight);
            var fewer = UiFactory.Button(row.transform, "-", theme, () => Step(-1), primary: false);
            fewer.gameObject.name = "Fewer " + key;
            UiFactory.Size(fewer.gameObject, 90f);
            var value = UiFactory.Label(row.transform, string.Empty, 30, theme.textPrimary, TextAnchor.MiddleCenter, FontStyle.Bold);
            value.gameObject.name = "Players " + key;
            UiFactory.Size(value.gameObject, 120f, UiFactory.ControlHeight);
            var more = UiFactory.Button(row.transform, "+", theme, () => Step(1), primary: false);
            more.gameObject.name = "More " + key;
            UiFactory.Size(more.gameObject, 90f);
            value.text = get().ToString();

            void Step(int by)
            {
                set(get() + by);
                // Raising the minimum past the maximum (or lowering the maximum under the minimum) drags the other along.
                if (key == "min" && _setup.MinParticipants > _setup.MaxParticipants)
                {
                    _setup.MaxParticipants = _setup.MinParticipants;
                }

                if (key == "max" && _setup.MaxParticipants < _setup.MinParticipants)
                {
                    _setup.MinParticipants = _setup.MaxParticipants;
                }

                // (Rebuild also clamps both to the allowed range.)
                Rebuild();
            }
        }

        private void AddInvites(UiTheme theme)
        {
            var panel = UiFactory.Panel(List, theme);
            var friends = AppServices.Friends.Friends;
            if (friends.Count == 0)
            {
                var none = UiFactory.Label(panel.transform, "You have no friends to invite yet.", 24, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 40f);
                return;
            }

            var count = UiFactory.Label(panel.transform, $"Invited: {_setup.InviteUserIds.Count}  (need {_setup.MaxParticipants - 1} to fill it)", 24, theme.textMuted, TextAnchor.MiddleLeft);
            count.gameObject.name = "Invite count";
            UiFactory.Size(count.gameObject, height: 36f);
            foreach (var friend in friends)
            {
                var id = friend.UserId;
                var toggle = UiFactory.Toggle(panel.transform, friend.Username, theme, _setup.InviteUserIds.Contains(id));
                toggle.gameObject.name = "Invite " + friend.Username;
                toggle.onValueChanged.AddListener(on =>
                {
                    if (on && !_setup.InviteUserIds.Contains(id))
                    {
                        _setup.InviteUserIds.Add(id);
                    }
                    else if (!on)
                    {
                        _setup.InviteUserIds.Remove(id);
                    }

                    count.text = $"Invited: {_setup.InviteUserIds.Count}  (need {_setup.MaxParticipants - 1} to fill it)";
                });
            }
        }

        // Your decks, then each friend's shared ones: one radio each.
        private void AddDeckChoice(UiTheme theme)
        {
            var decks = AppServices.Decklists;
            var panel = UiFactory.Panel(List, theme);
            if (!decks.Loaded)
            {
                var loading = UiFactory.Label(panel.transform, "Loading your decks...", 24, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(loading.gameObject, height: 40f);
                return;
            }

            var all = decks.Own.Select(d => (deck: d, owner: (string)null))
                .Concat(decks.Friends.SelectMany(f => f.Decklists.Select(d => (deck: d, owner: f.FriendUsername))))
                .ToList();
            if (all.Count == 0)
            {
                var none = UiFactory.Label(panel.transform, "No saved decks yet. Build one under Decklists on the home screen.", 24, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 70f);
                return;
            }

            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = true;
            foreach (var (deck, owner) in all)
            {
                var id = deck.Id;
                var label = $"{deck.Name}  ({deck.CardCount} cards{(owner != null ? ", " + owner + "'s" : string.Empty)})";
                var toggle = UiFactory.Toggle(panel.transform, label, theme, _setup.SavedDecklistId == id);
                toggle.gameObject.name = "Deck " + deck.Name;
                toggle.group = group;
                toggle.onValueChanged.AddListener(on =>
                {
                    if (on)
                    {
                        _setup.SavedDecklistId = id;
                    }
                });
            }
        }

        private async Task CreateAsync()
        {
            if (_busy)
            {
                return;
            }

            var problem = _setup.Problem();
            if (problem != null)
            {
                SetStatus(problem, isError: true);
                return;
            }

            _busy = true;
            _create.interactable = false;
            SetStatus("Creating the tournament...");
            var result = await AppServices.Tournaments.CreateAsync(_setup.ToBody());
            if (this == null)
            {
                return;
            }

            _busy = false;
            _create.interactable = true;
            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
                return;
            }

            SetStatus(string.Empty);
            Router.Show<TournamentScreen>(result.TournamentId.Value, addToHistory: false);
        }
    }
}
