using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The Weekly Sealed Pool: join the queue to be paired with the next player (a game appears as soon as someone
    /// is waiting), and see this week's standings or last week's.
    /// </summary>
    public sealed class WeeklySealedPoolScreen : ListScreen
    {
        private const float PollSeconds = 10f;

        private Text _queueLine;
        private Button _join;
        private Button _leave;
        private Button _thisWeek;
        private Button _lastWeek;
        private bool _prior;
        private bool _busy;

        protected override string Title => "Weekly Sealed Pool";

        public string QueueText => _queueLine != null ? _queueLine.text : null;

        public bool JoinEnabled => _join != null && _join.gameObject.activeSelf && _join.interactable;

        public bool LeaveShown => _leave != null && _leave.gameObject.activeSelf;

        protected override void BuildAbove(RectTransform column, UiTheme theme)
        {
            _queueLine = UiFactory.Label(column, string.Empty, 26, theme.textMuted, TextAnchor.MiddleLeft);
            _queueLine.gameObject.name = "Queue status";
            UiFactory.Size(_queueLine.gameObject, height: 70f);

            var actions = UiFactory.Row(column, "Queue actions", 14f, TextAnchor.MiddleCenter);
            actions.childForceExpandWidth = true;
            UiFactory.Size(actions.gameObject, height: UiFactory.ControlHeight);
            _join = UiFactory.Button(actions.transform, "Join queue", theme, () => Run(JoinAsync));
            _join.gameObject.name = "Join queue";
            UiFactory.Flexible(_join.gameObject, width: 1f);
            _leave = UiFactory.Button(actions.transform, "Leave queue", theme, () => Run(LeaveAsync), primary: false);
            _leave.gameObject.name = "Leave queue";
            UiFactory.Flexible(_leave.gameObject, width: 1f);

            var weeks = UiFactory.Row(column, "Weeks", 14f, TextAnchor.MiddleCenter);
            weeks.childForceExpandWidth = true;
            UiFactory.Size(weeks.gameObject, height: 56f);
            _thisWeek = UiFactory.Button(weeks.transform, "This week", theme, () => Run(() => ShowWeekAsync(false)), primary: false);
            _thisWeek.gameObject.name = "This week";
            UiFactory.Flexible(_thisWeek.gameObject, width: 1f);
            _lastWeek = UiFactory.Button(weeks.transform, "Last week", theme, () => Run(() => ShowWeekAsync(true)), primary: false);
            _lastWeek.gameObject.name = "Last week";
            UiFactory.Flexible(_lastWeek.gameObject, width: 1f);
        }

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _busy = false;
            _prior = false;
            SetStatus(string.Empty);

            AppServices.WeeklySealed.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
            PollWhileShown(PollSeconds, RefreshQueueOnly);
        }

        public override void OnHidden()
        {
            AppServices.WeeklySealed.Changed -= Rebuild;
            StopPolling();
        }

        private async Task Refresh()
        {
            var queue = AppServices.WeeklySealed.RefreshQueueAsync();
            var standings = AppServices.WeeklySealed.RefreshStandingsAsync(_prior);
            var queueResult = await queue;
            var standingsResult = await standings;
            if (this == null)
            {
                return;
            }

            var failure = !queueResult.Ok ? queueResult : !standingsResult.Ok ? standingsResult : null;
            if (failure != null)
            {
                SetStatus(failure.Message, isError: true);
            }
        }

        // While you wait in the queue, a game may be made for you; the standings can wait for a tap.
        private async Task RefreshQueueOnly()
        {
            var result = await AppServices.WeeklySealed.RefreshQueueAsync();
            if (this != null && !result.Ok)
            {
                SetStatus(result.Message, isError: true);
            }
        }

        private async Task ShowWeekAsync(bool prior)
        {
            _prior = prior;
            var result = await AppServices.WeeklySealed.RefreshStandingsAsync(prior);
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
            SetStatus("Joining the queue...");
            var result = await AppServices.WeeklySealed.JoinAsync();
            if (this == null)
            {
                return;
            }

            _busy = false;
            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
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
            var result = await AppServices.WeeklySealed.LeaveAsync();
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
            var flow = AppServices.WeeklySealed;
            var queue = flow.Queue;
            _queueLine.text = queue == null ? "Loading the queue..." : WeeklySealedDisplay.QueueLine(queue);
            _join.gameObject.SetActive(queue == null || !queue.Queued);
            _join.interactable = WeeklySealedDisplay.CanJoin(queue);
            _leave.gameObject.SetActive(queue != null && queue.Queued);
            Highlight(_thisWeek, !flow.ShowingPrior, theme);
            Highlight(_lastWeek, flow.ShowingPrior, theme);

            ClearList();
            if (!flow.StandingsLoaded)
            {
                var loading = UiFactory.Label(List, "Loading the standings...", 26, theme.textMuted);
                UiFactory.Size(loading.gameObject, height: 60f);
                return;
            }

            var me = AppServices.Auth.CurrentUser?.Id ?? 0;
            var standings = flow.Standings;
            if (standings == null || standings.Count == 0)
            {
                var none = UiFactory.Label(List, WeeklySealedDisplay.EmptyLine(flow.ShowingPrior, standings != null), 26, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 70f);
                return;
            }

            foreach (var row in standings)
            {
                var line = UiFactory.RowPanel(List, theme, 64f);
                line.gameObject.name = "Standing " + row.Username;
                var label = UiFactory.Label(line.transform, WeeklySealedDisplay.StandingLine(row, me), 28,
                    row.UserId == me ? theme.accent : theme.textPrimary, TextAnchor.MiddleLeft, row.UserId == me ? FontStyle.Bold : FontStyle.Normal);
                UiFactory.Flexible(label.gameObject, width: 1f);
            }
        }

        private static void Highlight(Button button, bool on, UiTheme theme)
        {
            var image = button.GetComponent<Image>();
            // The chosen week is the brighter button with accent text, so it never reads as a second primary action.
            image.color = Color.Lerp(theme.panel, Color.white, on ? 0.24f : 0.08f);
            button.GetComponentInChildren<Text>().color = on ? theme.accent : theme.textMuted;
        }
    }
}
