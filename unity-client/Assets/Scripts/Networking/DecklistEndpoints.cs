using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    /// <summary>Saved decklists and the card catalog behind the deck builder.</summary>
    public static class DecklistEndpoints
    {
        /// <summary>GET /decklists.</summary>
        public static Task<ApiResult<DecklistsResponse>> ListDecklistsAsync(this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<DecklistsResponse>("/decklists", cancellationToken);
        }

        /// <summary>GET /decklists/view -- one of yours, or a friend's shared with friends.</summary>
        public static Task<ApiResult<DecklistViewResponse>> ViewDecklistAsync(this ApiClient api, int id, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<DecklistViewResponse>("/decklists/view?id=" + id, cancellationToken);
        }

        /// <summary>POST /decklists. Card ids repeat for copies.</summary>
        public static Task<ApiResult<DecklistCreatedResponse>> CreateDecklistAsync(
            this ApiClient api, string name, string visibility, IEnumerable<int> cardIds, IEnumerable<int> sideboardCardIds,
            CancellationToken cancellationToken = default)
        {
            return api.PostAsync<DecklistCreatedResponse>(
                "/decklists",
                new { name, visibility, card_ids = cardIds.ToArray(), sideboard_card_ids = sideboardCardIds.ToArray() },
                cancellationToken);
        }

        /// <summary>POST /decklists/update.</summary>
        public static Task<ApiResult<ApiEnvelope>> UpdateDecklistAsync(
            this ApiClient api, int id, string name, string visibility, IEnumerable<int> cardIds, IEnumerable<int> sideboardCardIds,
            CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>(
                "/decklists/update",
                new { id, name, visibility, card_ids = cardIds.ToArray(), sideboard_card_ids = sideboardCardIds.ToArray() },
                cancellationToken);
        }

        /// <summary>POST /decklists/delete.</summary>
        public static Task<ApiResult<ApiEnvelope>> DeleteDecklistAsync(this ApiClient api, int id, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>("/decklists/delete", new { id }, cancellationToken);
        }

        /// <summary>GET /cards/catalog.</summary>
        public static Task<ApiResult<CatalogResponse>> GetCardCatalogAsync(this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<CatalogResponse>("/cards/catalog", cancellationToken);
        }
    }
}
