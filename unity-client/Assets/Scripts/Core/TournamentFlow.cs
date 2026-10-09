using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// Tournaments: the lists (yours, and the open ones to join), the one being looked at, and every way of joining,
    /// leaving, starting and cancelling, plus a Booster Draft pod's picks. Holds the last fetched state and raises
    /// <see cref="Changed"/> when it is replaced. UI-free.
    /// </summary>
    public sealed class TournamentFlow
    {
        private readonly ApiClient _api;

        public TournamentFlow(ApiClient api)
        {
            _api = api;
        }

        /// <summary>The ones you made, were invited to, joined or cast, newest first.</summary>
        public IReadOnlyList<TournamentSummary> Mine { get; private set; } = new List<TournamentSummary>();

        /// <summary>Open registration, that you could join.</summary>
        public IReadOnlyList<TournamentSummary> Open { get; private set; } = new List<TournamentSummary>();

        public bool Loaded { get; private set; }

        /// <summary>The tournament being looked at (null before the first fetch, or after another is asked for).</summary>
        public TournamentStateResponse Current { get; private set; }

        public PodDraftStateResponse Pod { get; private set; }

        public event Action Changed;

        public async Task<LobbyResult> RefreshListsAsync(CancellationToken cancellationToken = default)
        {
            var mine = _api.ListTournamentsAsync(true, cancellationToken);
            var open = _api.ListTournamentsAsync(false, cancellationToken);
            await Task.WhenAll(mine, open);
            if (!mine.Result.Ok)
            {
                return new LobbyResult { Message = mine.Result.UserMessage("Couldn't load your tournaments.") };
            }

            Mine = mine.Result.Value.Tournaments;
            // The open list is a nicety: a failure there leaves it as it was rather than hiding your own.
            if (open.Result.Ok)
            {
                Open = open.Result.Value.Tournaments;
            }

            Loaded = true;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        public async Task<LobbyResult> RefreshStateAsync(int tournamentId, CancellationToken cancellationToken = default)
        {
            var result = await _api.GetTournamentStateAsync(tournamentId, cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load that tournament.") };
            }

            Current = result.Value;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        /// <summary>Forgets the tournament being looked at, so the next one doesn't flash the last one's bracket.</summary>
        public void ForgetCurrent()
        {
            Current = null;
            Pod = null;
        }

        /// <summary>A Power Duel tournament asks for your deck when you join (and lets you change it until it starts).</summary>
        public static bool NeedsDeck(TournamentSummary tournament) => tournament.MatchParams?.DeckType == "custom_duel";

        public async Task<LobbyResult> JoinAsync(TournamentSummary tournament, int? savedDecklistId = null, CancellationToken cancellationToken = default) =>
            await Seat("join", tournament, savedDecklistId, "Couldn't join that tournament.", cancellationToken);

        public async Task<LobbyResult> AcceptAsync(TournamentSummary tournament, int? savedDecklistId = null, CancellationToken cancellationToken = default) =>
            await Seat("accept-invite", tournament, savedDecklistId, "Couldn't accept that invitation.", cancellationToken);

        public async Task<LobbyResult> SubmitDeckAsync(TournamentSummary tournament, int savedDecklistId, CancellationToken cancellationToken = default) =>
            await Seat("submit-deck", tournament, savedDecklistId, "Couldn't submit that deck.", cancellationToken);

        public Task<LobbyResult> DeclineAsync(TournamentSummary tournament, CancellationToken cancellationToken = default) =>
            Act("decline-invite", tournament.Id, "Couldn't decline that invitation.", cancellationToken);

        public Task<LobbyResult> WithdrawAsync(TournamentSummary tournament, CancellationToken cancellationToken = default) =>
            Act("withdraw", tournament.Id, "Couldn't withdraw.", cancellationToken);

        public Task<LobbyResult> StartAsync(int tournamentId, CancellationToken cancellationToken = default) =>
            Act("start", tournamentId, "Couldn't start that tournament.", cancellationToken);

        public Task<LobbyResult> CancelAsync(int tournamentId, CancellationToken cancellationToken = default) =>
            Act("cancel", tournamentId, "Couldn't cancel that tournament.", cancellationToken);

        /// <summary>Makes a tournament from a request built by <see cref="TournamentSetup"/>.</summary>
        public async Task<LobbyResult> CreateAsync(IDictionary<string, object> body, CancellationToken cancellationToken = default)
        {
            var result = await _api.CreateTournamentAsync(body, cancellationToken);
            return result.Ok
                ? new LobbyResult { Ok = true, TournamentId = result.Value.TournamentId }
                : new LobbyResult { Message = result.UserMessage("Couldn't create that tournament.") };
        }

        public async Task<LobbyResult> RefreshPodAsync(int tournamentId, CancellationToken cancellationToken = default)
        {
            var result = await _api.GetPodDraftStateAsync(tournamentId, cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load the draft.") };
            }

            Pod = result.Value;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        /// <summary>Takes a card from the "left" or "right" booster, then refreshes the draft.</summary>
        public async Task<LobbyResult> PickAsync(int tournamentId, string direction, BoardCard card, CancellationToken cancellationToken = default)
        {
            var result = await _api.PickPodDraftCardAsync(tournamentId, direction, card.CardId, cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't take that card.") };
            }

            return await RefreshPodAsync(tournamentId, cancellationToken);
        }

        public void Clear()
        {
            Mine = new List<TournamentSummary>();
            Open = new List<TournamentSummary>();
            Loaded = false;
            Current = null;
            Pod = null;
            Changed?.Invoke();
        }

        private async Task<LobbyResult> Seat(string action, TournamentSummary tournament, int? savedDecklistId, string failure, CancellationToken cancellationToken)
        {
            if (NeedsDeck(tournament) && !savedDecklistId.HasValue)
            {
                return new LobbyResult { Message = "Choose a deck to play in this tournament." };
            }

            var result = await _api.TournamentSeatAsync(action, tournament.Id, NeedsDeck(tournament) ? savedDecklistId : null, cancellationToken);
            return result.Ok ? new LobbyResult { Ok = true } : new LobbyResult { Message = result.UserMessage(failure) };
        }

        private async Task<LobbyResult> Act(string action, int tournamentId, string failure, CancellationToken cancellationToken)
        {
            var result = await _api.TournamentActionAsync(action, tournamentId, cancellationToken);
            return result.Ok ? new LobbyResult { Ok = true } : new LobbyResult { Message = result.UserMessage(failure) };
        }
    }

    /// <summary>How tournaments read, and what you can do with each.</summary>
    public static class TournamentDisplay
    {
        public static string StatusLabel(string status)
        {
            switch (status)
            {
                case "registration": return "Registration open";
                case "drafting": return "Drafting";
                case "in_progress": return "In progress";
                case "completed": return "Completed";
                case "cancelled": return "Cancelled";
                default: return status;
            }
        }

        public static string BracketLabel(string bracketType)
        {
            switch (bracketType)
            {
                case "single_elimination": return "Single elimination";
                case "double_elimination": return "Double elimination";
                case "swiss": return "Swiss rounds";
                default: return bracketType;
            }
        }

        /// <summary>"Single elimination - Power Duel": the bracket, then what is played in it.</summary>
        public static string MatchSummary(TournamentSummary tournament)
        {
            var parameters = tournament.MatchParams ?? new TournamentMatchParams();
            string deck;
            switch (parameters.DeckType)
            {
                case "custom_duel": deck = "Power Duel"; break;
                case "booster_draft": deck = "Booster Draft"; break;
                case "grid_draft_pod": deck = "Grid Draft (Pod)"; break;
                case "grid_draft_pod_playoff": deck = "Grid Draft (Pod Playoffs)"; break;
                default: deck = GameDisplay.DeckName(parameters.DeckType); break;
            }

            var selfDescribing = new[] { "sealed_deck", "booster_draft", "grid_draft", "grid_draft_pod", "grid_draft_pod_playoff", "custom_duel" };
            var play = Array.IndexOf(selfDescribing, parameters.DeckType) >= 0 ? deck : GameDisplay.FormatName(parameters.Format) + "  -  " + deck;
            return BracketLabel(tournament.BracketType) + "  -  " + play;
        }

        /// <summary>"Spring Open  -  Single elimination  -  Power Duel  -  In progress (5 of 8 joined)".</summary>
        public static string ListLine(TournamentSummary tournament, int myUserId)
        {
            var line = tournament.Name + "  -  " + StatusLabel(tournament.Status);
            if (tournament.CreatedByUserId == myUserId && tournament.MaxParticipants.HasValue && tournament.Status == "registration")
            {
                line += $" ({tournament.JoinedCount} of {tournament.MaxParticipants.Value} joined)";
            }

            if (tournament.Status == "completed" && !string.IsNullOrEmpty(tournament.WinnerUsername))
            {
                line += " (winner: " + tournament.WinnerUsername + ")";
            }

            return line;
        }

        public static string OpenLine(TournamentSummary tournament) =>
            $"{tournament.CreatorUsername}: {tournament.Name}";

        public static string OpenDetail(TournamentSummary tournament) =>
            MatchSummary(tournament) + (tournament.MaxParticipants.HasValue ? $"  -  {tournament.JoinedCount} of {tournament.MaxParticipants.Value} joined" : string.Empty);

        /// <summary>An invitation you haven't answered.</summary>
        public static List<TournamentSummary> Invitations(IEnumerable<TournamentSummary> mine) =>
            mine.Where(t => t.MyParticipantStatus == "invited").ToList();

        /// <summary>The ones you're in or run: not an unanswered invitation, not one you declined or withdrew from, not cancelled.</summary>
        public static List<TournamentSummary> Yours(IEnumerable<TournamentSummary> mine) =>
            mine.Where(t => t.Status != "cancelled" && !IsOut(t)).ToList();

        public static List<TournamentSummary> Cancelled(IEnumerable<TournamentSummary> mine) =>
            mine.Where(t => t.Status == "cancelled" && !IsOut(t)).ToList();

        private static bool IsOut(TournamentSummary t) =>
            t.MyParticipantStatus == "invited" || t.MyParticipantStatus == "declined" || t.MyParticipantStatus == "withdrawn";

        public static bool CanEditDeck(TournamentSummary t) =>
            t.Status == "registration" && TournamentFlow.NeedsDeck(t) && t.MyParticipantStatus == "joined";

        public static bool CanWithdraw(TournamentSummary t, int myUserId) =>
            t.Status == "registration" && t.CreatedByUserId != myUserId && t.MyParticipantStatus == "joined";

        // --- the tournament view ---

        public static bool IsCreator(TournamentStateResponse state, int myUserId) => state.Tournament.CreatedByUserId == myUserId;

        public static int JoinedCount(TournamentStateResponse state) => state.Participants.Count(p => p.Status == "joined");

        public static bool CanStart(TournamentStateResponse state, int myUserId) =>
            IsCreator(state, myUserId) && state.Tournament.Status == "registration" && JoinedCount(state) >= state.Tournament.MinParticipants;

        public static bool CanCancel(TournamentStateResponse state, int myUserId) =>
            IsCreator(state, myUserId) && (state.Tournament.Status == "registration" || state.Tournament.Status == "in_progress");

        public static Dictionary<int, string> Names(IEnumerable<TournamentParticipant> participants) =>
            participants.ToDictionary(p => p.Id, p => p.Username);

        /// <summary>"A vs B  -  A won", "A vs BYE  -  A won", "A vs B  -  in progress" or "  -  waiting".</summary>
        public static string MatchLabel(TournamentMatch match, IReadOnlyDictionary<int, string> names)
        {
            string Name(int? id) => !id.HasValue ? "BYE" : names.TryGetValue(id.Value, out var name) ? name : "?";
            var text = Name(match.Participant1Id) + " vs " + Name(match.Participant2Id);
            switch (match.Status)
            {
                case "completed":
                case "bye":
                    return text + "  -  " + Name(match.WinnerParticipantId) + " won";
                case "in_progress":
                    return text + "  -  in progress";
                default:
                    return text + "  -  waiting";
            }
        }

        public static string RoundHeading(TournamentRound round)
        {
            string prefix;
            switch (round.Bracket)
            {
                case "winners": prefix = "Winners round"; break;
                case "losers": prefix = "Losers round"; break;
                case "grand_final": prefix = "Grand final"; break;
                default: prefix = "Round"; break;
            }

            return prefix + " " + round.RoundNumber;
        }

        /// <summary>A round's matches: carried inline for a pod's own bracket, else looked up by round.</summary>
        public static List<TournamentMatch> MatchesOf(TournamentRound round, TournamentStateResponse state)
        {
            if (round.Matches != null)
            {
                return round.Matches;
            }

            return state.MatchesByRound != null && state.MatchesByRound.TryGetValue(round.Id, out var matches) ? matches : new List<TournamentMatch>();
        }

        /// <summary>The match you're in right now, if it has a game to go to.</summary>
        public static TournamentMatch MyLiveMatch(TournamentStateResponse state, int myUserId)
        {
            var mine = state.Participants.FirstOrDefault(p => p.UserId == myUserId && p.Status == "joined");
            if (mine == null)
            {
                return null;
            }

            var rounds = state.Rounds.Concat(state.Pods?.SelectMany(p => p.BracketRounds) ?? Enumerable.Empty<TournamentRound>());
            return rounds.SelectMany(r => MatchesOf(r, state))
                .FirstOrDefault(m => m.Status == "in_progress" && m.GameId.HasValue && (m.Participant1Id == mine.Id || m.Participant2Id == mine.Id));
        }

        /// <summary>"Swiss standings", best first: "1. A  -  3 wins".</summary>
        public static List<string> StandingLines(TournamentStateResponse state)
        {
            var names = Names(state.Participants);
            var lines = new List<string>();
            if (state.Standings == null)
            {
                return lines;
            }

            var place = 1;
            foreach (var pair in state.Standings)
            {
                var name = names.TryGetValue(pair.Key, out var n) ? n : "?";
                lines.Add($"{place++}. {name}  -  {pair.Value.Wins} win{(pair.Value.Wins == 1 ? string.Empty : "s")}");
            }

            return lines;
        }

        /// <summary>"Pod 2 (round 4/15): A, B, C" / "Finals (winner: A): ..." for a pod-draft tournament.</summary>
        public static string PodLine(TournamentPod pod)
        {
            var label = pod.Kind == "final" ? "Finals" : "Pod " + pod.PodNumber;
            string progress;
            if (pod.Status == "completed")
            {
                progress = "winner: " + (pod.WinnerUsername ?? "?");
            }
            else if (pod.Status == "playing")
            {
                progress = "playing its own bracket";
            }
            else
            {
                progress = pod.GameId.HasValue ? "drafting" : $"round {pod.CurrentRound}/15";
            }

            return $"{label} ({progress}): " + string.Join(", ", pod.Seats.OrderBy(s => s.SeatOrder).Select(s => s.Username));
        }

        /// <summary>The pod you're still drafting in (null when you aren't, or it's done drafting).</summary>
        public static TournamentPod MyDraftingPod(TournamentStateResponse state, string myUsername) =>
            state.Pods?.FirstOrDefault(p => p.Status == "drafting" && p.Seats.Any(s => s.Username == myUsername));
    }
}
