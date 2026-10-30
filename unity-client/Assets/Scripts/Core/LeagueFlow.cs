using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// League play: a running event you join (which also queues you for a match), your record and matches, the
    /// leaderboard, and your permanent collection. Holds what was last fetched and raises <see cref="Changed"/>. UI-free.
    /// </summary>
    public sealed class LeagueFlow
    {
        private readonly ApiClient _api;

        public LeagueFlow(ApiClient api)
        {
            _api = api;
        }

        public LeagueStatus Status { get; private set; }

        /// <summary>The event the leaderboard is for; null when there is no such event, or before it has been fetched.</summary>
        public LeagueEvent LeaderboardEvent { get; private set; }

        public IReadOnlyList<WeeklyStandingRow> Standings { get; private set; } = new List<WeeklyStandingRow>();

        public bool LeaderboardLoaded { get; private set; }

        /// <summary>Whether the leaderboard is the previous event's.</summary>
        public bool ShowingPrior { get; private set; }

        /// <summary>Your collection as last fetched, in the server's color, rarity, name order; null until fetched.</summary>
        public IReadOnlyList<BoardCard> Collection { get; private set; }

        public event Action Changed;

        public async Task<LobbyResult> RefreshStatusAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.GetLeagueStatusAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load the League.") };
            }

            Status = result.Value;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        public async Task<LobbyResult> RefreshLeaderboardAsync(bool prior, CancellationToken cancellationToken = default)
        {
            var result = await _api.GetLeagueLeaderboardAsync(prior, cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load the leaderboard.") };
            }

            ShowingPrior = prior;
            LeaderboardEvent = result.Value.Event;
            Standings = result.Value.Standings ?? new List<WeeklyStandingRow>();
            LeaderboardLoaded = true;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        public async Task<LobbyResult> RefreshCollectionAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.GetLeagueCollectionAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load your collection.") };
            }

            Collection = result.Value.Cards ?? new List<BoardCard>();
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        /// <summary>
        /// Joins the event and queues for a match: a game appears at once (its id in the result) when someone was already
        /// waiting. The first join of an event also grants a starter deck, so the collection is fetched again next time.
        /// </summary>
        public async Task<LobbyResult> JoinAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.JoinLeagueAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't join the League.") };
            }

            Collection = null;
            if (result.Value.Paired)
            {
                return new LobbyResult { Ok = true, GameId = result.Value.GameId, Message = "Paired with " + result.Value.OpponentUsername + "." };
            }

            await RefreshStatusAsync(cancellationToken);
            return new LobbyResult { Ok = true };
        }

        public async Task<LobbyResult> LeaveAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.LeaveLeagueQueueAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't leave the queue.") };
            }

            return await RefreshStatusAsync(cancellationToken);
        }

        public void Clear()
        {
            Status = null;
            LeaderboardEvent = null;
            Standings = new List<WeeklyStandingRow>();
            LeaderboardLoaded = false;
            ShowingPrior = false;
            Collection = null;
            Changed?.Invoke();
        }
    }

    /// <summary>How the League reads.</summary>
    public static class LeagueDisplay
    {
        /// <summary>"Spring Cup" or, for an event about one set, "Spring Cup (MSW)".</summary>
        public static string EventName(LeagueEvent league) =>
            league == null ? string.Empty : string.IsNullOrEmpty(league.SetCode) ? league.Name : $"{league.Name} ({league.SetCode})";

        /// <summary>"Record 3-1. 1/2 matches in progress. Collection: 52 cards (31 different)."</summary>
        public static string Summary(LeagueStatus status) =>
            $"Record {status.Wins}-{status.Losses}. {status.InProgressCount}/{status.ConcurrentMatchCap} matches in progress. "
            + $"Collection: {status.Collection.TotalCards} cards ({status.Collection.UniqueCards} different).";

        public static string Rules(LeagueStatus status) =>
            $"Matches are best-of-three Duels. Decks need at least {status.DeckRules.MinCards} cards from your collection "
            + $"(no more copies of a card than you own), with at most {status.DeckRules.MaxMythics} mythics and {status.DeckRules.MaxRares} rares. "
            + "You never play the same opponent twice in one event.";

        /// <summary>What to say about the queue, which depends on whether you're waiting and on whether this is your first time in.</summary>
        public static string QueueLine(LeagueStatus status)
        {
            if (status.Queued)
            {
                return "You're in the queue, waiting for an opponent. You'll choose your deck from your collection once a match starts.";
            }

            return status.Joined
                ? "You choose your deck from your collection once a match starts."
                : "You'll be granted a random Structure deck for your collection and queued for a match -- you choose your deck from your collection once it starts.";
        }

        public static string JoinLabel(LeagueStatus status) => status != null && status.Joined ? "Queue for a match" : "Join League";

        /// <summary>Joining is blocked at the match cap (the server refuses too; this says so up front).</summary>
        public static bool CanJoin(LeagueStatus status) =>
            status != null && status.Event != null && !status.Queued && status.InProgressCount < status.ConcurrentMatchCap;

        /// <summary>"vs Alder -- won" / "lost" / "in progress".</summary>
        public static string MatchLine(LeagueMatchRow match) =>
            $"vs {match.OpponentUsername} -- {(match.InProgress ? "in progress" : match.Won == true ? "won" : "lost")}";

        /// <summary>"Open game" for a match under way, "View" for one that is over.</summary>
        public static string MatchButton(LeagueMatchRow match) => match.InProgress ? "Open game" : "View";

        /// <summary>What to say when the leaderboard is empty: no such event, or an event nobody has finished a match in.</summary>
        public static string EmptyLeaderboard(bool prior, bool eventExists) =>
            !eventExists
                ? prior ? "There is no previous League event yet." : "There is no League event running right now."
                : prior ? "Nobody finished a match in that event." : "No standings yet -- be the first to finish a match!";

        /// <summary>"52 cards, 31 different."</summary>
        public static string CollectionSummary(IReadOnlyCollection<BoardCard> cards) =>
            cards == null || cards.Count == 0 ? string.Empty : $"{cards.Sum(c => c.Copies)} cards, {cards.Count} different.";
    }
}
