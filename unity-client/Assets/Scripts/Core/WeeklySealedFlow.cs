using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// The Weekly Sealed Pool: a queue that pairs you with the next player (a game appears as soon as someone is
    /// waiting), and the week's standings. Holds what was last fetched and raises <see cref="Changed"/>. UI-free.
    /// </summary>
    public sealed class WeeklySealedFlow
    {
        private readonly ApiClient _api;

        public WeeklySealedFlow(ApiClient api)
        {
            _api = api;
        }

        public WeeklyQueueStatus Queue { get; private set; }

        /// <summary>Last fetched standings; null when the week asked for had no event, empty until fetched.</summary>
        public IReadOnlyList<WeeklyStandingRow> Standings { get; private set; } = new List<WeeklyStandingRow>();

        public bool StandingsLoaded { get; private set; }

        /// <summary>Whether the standings are last week's.</summary>
        public bool ShowingPrior { get; private set; }

        public event Action Changed;

        public async Task<LobbyResult> RefreshQueueAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.GetWeeklyQueueAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load the queue.") };
            }

            Queue = result.Value;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        public async Task<LobbyResult> RefreshStandingsAsync(bool prior, CancellationToken cancellationToken = default)
        {
            var result = await _api.GetWeeklyStandingsAsync(prior, cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load the standings.") };
            }

            ShowingPrior = prior;
            Standings = result.Value.Standings;
            StandingsLoaded = true;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        /// <summary>Joins the queue: a game appears at once (its id in the result) when someone was already waiting.</summary>
        public async Task<LobbyResult> JoinAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.JoinWeeklyQueueAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't join the queue.") };
            }

            if (result.Value.Paired)
            {
                return new LobbyResult { Ok = true, GameId = result.Value.GameId, Message = "Paired with " + result.Value.OpponentUsername + "." };
            }

            await RefreshQueueAsync(cancellationToken);
            return new LobbyResult { Ok = true };
        }

        public async Task<LobbyResult> LeaveAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.LeaveWeeklyQueueAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't leave the queue.") };
            }

            return await RefreshQueueAsync(cancellationToken);
        }

        public void Clear()
        {
            Queue = null;
            Standings = new List<WeeklyStandingRow>();
            StandingsLoaded = false;
            ShowingPrior = false;
            Changed?.Invoke();
        }
    }

    /// <summary>How the Weekly Sealed Pool reads.</summary>
    public static class WeeklySealedDisplay
    {
        /// <summary>"2/2 matches in progress this week.", with a line first when you're waiting in the queue.</summary>
        public static string QueueLine(WeeklyQueueStatus queue)
        {
            var progress = $"{queue.InProgressCount}/{queue.ConcurrentMatchCap} matches in progress this week.";
            return queue.Queued ? "You're in the queue, waiting for an opponent. " + progress : progress;
        }

        /// <summary>Joining is blocked at the cap (the server refuses too; this says so up front).</summary>
        public static bool CanJoin(WeeklyQueueStatus queue) => queue != null && !queue.Queued && queue.InProgressCount < queue.ConcurrentMatchCap;

        /// <summary>"#1 Alder (you)  -  3-0 (top 10%)".</summary>
        public static string StandingLine(WeeklyStandingRow row, int myUserId) =>
            $"#{row.Rank} {row.Username}{(row.UserId == myUserId ? " (you)" : string.Empty)}  -  {row.Wins}-{row.Losses} (top {row.Percentile}%)";

        /// <summary>What to say when the list is empty: no event at all (null standings), or an event nobody has finished a match in.</summary>
        public static string EmptyLine(bool prior, bool eventExisted) =>
            !eventExisted ? "There was no Weekly Sealed Pool event last week."
                : prior ? "Nobody finished a match last week."
                : "No standings yet -- be the first to finish a match this week!";
    }
}
