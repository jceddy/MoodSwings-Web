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

        /// <param name="siteRoot">e.g. https://moodswings-dev.jceddy.com -- no trailing slash required.</param>
        public ApiConfig(string siteRoot)
        {
            SiteRoot = siteRoot.TrimEnd('/');
        }
    }
}
