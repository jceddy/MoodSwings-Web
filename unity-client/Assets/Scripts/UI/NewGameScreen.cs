using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Set up and start a game: either invite friends and practice bots (the
    /// game starts at once), or post to the open lobby for 2-4 players. Opened
    /// blank, or prefilled (a rematch). Traditional or Duel format with the
    /// ready-made decks for now. On success it returns to the screen it was opened
    /// from, which shows the outcome.
    /// </summary>
    public sealed class NewGameScreen : ListScreen
    {
        private GameSetup _setup;
        private Text _countLabel;
        private GameObject _botFirstPanel;
        private Toggle _synchronousToggle;
        private GameObject _synchronousPanel;
        private Toggle _bestOfThreeToggle;
        private GameObject _bestOfThreePanel;
        private RectTransform _partnerPanel;
        private RectTransform _botDeckPanel;
        private GameObject _sideboardPanel;
        private Toggle _sideboardToggle;
        private Button _create;
        private Text _createLabel;
        private bool _busy;

        protected override string Title => "New game";

        protected override bool StatusBelowList => true;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _setup = args as GameSetup ?? NewDefaultSetup();
            _setup.AllowCustomContent = AppServices.Preferences.Get(PreferenceCatalog.GameDefaults.First(p => p.JsonKey == "allow_custom_content"));
            _busy = false;
            SetStatus(string.Empty);

            AppServices.Lobby.Changed += Rebuild;
            AppServices.Friends.Changed += Rebuild;
            AppServices.Decklists.Changed += Rebuild;
            Rebuild();
            Run(LoadChoices);
        }

        public override void OnHidden()
        {
            AppServices.Lobby.Changed -= Rebuild;
            AppServices.Friends.Changed -= Rebuild;
            AppServices.Decklists.Changed -= Rebuild;
        }

        protected override void BuildBelow(RectTransform column, UiTheme theme)
        {
            _create = UiFactory.Button(column, "Start game", theme, () => Run(CreateAsync));
            _createLabel = _create.GetComponentInChildren<Text>();
        }

        private static GameSetup NewDefaultSetup()
        {
            var defaultSelections = PreferenceCatalog.GameDefaults.First(p => p.JsonKey == "default_selections_mode_preference");
            return new GameSetup { DefaultSelectionsMode = AppServices.Preferences.Get(defaultSelections) };
        }

        /// <summary>The bots and friends to pick from; the screen redraws itself as each arrives.</summary>
        private async Task LoadChoices()
        {
            var bots = AppServices.Lobby.RefreshBotsAsync();
            var friends = AppServices.Friends.RefreshAsync();
            var flag = AppServices.Lobby.RefreshSynchronousModeFlagAsync(); // a failure just means the option stays hidden
            var decks = AppServices.Decklists.RefreshAsync(); // only needed once a saved deck is asked for
            var botsResult = await bots;
            var friendsResult = await friends;
            await flag;
            await decks;
            if (this == null)
            {
                return;
            }

            if (!botsResult.Ok)
            {
                SetStatus(botsResult.Message, isError: true);
            }
            else if (!friendsResult.Ok)
            {
                SetStatus(friendsResult.Message, isError: true);
            }
        }

        private void Rebuild()
        {
            if (List == null || _setup == null)
            {
                return;
            }

            ClearList();
            var theme = AppServices.Theme;

            UiFactory.SectionTitle(List, theme, "Who plays");
            AddModeChoice(theme);
            if (_setup.PostToOpenLobby)
            {
                AddOpenLobbyChoice(theme);
            }
            else
            {
                AddOpponentChoice(theme);
            }

            UiFactory.SectionTitle(List, theme, "Format");
            AddFormatChoice(theme);

            // Team play: which opponent is your partner. Filled in by RefreshSummary as opponents are picked.
            if (_setup.IsTeamFormat && !_setup.PostToOpenLobby)
            {
                _partnerPanel = UiFactory.Panel(List, theme).GetComponent<RectTransform>();
            }
            else
            {
                _partnerPanel = null;
            }

            UiFactory.SectionTitle(List, theme, _setup.IsDraftFormat ? "Draft" : "Deck");
            AddDeckChoice(theme);
            if (_setup.UsesPoolSource)
            {
                UiFactory.SectionTitle(List, theme, "Cards to draft");
                AddPoolSourceChoice(theme);
            }

            if (_setup.UsesRotisserieCutoff)
            {
                UiFactory.SectionTitle(List, theme, "Picks each");
                AddCutoffChoice(theme);
            }

            if (_setup.IsCustomDuel)
            {
                UiFactory.SectionTitle(List, theme, "Deck rules");
                AddDuelRulesChoice(theme);
            }

            if (_setup.UsesSavedDeck)
            {
                UiFactory.SectionTitle(List, theme, _setup.DeckType == GameSetup.CustomDeck ? "Saved deck" : "Deck to draft from");
                AddSavedDeckChoice(theme, _setup.SavedDecklistId, deck =>
                {
                    _setup.SavedDecklistId = deck.Id;
                    _setup.SavedDeckCardCount = deck.CardCount;
                    RefreshSummary();
                });
            }

            // A practice bot can't pick its own deck for a custom duel, so its deck is chosen here. Filled in by RefreshSummary.
            _botDeckPanel = _setup.IsCustomDuel && !_setup.PostToOpenLobby
                ? UiFactory.Panel(List, theme).GetComponent<RectTransform>()
                : null;

            UiFactory.SectionTitle(List, theme, "Options");
            var options = UiFactory.Panel(List, theme);
            var defaults = UiFactory.Toggle(options.transform, "Default selections mode", theme, _setup.DefaultSelectionsMode);
            UiFactory.ToggleDescription(options.transform, theme, "Pre-fill card choices with a reasonable default. You can still change them before submitting.");
            defaults.onValueChanged.AddListener(on => _setup.DefaultSelectionsMode = on);

            var bestOfThree = UiFactory.Panel(List, theme);
            _bestOfThreePanel = bestOfThree.gameObject;
            _bestOfThreeToggle = UiFactory.Toggle(bestOfThree.transform, "Best of three", theme, _setup.BestOfThree);
            _bestOfThreeToggle.gameObject.name = "Best of three toggle";
            UiFactory.ToggleDescription(bestOfThree.transform, theme,
                "First to win two games. The next game is created for you after each one, and the loser chooses who goes first.");
            _bestOfThreeToggle.onValueChanged.AddListener(on =>
            {
                _setup.BestOfThree = on;
                RefreshSummary(); // sideboarding depends on it
            });

            var sideboard = UiFactory.Panel(List, theme);
            _sideboardPanel = sideboard.gameObject;
            _sideboardToggle = UiFactory.Toggle(sideboard.transform, "Allow sideboarding", theme, _setup.AllowSideboarding);
            _sideboardToggle.gameObject.name = "Sideboarding toggle";
            UiFactory.ToggleDescription(sideboard.transform, theme,
                "Between games of the match, each player may rebuild their deck from their first deck and sideboard.");
            _sideboardToggle.onValueChanged.AddListener(on => _setup.AllowSideboarding = on);

            var synchronous = UiFactory.Panel(List, theme);
            _synchronousPanel = synchronous.gameObject;
            _synchronousToggle = UiFactory.Toggle(synchronous.transform, "Synchronous (live)", theme, _setup.SynchronousMode);
            _synchronousToggle.gameObject.name = "Synchronous toggle";
            UiFactory.ToggleDescription(synchronous.transform, theme,
                "For two players sitting down together: a ready check before the game and a 30-second clock on each action.");
            _synchronousToggle.onValueChanged.AddListener(on => _setup.SynchronousMode = on);

            RefreshSummary();
        }

        private void AddModeChoice(UiTheme theme)
        {
            var panel = UiFactory.Panel(List, theme);
            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = false;

            AddRadio(theme, panel, group, "Invite friends and bots", "The game starts right away.", !_setup.PostToOpenLobby,
                () => SetMode(openLobby: false));
            AddRadio(theme, panel, group, "Post to the open lobby", "Starts when enough players have joined.", _setup.PostToOpenLobby,
                () => SetMode(openLobby: true));
        }

        private void SetMode(bool openLobby)
        {
            if (_setup.PostToOpenLobby == openLobby)
            {
                return;
            }

            _setup.PostToOpenLobby = openLobby;
            SetStatus(string.Empty);
            Rebuild();
        }

        private void AddFormatChoice(UiTheme theme)
        {
            var panel = UiFactory.Panel(List, theme);
            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = false;
            foreach (var format in GameSetup.FormatOptions)
            {
                var id = format.Id;
                AddRadio(theme, panel, group, format.Label, format.Description, _setup.Format == id, () =>
                {
                    if (_setup.Format == id)
                    {
                        return;
                    }

                    _setup.Format = id;
                    _setup.Normalize(AppServices.Lobby.SynchronousModeEnabled);
                    SetStatus(string.Empty);
                    Rebuild();
                });
            }
        }

        private void AddOpenLobbyChoice(UiTheme theme)
        {
            var panel = UiFactory.Panel(List, theme);
            var title = UiFactory.Label(panel.transform, "Total players, including you", 28, theme.textPrimary, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(title.gameObject, height: 40f);

            if (!_setup.OpenLobbyCountIsChoosable)
            {
                AddNote(theme, panel, $"A {GameDisplay.FormatName(_setup.Format)} game from the lobby seats exactly {_setup.EffectiveOpenLobbyPlayerCount} players.");
                if (_setup.IsTeamFormat)
                {
                    AddNote(theme, panel, "Teams are assigned at random once everyone has joined.");
                }

                UiFactory.ToggleDescription(panel.transform, theme, "Only players who are \"discoverable for open games\" (see Settings) will see it.");
                return;
            }

            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = false;
            for (var players = GameSetup.MinPlayers; players <= GameSetup.MaxPlayers; players++)
            {
                var count = players;
                AddRadio(theme, panel, group, count + " players", null, _setup.OpenLobbyPlayerCount == count, () =>
                {
                    _setup.OpenLobbyPlayerCount = count;
                    RefreshSummary();
                });
            }

            UiFactory.ToggleDescription(panel.transform, theme, "Only players who are \"discoverable for open games\" (see Settings) will see it.");
        }

        private void AddOpponentChoice(UiTheme theme)
        {
            var lobby = AppServices.Lobby;
            var friends = AppServices.Friends.Friends;
            var panel = UiFactory.Panel(List, theme);

            AddGroupLabel(theme, panel, "Practice bots");
            if (lobby.Bots.Count == 0)
            {
                AddNote(theme, panel, "Loading the practice bots...");
            }

            foreach (var bot in lobby.Bots)
            {
                AddOpponentToggle(theme, panel, bot.UserId, bot.Username + (bot.UsesTacticalAi ? "  (tactical)" : string.Empty));
            }

            AddGroupLabel(theme, panel, "Friends");
            if (friends.Count == 0)
            {
                AddNote(theme, panel, "Add friends from the Friends screen to invite them.");
            }

            foreach (var friend in friends)
            {
                AddOpponentToggle(theme, panel, friend.UserId, friend.Username + (friend.IsOnline ? "  (online)" : string.Empty));
            }

            // Carried over from a rematch: someone who is neither a bot nor a friend.
            var listed = new HashSet<int>(lobby.Bots.Select(b => b.UserId).Concat(friends.Select(f => f.UserId)));
            foreach (var id in _setup.OpponentUserIds.Where(i => !listed.Contains(i)).ToList())
            {
                var name = _setup.OpponentNames.TryGetValue(id, out var known) ? known : "Player " + id;
                AddOpponentToggle(theme, panel, id, name + "  (from the last game)");
            }

            _countLabel = UiFactory.Label(panel.transform, string.Empty, 24, theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Size(_countLabel.gameObject, height: 34f);

            var botFirst = UiFactory.Toggle(panel.transform, "Let a bot go first", theme, _setup.BotGoesFirst);
            botFirst.onValueChanged.AddListener(on => _setup.BotGoesFirst = on);
            _botFirstPanel = botFirst.gameObject;
        }

        private void AddOpponentToggle(UiTheme theme, VerticalLayoutGroup panel, int userId, string label)
        {
            var toggle = UiFactory.Toggle(panel.transform, label, theme, _setup.OpponentUserIds.Contains(userId));
            toggle.onValueChanged.AddListener(on =>
            {
                if (on && _setup.OpponentUserIds.Count >= _setup.MaxOpponents)
                {
                    toggle.SetIsOnWithoutNotify(false);
                    SetStatus(
                        _setup.IsTwoPlayerOnly
                            ? "The Sealed Pool of the Day is for exactly two players, so pick one opponent."
                            : $"A game seats at most {GameSetup.MaxPlayers} players, so pick at most {GameSetup.MaxPlayers - 1} opponents.",
                        isError: true);
                    return;
                }

                SetStatus(string.Empty);
                if (on)
                {
                    _setup.OpponentUserIds.Add(userId);
                }
                else
                {
                    _setup.OpponentUserIds.Remove(userId);
                }

                RefreshSummary();
            });
        }

        private void AddDeckChoice(UiTheme theme)
        {
            var panel = UiFactory.Panel(List, theme);
            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = false;
            foreach (var deck in _setup.DecksForFormat)
            {
                var id = deck.Id;
                AddRadio(theme, panel, group, deck.Label, deck.Description, _setup.DeckType == id, () =>
                {
                    if (_setup.DeckType != id)
                    {
                        _setup.DeckType = id;
                        _setup.Normalize(AppServices.Lobby.SynchronousModeEnabled);
                        Rebuild();
                    }
                });
            }
        }

        private void AddDuelRulesChoice(UiTheme theme)
        {
            var panel = UiFactory.Panel(List, theme);
            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = false;
            foreach (var preset in GameSetup.DuelRulePresets)
            {
                var id = preset.Id;
                AddRadio(theme, panel, group, preset.Label, preset.Description, _setup.DuelPreset == id, () =>
                {
                    _setup.DuelPreset = id;
                    RefreshSummary();
                });
            }
        }

        // Your decks, then each friend's shared ones: one radio each. Used for the game's deck and for each bot's.
        private void AddSavedDeckChoice(UiTheme theme, int? selected, System.Action<DecklistSummary> chosen, Transform parent = null)
        {
            var decks = AppServices.Decklists;
            var panel = UiFactory.Panel(parent ?? List, theme);
            if (!decks.Loaded)
            {
                AddNote(theme, panel, "Loading your decks...");
                return;
            }

            var all = decks.Own.Select(d => (deck: d, owner: (string)null))
                .Concat(decks.Friends.SelectMany(f => f.Decklists.Select(d => (deck: d, owner: f.FriendUsername))))
                .ToList();
            if (all.Count == 0)
            {
                AddNote(theme, panel, "No saved decks yet. Build one under Decklists on the home screen.");
                return;
            }

            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = true;
            foreach (var (deck, owner) in all)
            {
                var summary = deck;
                var label = $"{deck.Name}  ({deck.CardCount} cards{(owner != null ? ", " + owner + "'s" : string.Empty)})";
                var toggle = UiFactory.Toggle(panel.transform, label, theme, selected == deck.Id);
                toggle.gameObject.name = "Deck " + deck.Name;
                toggle.group = group;
                toggle.onValueChanged.AddListener(on =>
                {
                    if (on)
                    {
                        chosen(summary);
                    }
                });
            }
        }

        // One saved deck for each practice bot seated in a custom duel.
        private void RebuildBotDeckChoices()
        {
            if (_botDeckPanel == null)
            {
                return;
            }

            foreach (Transform child in _botDeckPanel)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }

            var theme = AppServices.Theme;
            var panel = _botDeckPanel.GetComponent<VerticalLayoutGroup>();
            var bots = _setup.OpponentUserIds.Where(_setup.BotUserIds.Contains).ToList();
            _botDeckPanel.gameObject.SetActive(bots.Count > 0);
            foreach (var botId in bots)
            {
                var id = botId;
                AddGroupLabel(theme, panel, "Deck for " + NameOf(id));
                _setup.BotDecklistIds.TryGetValue(id, out var current);
                AddSavedDeckChoice(theme, _setup.BotDecklistIds.ContainsKey(id) ? current : (int?)null, deck =>
                {
                    _setup.BotDecklistIds[id] = deck.Id;
                    RefreshSummary();
                }, _botDeckPanel);
            }
        }

        // How many cards each player picks in a Rotisserie Draft: a stepper between the server's limits.
        private void AddCutoffChoice(UiTheme theme)
        {
            var panel = UiFactory.Panel(List, theme);
            var row = UiFactory.Row(panel.transform, "Cutoff", 16f, TextAnchor.MiddleLeft);
            UiFactory.Size(row.gameObject, height: UiFactory.ControlHeight);
            var label = UiFactory.Label(row.transform, string.Empty, 30, theme.textPrimary, TextAnchor.MiddleCenter, FontStyle.Bold);
            label.gameObject.name = "Cutoff label";
            UiFactory.Size(label.gameObject, 360f, UiFactory.ControlHeight);

            void Show() => label.text = _setup.RotisserieCutoff + " cards each";
            void Step(int by)
            {
                _setup.RotisserieCutoff = Mathf.Clamp(_setup.RotisserieCutoff + by, GameSetup.MinRotisserieCutoff, GameSetup.MaxRotisserieCutoff);
                Show();
            }

            var fewer = UiFactory.Button(row.transform, "-", theme, () => Step(-1), primary: false);
            fewer.gameObject.name = "Fewer picks";
            UiFactory.Size(fewer.gameObject, 90f);
            label.transform.SetSiblingIndex(1);
            var more = UiFactory.Button(row.transform, "+", theme, () => Step(1), primary: false);
            more.gameObject.name = "More picks";
            UiFactory.Size(more.gameObject, 90f);
            Show();

            UiFactory.ToggleDescription(panel.transform, theme,
                $"Each player drafts this many cards ({GameSetup.MinRotisserieCutoff} to {GameSetup.MaxRotisserieCutoff}), then builds a deck from them.");
        }

        private void AddPoolSourceChoice(UiTheme theme)
        {
            var panel = UiFactory.Panel(List, theme);
            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = false;
            foreach (var source in GameSetup.PoolSourceOptions)
            {
                var id = source.Id;
                AddRadio(theme, panel, group, source.Label, source.Description, _setup.PoolSource == id, () =>
                {
                    // Choosing (or leaving) a saved deck as the pool adds (or drops) the list of decks to pick from.
                    var changes = (_setup.PoolSource == GameSetup.SavedDeckSource) != (id == GameSetup.SavedDeckSource);
                    _setup.PoolSource = id;
                    if (changes)
                    {
                        Rebuild();
                    }
                });
            }
        }

        private static void AddRadio(
            UiTheme theme, VerticalLayoutGroup panel, ToggleGroup group, string label, string description, bool isOn, System.Action onChosen)
        {
            var toggle = UiFactory.Toggle(panel.transform, label, theme, isOn);
            toggle.group = group;
            if (!string.IsNullOrEmpty(description))
            {
                UiFactory.ToggleDescription(panel.transform, theme, description);
            }

            // Switching fires for the option turned off as well; only act on the new choice.
            toggle.onValueChanged.AddListener(on =>
            {
                if (on)
                {
                    onChosen();
                }
            });
        }

        private static void AddGroupLabel(UiTheme theme, VerticalLayoutGroup panel, string text)
        {
            var label = UiFactory.Label(panel.transform, text, 26, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(label.gameObject, height: 38f);
        }

        private static void AddNote(UiTheme theme, VerticalLayoutGroup panel, string text)
        {
            var label = UiFactory.Label(panel.transform, text, 24, theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Size(label.gameObject, height: 34f);
        }

        /// <summary>Updates everything that depends on the current choices: the count, the bot option, and the action button.</summary>
        private void RefreshSummary()
        {
            if (_countLabel != null)
            {
                _countLabel.text = $"Selected: {_setup.OpponentUserIds.Count} of {GameSetup.MaxPlayers - 1}";
            }

            // "Synchronous" only makes sense for two players in a format that supports it, and only
            // while the server offers it; anything that stops applying is switched off, not just hidden.
            _setup.BotUserIds = new HashSet<int>(AppServices.Lobby.Bots.Select(b => b.UserId));
            _setup.Normalize(AppServices.Lobby.SynchronousModeEnabled);
            RebuildPartnerChoices();
            RebuildBotDeckChoices();
            if (_sideboardToggle != null)
            {
                _sideboardPanel.SetActive(_setup.SideboardingAvailable);
                if (!_setup.SideboardingAvailable)
                {
                    _sideboardToggle.SetIsOnWithoutNotify(false);
                }
            }

            if (_bestOfThreeToggle != null)
            {
                _bestOfThreePanel.SetActive(_setup.BestOfThreeAvailable);
                if (!_setup.BestOfThreeAvailable)
                {
                    _bestOfThreeToggle.SetIsOnWithoutNotify(false);
                }
            }

            if (_synchronousToggle != null)
            {
                var offered = _setup.SynchronousModeAvailable(AppServices.Lobby.SynchronousModeEnabled);
                _synchronousPanel.SetActive(offered);
                if (!offered)
                {
                    _synchronousToggle.SetIsOnWithoutNotify(false);
                }
            }

            if (_botFirstPanel != null)
            {
                var botIds = new HashSet<int>(AppServices.Lobby.Bots.Select(b => b.UserId));
                var anyBot = _setup.OpponentUserIds.Any(botIds.Contains);
                _botFirstPanel.SetActive(anyBot && !_setup.IsTeamFormat);
                if (!anyBot)
                {
                    _setup.BotGoesFirst = false;
                }
            }

            _createLabel.text = _setup.PostToOpenLobby ? "Post to open lobby" : "Start game";
            _create.interactable = !_busy && (_setup.PostToOpenLobby ? _setup.ValidateOpenGame() : _setup.ValidateDirectGame()) == null;
        }

        private string NameOf(int userId)
        {
            var bot = AppServices.Lobby.Bots.FirstOrDefault(b => b.UserId == userId);
            if (bot != null)
            {
                return bot.Username;
            }

            var friend = AppServices.Friends.Friends.FirstOrDefault(f => f.UserId == userId);
            if (friend != null)
            {
                return friend.Username;
            }

            return _setup.OpponentNames.TryGetValue(userId, out var known) ? known : "Player " + userId;
        }

        // Your partner is one of your opponents: a radio per opponent picked so far, or "at random".
        private void RebuildPartnerChoices()
        {
            if (_partnerPanel == null)
            {
                return;
            }

            foreach (Transform child in _partnerPanel)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }

            var theme = AppServices.Theme;
            var panel = _partnerPanel.GetComponent<VerticalLayoutGroup>();
            AddGroupLabel(theme, panel, "Your partner");

            var random = UiFactory.Toggle(_partnerPanel, "Assign my partner at random", theme, _setup.RandomTeams);
            random.gameObject.name = "Random partner toggle";
            random.onValueChanged.AddListener(on =>
            {
                _setup.RandomTeams = on;
                RefreshSummary();
            });

            if (_setup.RandomTeams)
            {
                return;
            }

            if (_setup.OpponentUserIds.Count == 0)
            {
                AddNote(theme, panel, "Pick your opponents above, then choose which one is on your team.");
                return;
            }

            var group = _partnerPanel.gameObject.GetComponent<ToggleGroup>() ?? _partnerPanel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = false;
            foreach (var id in _setup.OpponentUserIds)
            {
                var partnerId = id;
                var toggle = UiFactory.Toggle(_partnerPanel, NameOf(partnerId), theme, _setup.PartnerUserId == partnerId);
                toggle.group = group;
                toggle.onValueChanged.AddListener(on =>
                {
                    if (on)
                    {
                        _setup.PartnerUserId = partnerId;
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

            _busy = true;
            RefreshSummary();
            SetStatus("Working...");

            var result = _setup.PostToOpenLobby
                ? await AppServices.Lobby.PostOpenGameAsync(_setup)
                : await AppServices.Lobby.CreateGameAsync(_setup);
            if (this == null)
            {
                return;
            }

            _busy = false;
            RefreshSummary();
            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
                return;
            }

            // Back to wherever this was opened from, which shows what happened.
            var message = result.Message;
            Router.Back();
            Router.Current?.ShowMessage(message);
        }
    }
}
