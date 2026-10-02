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
        private Button _create;
        private Text _createLabel;
        private bool _busy;

        protected override string Title => "New game";

        protected override bool StatusBelowList => true;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _setup = args as GameSetup ?? NewDefaultSetup();
            _busy = false;
            SetStatus(string.Empty);

            AppServices.Lobby.Changed += Rebuild;
            AppServices.Friends.Changed += Rebuild;
            Rebuild();
            Run(LoadChoices);
        }

        public override void OnHidden()
        {
            AppServices.Lobby.Changed -= Rebuild;
            AppServices.Friends.Changed -= Rebuild;
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
            var botsResult = await bots;
            var friendsResult = await friends;
            await flag;
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

            UiFactory.SectionTitle(List, theme, "Deck");
            AddDeckChoice(theme);

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
            _bestOfThreeToggle.onValueChanged.AddListener(on => _setup.BestOfThree = on);

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
                if (on && _setup.OpponentUserIds.Count >= GameSetup.MaxPlayers - 1)
                {
                    toggle.SetIsOnWithoutNotify(false);
                    SetStatus($"A game seats at most {GameSetup.MaxPlayers} players, so pick at most {GameSetup.MaxPlayers - 1} opponents.", isError: true);
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
            foreach (var deck in GameSetup.DeckOptions)
            {
                var id = deck.Id;
                AddRadio(theme, panel, group, deck.Label, deck.Description, _setup.DeckType == id, () => _setup.DeckType = id);
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
            _setup.Normalize(AppServices.Lobby.SynchronousModeEnabled);
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
                _botFirstPanel.SetActive(anyBot);
                if (!anyBot)
                {
                    _setup.BotGoesFirst = false;
                }
            }

            _createLabel.text = _setup.PostToOpenLobby ? "Post to open lobby" : "Start game";
            _create.interactable = !_busy && (_setup.PostToOpenLobby ? _setup.ValidateOpenGame() : _setup.ValidateDirectGame()) == null;
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
