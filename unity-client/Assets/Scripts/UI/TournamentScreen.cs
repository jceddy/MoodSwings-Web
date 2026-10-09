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
    /// One tournament: who is in, the bracket (or Swiss standings), the draft pods of a pod tournament, your drafted
    /// pool, and the buttons that move it along -- start and cancel for its maker, a way into your own match, and into
    /// your draft. Opened with the tournament's id; refreshes while it's open.
    /// </summary>
    public sealed class TournamentScreen : ListScreen
    {
        private const float PollSeconds = 6f;

        private int _id;
        private bool _busy;
        private ConfirmOverlay _confirm;
        private CardListOverlay _cards;
        private CardDetailOverlay _detail;

        protected override string Title => "Tournament";

        public int TournamentId => _id;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            if (_confirm == null)
            {
                _confirm = new ConfirmOverlay(transform, AppServices.Theme);
                _cards = new CardListOverlay(transform, AppServices.Theme);
                _detail = new CardDetailOverlay(transform, AppServices.Theme);
                _cards.Chosen = card => _detail.Show(card);
            }

            _confirm.Dismiss();
            _cards.Close();
            _detail.Close();
            _busy = false;
            _id = args is int id ? id : _id;
            if (AppServices.Tournaments.Current?.Tournament?.Id != _id)
            {
                AppServices.Tournaments.ForgetCurrent();
            }

            SetStatus(string.Empty);
            AppServices.Tournaments.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
            PollWhileShown(PollSeconds, Refresh);
        }

        public override void OnHidden()
        {
            AppServices.Tournaments.Changed -= Rebuild;
            StopPolling();
            _confirm?.Dismiss();
            _cards?.Close();
            _detail?.Close();
        }

        public override bool HandleBack() =>
            _confirm != null && (_confirm.Dismiss() || _detail.Dismiss() || _cards.Dismiss());

        private async Task Refresh()
        {
            var result = await AppServices.Tournaments.RefreshStateAsync(_id);
            if (this != null && !result.Ok)
            {
                SetStatus(result.Message, isError: true);
            }
        }

        private TournamentStateResponse State =>
            AppServices.Tournaments.Current?.Tournament?.Id == _id ? AppServices.Tournaments.Current : null;

        private void Rebuild()
        {
            if (List == null)
            {
                return;
            }

            ClearList();
            var theme = AppServices.Theme;
            var state = State;
            if (state == null)
            {
                var loading = UiFactory.Label(List, "Loading the tournament...", 26, theme.textMuted);
                UiFactory.Size(loading.gameObject, height: 60f);
                return;
            }

            var me = AppServices.Auth.CurrentUser;
            var names = TournamentDisplay.Names(state.Participants);
            var tournament = state.Tournament;

            var title = UiFactory.Label(List, tournament.Name, 40, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            title.gameObject.name = "Tournament name";
            UiFactory.Size(title.gameObject, height: 56f);
            var summary = UiFactory.Label(List, TournamentDisplay.BracketLabel(tournament.BracketType) + "  -  " + TournamentDisplay.StatusLabel(tournament.Status)
                + (tournament.Status == "completed" && tournament.WinnerUserId.HasValue ? "  -  winner: " + WinnerName(state) : string.Empty),
                26, theme.textMuted, TextAnchor.MiddleLeft);
            summary.gameObject.name = "Tournament status";
            UiFactory.Size(summary.gameObject, height: 40f);

            AddActions(theme, state, me);
            AddPool(theme, state, me);

            if (tournament.Status == "registration")
            {
                AddPlayers(theme, state);
            }

            AddPods(theme, state, names);
            AddStandings(theme, state);
            AddRounds(theme, state, state.Rounds, names, string.Empty);
        }

        private string WinnerName(TournamentStateResponse state) =>
            state.Participants.FirstOrDefault(p => p.UserId == state.Tournament.WinnerUserId)?.Username ?? "?";

        private void AddActions(UiTheme theme, TournamentStateResponse state, User me)
        {
            var myId = me?.Id ?? 0;
            var row = UiFactory.Row(List, "Actions", 14f, TextAnchor.MiddleLeft);
            row.childForceExpandWidth = true;
            UiFactory.Size(row.gameObject, height: 70f);
            var any = false;

            var live = TournamentDisplay.MyLiveMatch(state, myId);
            if (live != null)
            {
                any |= AddAction(row, theme, "Go to your game", true, () => OpenGame(live.GameId.Value));
            }

            var pod = me != null ? TournamentDisplay.MyDraftingPod(state, me.Username) : null;
            if (pod != null)
            {
                any |= AddAction(row, theme, "Continue drafting", true, () => ContinueDrafting(pod));
            }

            if (TournamentDisplay.CanStart(state, myId))
            {
                any |= AddAction(row, theme, "Start tournament", true, () => Run(StartAsync));
            }

            if (TournamentDisplay.CanCancel(state, myId))
            {
                any |= AddAction(row, theme, "Cancel tournament", false, () => Run(CancelAsync));
            }

            if (!any)
            {
                row.gameObject.SetActive(false);
                Destroy(row.gameObject);
            }
        }

        private static bool AddAction(HorizontalLayoutGroup row, UiTheme theme, string label, bool primary, UnityEngine.Events.UnityAction click)
        {
            var button = UiFactory.Button(row.transform, label, theme, click, primary: primary);
            button.gameObject.name = label;
            UiFactory.Flexible(button.gameObject, width: 1f);
            return true;
        }

        private void AddPool(UiTheme theme, TournamentStateResponse state, User me)
        {
            var mine = state.Participants.FirstOrDefault(p => p.UserId == (me?.Id ?? 0));
            if (mine == null || mine.DraftPool == null || mine.DraftPool.Count == 0)
            {
                return;
            }

            var row = UiFactory.Row(List, "Pool", 14f, TextAnchor.MiddleLeft);
            row.childForceExpandWidth = true;
            UiFactory.Size(row.gameObject, height: 64f);
            var pool = DraftDisplay.SortPool(mine.DraftPool);
            AddAction(row, theme, $"Your pool ({pool.Count} cards)", false, () => _cards.Show("Your drafted pool", "Tap a card to read it.", pool));
            if (mine.CurrentDeck != null && mine.CurrentDeck.Count > 0)
            {
                var deck = DraftDisplay.SortPool(mine.CurrentDeck);
                AddAction(row, theme, $"Your last deck ({deck.Count} cards)", false, () => _cards.Show("The deck you last played", "Tap a card to read it.", deck));
            }
        }

        private void AddPlayers(UiTheme theme, TournamentStateResponse state)
        {
            var tournament = state.Tournament;
            var max = tournament.MaxParticipants.HasValue ? " of " + tournament.MaxParticipants.Value : string.Empty;
            UiFactory.SectionTitle(List, theme, $"Players ({TournamentDisplay.JoinedCount(state)}{max} joined, {tournament.MinParticipants} needed)");
            foreach (var participant in state.Participants.Where(p => p.Status == "joined" || p.Status == "invited"))
            {
                var line = participant.Username + (participant.Status == "invited" ? "   (invited)" : string.Empty)
                    + (!string.IsNullOrEmpty(participant.DeckName) ? "   -   " + participant.DeckName : string.Empty);
                var label = UiFactory.Label(List, line, 26, participant.Status == "invited" ? theme.textMuted : theme.textPrimary, TextAnchor.MiddleLeft);
                label.gameObject.name = "Player " + participant.Username;
                UiFactory.Size(label.gameObject, height: 40f);
            }
        }

        private void AddPods(UiTheme theme, TournamentStateResponse state, Dictionary<int, string> names)
        {
            if (state.Pods == null || state.Pods.Count == 0)
            {
                return;
            }

            UiFactory.SectionTitle(List, theme, "Draft pods");
            foreach (var pod in state.Pods)
            {
                var label = UiFactory.Label(List, TournamentDisplay.PodLine(pod), 26, theme.textPrimary, TextAnchor.MiddleLeft);
                label.gameObject.name = "Pod " + pod.PodNumber;
                UiFactory.Size(label.gameObject, height: 40f);
                AddRounds(theme, state, pod.BracketRounds, names, (pod.Kind == "final" ? "Finals" : "Pod " + pod.PodNumber) + ": ");
            }
        }

        private void AddStandings(UiTheme theme, TournamentStateResponse state)
        {
            var lines = TournamentDisplay.StandingLines(state);
            if (lines.Count == 0)
            {
                return;
            }

            UiFactory.SectionTitle(List, theme, "Standings");
            foreach (var line in lines)
            {
                var label = UiFactory.Label(List, line, 26, theme.textPrimary, TextAnchor.MiddleLeft);
                UiFactory.Size(label.gameObject, height: 38f);
            }
        }

        private void AddRounds(UiTheme theme, TournamentStateResponse state, IEnumerable<TournamentRound> rounds, Dictionary<int, string> names, string prefix)
        {
            foreach (var round in rounds)
            {
                UiFactory.SectionTitle(List, theme, prefix + TournamentDisplay.RoundHeading(round));
                foreach (var match in TournamentDisplay.MatchesOf(round, state))
                {
                    AddMatch(theme, match, names);
                }
            }
        }

        private void AddMatch(UiTheme theme, TournamentMatch match, Dictionary<int, string> names)
        {
            var row = UiFactory.RowPanel(List, theme, 72f);
            row.gameObject.name = "Match " + match.Id;
            var label = UiFactory.Label(row.transform, TournamentDisplay.MatchLabel(match, names), 26, theme.textPrimary, TextAnchor.MiddleLeft);
            UiFactory.Flexible(label.gameObject, width: 1f);

            if (match.GameId.HasValue && (match.Status == "in_progress" || match.Status == "completed"))
            {
                var gameId = match.GameId.Value;
                var inProgress = match.Status == "in_progress";
                var button = UiFactory.Button(row.transform, inProgress ? "Go to game" : "View game", theme, () => OpenGame(gameId), primary: false);
                button.gameObject.name = (inProgress ? "Go to game " : "View game ") + match.Id;
                button.GetComponentInChildren<Text>().fontSize = 24;
                UiFactory.Size(button.gameObject, 220f, 52f).minWidth = 220f;
            }
        }

        private void OpenGame(int gameId) => Router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, gameId));

        private void ContinueDrafting(TournamentPod pod)
        {
            if (pod.GameId.HasValue)
            {
                OpenGame(pod.GameId.Value);
            }
            else
            {
                Router.Show<PodDraftScreen>(_id);
            }
        }

        private async Task StartAsync()
        {
            if (_busy)
            {
                return;
            }

            _busy = true;
            SetStatus("Starting the tournament...");
            var result = await AppServices.Tournaments.StartAsync(_id);
            if (this == null)
            {
                return;
            }

            _busy = false;
            SetStatus(result.Ok ? string.Empty : result.Message, isError: !result.Ok);
            if (result.Ok)
            {
                await Refresh();
            }
        }

        private async Task CancelAsync()
        {
            if (_busy || !await _confirm.AskAsync("Cancel this tournament? It can't be started again.", "Cancel tournament", "Keep it"))
            {
                return;
            }

            _busy = true;
            SetStatus("Cancelling...");
            var result = await AppServices.Tournaments.CancelAsync(_id);
            if (this == null)
            {
                return;
            }

            _busy = false;
            SetStatus(result.Ok ? string.Empty : result.Message, isError: !result.Ok);
            if (result.Ok)
            {
                await Refresh();
            }
        }
    }
}
