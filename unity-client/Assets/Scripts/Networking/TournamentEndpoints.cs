using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class TournamentEndpoints
    {
        /// <summary>GET /tournaments?mine=1 -- the ones you made, were invited to, joined or cast; without it, the open ones you could join.</summary>
        public static Task<ApiResult<TournamentsResponse>> ListTournamentsAsync(
            this ApiClient api, bool mine, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<TournamentsResponse>(mine ? "/tournaments?mine=1" : "/tournaments", cancellationToken);
        }

        public static Task<ApiResult<TournamentStateResponse>> GetTournamentStateAsync(
            this ApiClient api, int tournamentId, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<TournamentStateResponse>("/tournaments/state?id=" + tournamentId, cancellationToken);
        }

        /// <summary>POST /tournaments -- 201 with the new id. The body is the game-creation shape plus the tournament's own fields.</summary>
        public static Task<ApiResult<CreateTournamentResponse>> CreateTournamentAsync(
            this ApiClient api, IDictionary<string, object> body, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<CreateTournamentResponse>("/tournaments", body, cancellationToken);
        }

        /// <summary>POST /tournaments/{join, accept-invite, submit-deck}: a Power Duel tournament also takes your deck (a saved decklist id).</summary>
        public static Task<ApiResult<ApiEnvelope>> TournamentSeatAsync(
            this ApiClient api, string action, int tournamentId, int? savedDecklistId = null, CancellationToken cancellationToken = default)
        {
            var body = new Dictionary<string, object> { ["tournament_id"] = tournamentId };
            if (savedDecklistId.HasValue)
            {
                body["saved_decklist_id"] = savedDecklistId.Value;
            }

            return api.PostAsync<ApiEnvelope>("/tournaments/" + action, body, cancellationToken);
        }

        /// <summary>POST /tournaments/{decline-invite, withdraw, start, cancel}.</summary>
        public static Task<ApiResult<ApiEnvelope>> TournamentActionAsync(
            this ApiClient api, string action, int tournamentId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>(
                "/tournaments/" + action, new Dictionary<string, object> { ["tournament_id"] = tournamentId }, cancellationToken);
        }

        public static Task<ApiResult<PodDraftStateResponse>> GetPodDraftStateAsync(
            this ApiClient api, int tournamentId, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<PodDraftStateResponse>("/tournaments/pod-draft/state?tournament_id=" + tournamentId, cancellationToken);
        }

        /// <summary>POST /tournaments/pod-draft/pick -- take a card from the "left" or "right" booster.</summary>
        public static Task<ApiResult<ApiEnvelope>> PickPodDraftCardAsync(
            this ApiClient api, int tournamentId, string direction, int cardId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>(
                "/tournaments/pod-draft/pick",
                new Dictionary<string, object> { ["tournament_id"] = tournamentId, ["direction"] = direction, ["card_id"] = cardId },
                cancellationToken);
        }
    }
}
