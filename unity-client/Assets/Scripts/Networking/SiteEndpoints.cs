using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class SiteEndpoints
    {
        /// <summary>
        /// The deployed web product's version (the repo-root VERSION file,
        /// served as a static file at the site root). Shown in the UI; not
        /// the client's own version.
        /// </summary>
        public static Task<ApiResult<string>> GetServerVersionAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetSiteTextAsync("/VERSION", cancellationToken);
        }
    }
}
