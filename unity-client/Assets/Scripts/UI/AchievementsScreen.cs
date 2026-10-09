using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Every achievement by category, each with a tier badge, a progress bar while it is being worked toward and a
    /// check once it's unlocked. A switch hides the ones still locked. Opening the screen counts everything unlocked
    /// so far as seen (which clears the Home badge).
    /// </summary>
    public sealed class AchievementsScreen : ListScreen
    {
        private Toggle _hideLocked;

        protected override string Title => "Achievements";

        public bool HideLockedOn => _hideLocked != null && _hideLocked.isOn;

        public static Color TierColor(string tier)
        {
            switch (tier)
            {
                case "Bronze": return new Color32(0xcd, 0x7f, 0x32, 255);
                case "Silver": return new Color32(0xc0, 0xc0, 0xc0, 255);
                case "Gold": return new Color32(0xd4, 0xaf, 0x37, 255);
                case "Platinum": return new Color32(0xa8, 0xd0, 0xe6, 255);
                case "Diamond": return new Color32(0xb9, 0xf2, 0xff, 255);
                default: return new Color32(0x80, 0x80, 0x80, 255);
            }
        }

        protected override void BuildAbove(RectTransform column, UiTheme theme)
        {
            _hideLocked = UiFactory.Toggle(column, "Hide locked achievements", theme, AppServices.Device.HideLockedAchievements);
            _hideLocked.gameObject.name = "Hide locked";
            UiFactory.Size(_hideLocked.gameObject, height: 44f);
            _hideLocked.onValueChanged.AddListener(value =>
            {
                AppServices.Device.HideLockedAchievements = value;
                Rebuild();
            });
        }

        public override void OnShown(object args)
        {
            EnsureBuilt();
            SetStatus(string.Empty);
            _hideLocked.SetIsOnWithoutNotify(AppServices.Device.HideLockedAchievements);

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
            var result = await AppServices.Stats.RefreshAchievementsAsync();
            if (this == null)
            {
                return;
            }

            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
                return;
            }

            AppServices.Stats.MarkSeen();
        }

        private void Rebuild()
        {
            if (List == null)
            {
                return;
            }

            ClearList();
            var theme = AppServices.Theme;
            var stats = AppServices.Stats;
            if (!stats.AchievementsLoaded)
            {
                var loading = UiFactory.Label(List, "Loading your achievements...", 26, theme.textMuted);
                UiFactory.Size(loading.gameObject, height: 60f);
                return;
            }

            var catalog = stats.Achievements;
            var summary = UiFactory.Label(List, AchievementsDisplay.Summary(catalog), 30, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            summary.gameObject.name = "Summary";
            UiFactory.Size(summary.gameObject, height: 50f);

            foreach (var letter in AchievementsDisplay.OrderedLetters(catalog))
            {
                var visible = AchievementsDisplay.Visible(catalog[letter], AppServices.Device.HideLockedAchievements);
                if (visible.Count == 0)
                {
                    continue;
                }

                var done = catalog[letter].FindAll(a => a.Unlocked).Count;
                UiFactory.SectionTitle(List, theme, $"{AchievementsDisplay.CategoryName(letter)}   {done} / {catalog[letter].Count}");
                foreach (var achievement in visible)
                {
                    AddRow(theme, achievement);
                }
            }
        }

        private void AddRow(UiTheme theme, Achievement achievement)
        {
            var unlocked = achievement.Unlocked;
            var row = UiFactory.Create("Achievement " + achievement.Slug, List);
            row.gameObject.AddComponent<Image>().color = unlocked ? Color.Lerp(theme.panel, theme.accent, 0.10f) : theme.panel;
            var layout = row.gameObject.AddComponent<HorizontalLayoutGroup>();
            layout.padding = new RectOffset(20, 18, 12, 12);
            layout.spacing = 18f;
            layout.childAlignment = TextAnchor.MiddleLeft;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;

            var mark = UiFactory.Label(row, unlocked ? "✓" : string.Empty, 36, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            mark.gameObject.name = "Unlocked mark";
            var markSize = UiFactory.Size(mark.gameObject, 40f);
            markSize.minWidth = 40f;

            var text = UiFactory.Create("Text", row);
            UiFactory.Flexible(text.gameObject, width: 1f);
            var column = text.gameObject.AddComponent<VerticalLayoutGroup>();
            column.spacing = 4f;
            column.childControlWidth = true;
            column.childControlHeight = true;
            column.childForceExpandWidth = true;
            column.childForceExpandHeight = false;

            UiFactory.Label(text, achievement.Title, 28, theme.textPrimary, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Label(text, achievement.Description, 22, theme.textMuted, TextAnchor.UpperLeft);

            var progress = AchievementsDisplay.ProgressText(achievement);
            if (progress != null)
            {
                AddProgress(theme, text, achievement, progress);
            }

            AddTierBadge(row, achievement.Tier);
        }

        private static void AddProgress(UiTheme theme, RectTransform parent, Achievement achievement, string label)
        {
            var line = UiFactory.Row(parent, "Progress", 14f, TextAnchor.MiddleLeft);
            UiFactory.Size(line.gameObject, height: 26f);

            var track = UiFactory.Create("Track", line.transform);
            track.gameObject.AddComponent<Image>().color = new Color(1f, 1f, 1f, 0.10f);
            var trackSize = UiFactory.Size(track.gameObject, height: 12f);
            trackSize.flexibleWidth = 1f;
            trackSize.minWidth = 100f;

            var fill = UiFactory.Create("Fill", track);
            fill.anchorMin = Vector2.zero;
            fill.anchorMax = new Vector2(AchievementsDisplay.ProgressFraction(achievement), 1f);
            fill.offsetMin = Vector2.zero;
            fill.offsetMax = Vector2.zero;
            fill.gameObject.AddComponent<Image>().color = theme.accent;

            var text = UiFactory.Label(line.transform, label, 20, theme.textMuted, TextAnchor.MiddleRight);
            text.gameObject.name = "Progress text";
            var textSize = UiFactory.Size(text.gameObject, 130f);
            textSize.minWidth = 130f;
        }

        private static void AddTierBadge(RectTransform row, string tier)
        {
            var badge = UiFactory.Create("Tier " + tier, row);
            badge.gameObject.AddComponent<Image>().color = TierColor(tier);
            var badgeSize = UiFactory.Size(badge.gameObject, 150f, 40f);
            badgeSize.minWidth = 150f;
            badgeSize.minHeight = 40f;
            badgeSize.flexibleWidth = 0f;
            var label = UiFactory.Label(badge, (tier ?? string.Empty).ToUpperInvariant(), 20, new Color(0.08f, 0.09f, 0.10f), TextAnchor.MiddleCenter, FontStyle.Bold);
            UiFactory.Stretch(label.rectTransform);
        }
    }
}
