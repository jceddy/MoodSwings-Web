using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// League play: an open-ended event where you keep a permanent collection and play best-of-three Duels against other
    /// members using only cards you own. Joining also queues you for a match (no deck is chosen here -- each player builds
    /// theirs in the game); the screen shows your record and matches, the leaderboard (this event's or the last one's), and
    /// the way into your collection.
    /// </summary>
    public sealed class LeagueScreen : ListScreen
    {
        private const float PollSeconds = 10f;

        private Text _eventName;
        private Text _summary;
        private Text _rules;
        private Text _queueLine;
        private Button _join;
        private Button _leave;
        private Button _collection;
        private bool _prior;
        private bool _busy;

        protected override string Title => "League";

        public string EventText => _eventName != null ? _eventName.text : null;

        public string SummaryText => _summary != null && _summary.gameObject.activeSelf ? _summary.text : null;

        public string QueueText => _queueLine != null && _queueLine.gameObject.activeSelf ? _queueLine.text : null;

        public string JoinText => _join != null ? _join.GetComponentInChildren<Text>().text : null;

        public bool JoinShown => _join != null && _join.gameObject.activeSelf;

        public bool JoinEnabled => JoinShown && _join.interactable;

        public bool LeaveShown => _leave != null && _leave.gameObject.activeSelf;

        public bool CollectionShown => _collection != null && _collection.gameObject.activeSelf;

        protected override void BuildAbove(RectTransform column, UiTheme theme)
        {
            _eventName = UiFactory.Label(column, string.Empty, 36, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            _eventName.gameObject.name = "Event";
            UiFactory.Size(_eventName.gameObject, height: 50f);
            _summary = UiFactory.Label(column, string.Empty, 26, theme.textPrimary, TextAnchor.UpperLeft);
            _summary.gameObject.name = "Summary";
            _rules = UiFactory.Label(column, string.Empty, 24, theme.textMuted, TextAnchor.UpperLeft);
            _rules.gameObject.name = "Rules";
            _queueLine = UiFactory.Label(column, string.Empty, 26, theme.textMuted, TextAnchor.UpperLeft);
            _queueLine.gameObject.name = "Queue status";

            var actions = UiFactory.Row(column, "League actions", 14f, TextAnchor.MiddleCenter);
            actions.childForceExpandWidth = true;
            UiFactory.Size(actions.gameObject, height: UiFactory.ControlHeight);
            _join = UiFactory.Button(actions.transform, "Join League", theme, () => Run(JoinAsync));
            _join.gameObject.name = "League join";
            UiFactory.Flexible(_join.gameObject, width: 1f);
            _leave = UiFactory.Button(actions.transform, "Leave queue", theme, () => Run(LeaveAsync), primary: false);
            _leave.gameObject.name = "Leave queue";
            UiFactory.Flexible(_leave.gameObject, width: 1f);
            _collection = UiFactory.Button(actions.transform, "View my collection", theme, () => Router.Show<LeagueCollectionScreen>(), primary: false);
            _collection.gameObject.name = "View my collection";
            UiFactory.Flexible(_collection.gameObject, width: 1f);
        }

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _busy = false;
            _prior = false;
            SetStatus(string.Empty);

            AppServices.League.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
            PollWhileShown(PollSeconds, RefreshStatusOnly);
        }

        public override void OnHidden()
        {
            AppServices.League.Changed -= Rebuild;
            StopPolling();
        }

        private async Task Refresh()
        {
            var status = AppServices.League.RefreshStatusAsync();
            var leaderboard = AppServices.League.RefreshLeaderboardAsync(_prior);
            var statusResult = await status;
            var leaderboardResult = await leaderboard;
            if (this == null)
            {
                return;
            }

            var failure = !statusResult.Ok ? statusResult : !leaderboardResult.Ok ? leaderboardResult : null;
            if (failure != null)
            {
                SetStatus(failure.Message, isError: true);
            }
        }

        // While you wait in the queue a match may be made for you; the leaderboard can wait for a tap.
        private async Task RefreshStatusOnly()
        {
            var result = await AppServices.League.RefreshStatusAsync();
            if (this != null && !result.Ok)
            {
                SetStatus(result.Message, isError: true);
            }
        }

        private async Task ShowLeaderboardAsync(bool prior)
        {
            _prior = prior;
            var result = await AppServices.League.RefreshLeaderboardAsync(prior);
            if (this != null && !result.Ok)
            {
                SetStatus(result.Message, isError: true);
            }
        }

        private async Task JoinAsync()
        {
            if (_busy)
            {
                return;
            }

            _busy = true;
            SetStatus("Joining the League...");
            var result = await AppServices.League.JoinAsync();
            if (this == null)
            {
                return;
            }

            _busy = false;
            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
                // The refusal usually means the page was out of date (the match cap, a queue that already had you).
                await RefreshStatusOnly();
                return;
            }

            if (result.GameId.HasValue)
            {
                SetStatus(result.Message);
                Router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, result.GameId.Value), addToHistory: false);
                return;
            }

            SetStatus(string.Empty);
        }

        private async Task LeaveAsync()
        {
            if (_busy)
            {
                return;
            }

            _busy = true;
            var result = await AppServices.League.LeaveAsync();
            if (this == null)
            {
                return;
            }

            _busy = false;
            SetStatus(result.Ok ? string.Empty : result.Message, isError: !result.Ok);
        }

        private void Rebuild()
        {
            if (List == null)
            {
                return;
            }

            var theme = AppServices.Theme;
            var flow = AppServices.League;
            var status = flow.Status;
            var running = status != null && status.Event != null;

            _eventName.text = status == null ? "Loading the League..."
                : running ? LeagueDisplay.EventName(status.Event)
                : "There is no League event running right now.";
            _eventName.color = running ? theme.accent : theme.textMuted;
            _summary.gameObject.SetActive(running);
            _rules.gameObject.SetActive(running);
            _queueLine.gameObject.SetActive(running);
            _collection.gameObject.SetActive(running);
            if (running)
            {
                _summary.text = LeagueDisplay.Summary(status);
                _rules.text = LeagueDisplay.Rules(status);
                _queueLine.text = LeagueDisplay.QueueLine(status);
            }

            _join.gameObject.SetActive(running && !status.Queued);
            _join.interactable = LeagueDisplay.CanJoin(status);
            if (running)
            {
                _join.GetComponentInChildren<Text>().text = LeagueDisplay.JoinLabel(status);
            }

            _leave.gameObject.SetActive(running && status.Queued);

            ClearList();
            if (running)
            {
                BuildMatches(theme, status);
            }

            BuildLeaderboard(theme, flow);
        }

        private void BuildMatches(UiTheme theme, LeagueStatus status)
        {
            UiFactory.SectionTitle(List, theme, $"Your matches ({status.Matches.Count})");
            if (status.Matches.Count == 0)
            {
                var none = UiFactory.Label(List, "No matches yet -- queue for one.", 26, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 60f);
                return;
            }

            foreach (var match in status.Matches)
            {
                var row = UiFactory.RowPanel(List, theme, 72f);
                row.gameObject.name = "Match " + match.OpponentUsername;
                var label = UiFactory.Label(row.transform, LeagueDisplay.MatchLine(match), 28,
                    match.InProgress ? theme.accent : theme.textPrimary, TextAnchor.MiddleLeft, match.InProgress ? FontStyle.Bold : FontStyle.Normal);
                UiFactory.Flexible(label.gameObject, width: 1f);
                if (match.GameId.HasValue)
                {
                    var gameId = match.GameId.Value;
                    var open = UiFactory.Button(row.transform, LeagueDisplay.MatchButton(match), theme,
                        () => Router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, gameId)), primary: match.InProgress);
                    open.gameObject.name = "Open " + match.OpponentUsername;
                    UiFactory.Size(open.gameObject, 220f, 56f);
                }
            }
        }

        private void BuildLeaderboard(UiTheme theme, LeagueFlow flow)
        {
            UiFactory.SectionTitle(List, theme, "Leaderboard");

            var toggles = UiFactory.Row(List, "Events", 14f, TextAnchor.MiddleCenter);
            toggles.childForceExpandWidth = true;
            UiFactory.Size(toggles.gameObject, height: 56f);
            AddToggle(toggles.transform, theme, "Current event", !flow.ShowingPrior, () => Run(() => ShowLeaderboardAsync(false)));
            AddToggle(toggles.transform, theme, "Previous event", flow.ShowingPrior, () => Run(() => ShowLeaderboardAsync(true)));

            if (!flow.LeaderboardLoaded)
            {
                var loading = UiFactory.Label(List, "Loading the leaderboard...", 26, theme.textMuted);
                UiFactory.Size(loading.gameObject, height: 60f);
                return;
            }

            if (flow.LeaderboardEvent != null)
            {
                var name = UiFactory.Label(List, LeagueDisplay.EventName(flow.LeaderboardEvent), 26, theme.textMuted, TextAnchor.MiddleLeft);
                name.gameObject.name = "Leaderboard event";
                UiFactory.Size(name.gameObject, height: 40f);
            }

            var standings = flow.Standings;
            if (standings.Count == 0)
            {
                var none = UiFactory.Label(List, LeagueDisplay.EmptyLeaderboard(flow.ShowingPrior, flow.LeaderboardEvent != null), 26, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 70f);
                return;
            }

            var me = AppServices.Auth.CurrentUser?.Id ?? 0;
            foreach (var row in standings)
            {
                var line = UiFactory.RowPanel(List, theme, 64f);
                line.gameObject.name = "Standing " + row.Username;
                var label = UiFactory.Label(line.transform, WeeklySealedDisplay.StandingLine(row, me), 28,
                    row.UserId == me ? theme.accent : theme.textPrimary, TextAnchor.MiddleLeft, row.UserId == me ? FontStyle.Bold : FontStyle.Normal);
                UiFactory.Flexible(label.gameObject, width: 1f);
            }
        }

        // The chosen event is the brighter button with accent text, so it never reads as a second primary action.
        private static void AddToggle(Transform parent, UiTheme theme, string label, bool on, UnityEngine.Events.UnityAction onClick)
        {
            var button = UiFactory.Button(parent, label, theme, onClick, primary: false);
            button.gameObject.name = label;
            UiFactory.Flexible(button.gameObject, width: 1f);
            button.GetComponent<Image>().color = on ? theme.Selected : theme.raised;
            button.GetComponentInChildren<Text>().color = on ? theme.accent : theme.textMuted;
        }
    }
}
