namespace MoodSwings.Networking
{
    /// <summary>
    /// Where the backend lives. php-app is mounted under "/app" on the
    /// site root (web-static/js/app.js: API_BASE = '/app'), while static
    /// files -- card art, VERSION -- are served from the site root itself,
    /// so both are exposed.
    /// </summary>
    public sealed class ApiConfig
    {
        public const string DevSiteRoot = "https://moodswings-dev.jceddy.com";

        public static ApiConfig Dev => new ApiConfig(DevSiteRoot);

        public string SiteRoot { get; }

        public string ApiBase => SiteRoot + "/app";

        /// <summary>
        /// Sent on every request. Always set it explicitly: the host's
        /// filter answers 406 to some generic/default agents (Python's,
        /// a bare "Mozilla/5.0"), and a real name also makes this client
        /// identifiable in server logs.
        /// </summary>
        public string UserAgent { get; }

        /// <param name="siteRoot">e.g. https://moodswings-dev.jceddy.com -- no trailing slash required.</param>
        public ApiConfig(string siteRoot, string userAgent = "MoodSwings")
        {
            SiteRoot = siteRoot.TrimEnd('/');
            UserAgent = userAgent;
        }
    }
}
