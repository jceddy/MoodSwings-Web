using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// Your lifetime stats and your achievements. Holds what was last fetched and raises <see cref="Changed"/> when
    /// it is replaced. Also works out which unlocks are new since you last looked (for the Home badge and the
    /// "you unlocked..." note). UI-free.
    /// </summary>
    public sealed class StatsFlow
    {
        private readonly ApiClient _api;
        private readonly DeviceSettings _device;

        public StatsFlow(ApiClient api, DeviceSettings device)
        {
            _api = api;
            _device = device;
        }

        public UserStats Stats { get; private set; }

        public IReadOnlyList<WeeklySealedEvent> PriorWeeklySealedEvents { get; private set; } = new List<WeeklySealedEvent>();

        /// <summary>The catalog by category letter; empty until the first fetch.</summary>
        public IReadOnlyDictionary<string, List<Achievement>> Achievements { get; private set; } =
            new Dictionary<string, List<Achievement>>();

        public bool AchievementsLoaded { get; private set; }

        /// <summary>Server-wide figures for every catalog card; empty until the first fetch.</summary>
        public IReadOnlyList<CardStat> CardStats { get; private set; } = new List<CardStat>();

        public bool CardStatsLoaded { get; private set; }

        public event Action Changed;

        public async Task<LobbyResult> RefreshStatsAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.GetUserStatsAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load your stats.") };
            }

            Stats = result.Value.Stats ?? new UserStats();
            PriorWeeklySealedEvents = result.Value.PriorWeeklySealedPoolEvents ?? new List<WeeklySealedEvent>();
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        public async Task<LobbyResult> RefreshAchievementsAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.GetAchievementsAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load your achievements.") };
            }

            Achievements = result.Value.Achievements ?? new Dictionary<string, List<Achievement>>();
            AchievementsLoaded = true;
            if (_device.AchievementsSeenAt == null)
            {
                // The first look on this device: what is already unlocked is old news, not a pile of new unlocks.
                _device.AchievementsSeenAt = AchievementsDisplay.LatestUnlock(Achievements) ?? string.Empty;
            }

            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        public async Task<LobbyResult> RefreshCardStatsAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.GetCardStatsAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load the card stats.") };
            }

            CardStats = result.Value.Cards ?? new List<CardStat>();
            CardStatsLoaded = true;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        /// <summary>Every achievement unlocked after the last time the Achievements screen was opened.</summary>
        public List<Achievement> Unseen() => AchievementsDisplay.UnlockedAfter(Achievements, _device.AchievementsSeenAt);

        /// <summary>Records everything unlocked so far as seen (call when the Achievements screen is opened).</summary>
        public void MarkSeen()
        {
            var latest = AchievementsDisplay.LatestUnlock(Achievements);
            if (latest != null)
            {
                _device.AchievementsSeenAt = latest;
            }
        }

        public void Clear()
        {
            Stats = null;
            _device.AchievementsSeenAt = null;
            PriorWeeklySealedEvents = new List<WeeklySealedEvent>();
            Achievements = new Dictionary<string, List<Achievement>>();
            AchievementsLoaded = false;
            CardStats = new List<CardStat>();
            CardStatsLoaded = false;
            Changed?.Invoke();
        }
    }

    /// <summary>How achievements read: category names and order, progress, the unlock tally, and the hide-locked filter.</summary>
    public static class AchievementsDisplay
    {
        /// <summary>Category letters in the order the web page shows them, with their names.</summary>
        public static readonly (string Letter, string Name)[] Categories =
        {
            ("A", "Volume & Milestones"),
            ("B", "Format Mastery"),
            ("C", "Deck Type & Draft Mastery"),
            ("D", "Color & Rarity Mastery"),
            ("E", "In-Game Skill & Card Feats"),
            ("F", "Tournaments"),
            ("G", "Social & Account"),
            ("H", "Fun, Flavor & Meta"),
            ("I", "Card Cycles"),
            ("J", "Puzzles"),
        };

        public static string CategoryName(string letter)
        {
            foreach (var category in Categories)
            {
                if (category.Letter == letter)
                {
                    return category.Name;
                }
            }

            return letter;
        }

        /// <summary>The categories that have achievements, in display order (any the client doesn't know come last).</summary>
        public static List<string> OrderedLetters(IReadOnlyDictionary<string, List<Achievement>> catalog)
        {
            var known = Categories.Select(c => c.Letter).Where(l => catalog.ContainsKey(l) && catalog[l].Count > 0);
            var unknown = catalog.Where(p => p.Value.Count > 0 && Categories.All(c => c.Letter != p.Key)).Select(p => p.Key).OrderBy(k => k, StringComparer.Ordinal);
            return known.Concat(unknown).ToList();
        }

        public static int Total(IReadOnlyDictionary<string, List<Achievement>> catalog) => catalog.Values.Sum(list => list.Count);

        public static int UnlockedCount(IReadOnlyDictionary<string, List<Achievement>> catalog) =>
            catalog.Values.Sum(list => list.Count(a => a.Unlocked));

        public static string Summary(IReadOnlyDictionary<string, List<Achievement>> catalog) =>
            $"{UnlockedCount(catalog)} of {Total(catalog)} unlocked";

        /// <summary>The achievements to list for a category: all of them, or with hide-locked on only the unlocked ones.</summary>
        public static List<Achievement> Visible(IEnumerable<Achievement> achievements, bool hideLocked) =>
            achievements.Where(a => !hideLocked || a.Unlocked).ToList();

        /// <summary>"3 / 10" for a counting achievement that isn't done yet; null for the rest.</summary>
        public static string ProgressText(Achievement achievement) =>
            achievement.Target.HasValue && !achievement.Unlocked ? $"{achievement.Progress} / {achievement.Target.Value}" : null;

        /// <summary>How far along a counting achievement is, 0 to 1 (0 when it has no target).</summary>
        public static float ProgressFraction(Achievement achievement)
        {
            if (!achievement.Target.HasValue || achievement.Target.Value <= 0)
            {
                return 0f;
            }

            return Math.Min(1f, (float)achievement.Progress / achievement.Target.Value);
        }

        /// <summary>The newest unlock time in the catalog (server timestamps sort as text); null when nothing is unlocked.</summary>
        public static string LatestUnlock(IReadOnlyDictionary<string, List<Achievement>> catalog)
        {
            string latest = null;
            foreach (var achievement in catalog.Values.SelectMany(list => list).Where(a => a.Unlocked))
            {
                if (latest == null || string.CompareOrdinal(achievement.UnlockedAt, latest) > 0)
                {
                    latest = achievement.UnlockedAt;
                }
            }

            return latest;
        }

        /// <summary>The achievements unlocked after <paramref name="since"/> (null: everything unlocked), newest first.</summary>
        public static List<Achievement> UnlockedAfter(IReadOnlyDictionary<string, List<Achievement>> catalog, string since) =>
            catalog.Values.SelectMany(list => list)
                .Where(a => a.Unlocked && (since == null || string.CompareOrdinal(a.UnlockedAt, since) > 0))
                .OrderByDescending(a => a.UnlockedAt, StringComparer.Ordinal)
                .ToList();
    }

    /// <summary>How your lifetime numbers read.</summary>
    public static class StatsDisplay
    {
        /// <summary>"12-8 (60%)" as the web page writes it; no percentage until there is something to measure.</summary>
        public static string Record(int wins, int losses, double? percentage) =>
            percentage.HasValue ? $"{wins}-{losses} ({Percentage(percentage)})" : $"{wins}-{losses}";

        public static string Week(WeeklySealedEvent e) => $"{e.PeriodStart}   {e.Wins}-{e.Losses}   Top {e.Percentile}%";

        /// <summary>"60%" (to the nearest whole percent), or an em dash when there is nothing to measure.</summary>
        public static string Percentage(double? value) =>
            value.HasValue ? Math.Round(value.Value).ToString("0", System.Globalization.CultureInfo.InvariantCulture) + "%" : "—";
    }
}
