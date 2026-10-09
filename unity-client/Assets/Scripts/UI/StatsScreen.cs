using System.Threading.Tasks;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>Your lifetime games and matches, and how you placed in past Weekly Sealed Pool weeks.</summary>
    public sealed class StatsScreen : ListScreen
    {
        protected override string Title => "Your stats";

        protected override void BuildBelow(RectTransform column, UiTheme theme)
        {
            var cards = UiFactory.Button(column, "Card stats", theme, () => Router.Show<CardStatsScreen>(), primary: false);
            cards.gameObject.name = "Card stats";
        }

        public override void OnShown(object args)
        {
            EnsureBuilt();
            SetStatus(string.Empty);

            AppServices.Stats.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
        }

        public override void OnHidden()
        {
            AppServices.Stats.Changed -= Rebuild;
        }

        private async Task Refresh()
        {
            var result = await AppServices.Stats.RefreshStatsAsync();
            if (this != null && !result.Ok)
            {
                SetStatus(result.Message, isError: true);
            }
        }

        private void Rebuild()
        {
            if (List == null)
            {
                return;
            }

            ClearList();
            var theme = AppServices.Theme;
            var stats = AppServices.Stats.Stats;
            if (stats == null)
            {
                var loading = UiFactory.Label(List, "Loading your stats...", 26, theme.textMuted);
                UiFactory.Size(loading.gameObject, height: 60f);
                return;
            }

            UiFactory.SectionTitle(List, theme, "Lifetime");
            AddRecord(theme, "Games", "Every format", StatsDisplay.Record(stats.GameWins, stats.GameLosses, stats.GameWinPercentage));
            AddRecord(theme, "Matches", "Best-of-three results", StatsDisplay.Record(stats.MatchWins, stats.MatchLosses, stats.MatchWinPercentage));

            var weeks = AppServices.Stats.PriorWeeklySealedEvents;
            UiFactory.SectionTitle(List, theme, "Weekly Sealed Pool: past weeks");
            if (weeks.Count == 0)
            {
                var none = UiFactory.Label(List, "You haven't finished a week yet.", 24, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 50f);
                return;
            }

            foreach (var week in weeks)
            {
                AddRecord(theme, week.PeriodStart, "Week starting", $"{week.Wins}-{week.Losses}   Top {week.Percentile}%");
            }
        }

        private void AddRecord(UiTheme theme, string title, string subtitle, string value)
        {
            var row = UiFactory.RowPanel(List, theme, 90f);
            row.gameObject.name = "Stat " + title;
            UiFactory.Flexible(UiFactory.TwoLineText(row.transform, theme, title, subtitle).gameObject, width: 1f);
            var figure = UiFactory.Label(row.transform, value, 32, theme.accent, TextAnchor.MiddleRight, FontStyle.Bold);
            figure.gameObject.name = "Value";
            var size = UiFactory.Size(figure.gameObject, 360f);
            size.minWidth = 360f;
        }
    }
}
