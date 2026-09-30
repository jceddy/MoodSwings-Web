namespace MoodSwings.Networking
{
    /// <summary>
    /// Where the session_token cookie value lives between launches. The
    /// token is a credential, so the real (Phase 1) implementation should
    /// use platform secure storage rather than PlayerPrefs.
    /// </summary>
    public interface ISessionStore
    {
        string Load();

        void Save(string sessionToken);

        void Clear();
    }

    public sealed class InMemorySessionStore : ISessionStore
    {
        private string _token;

        public string Load() => _token;

        public void Save(string sessionToken) => _token = sessionToken;

        public void Clear() => _token = null;
    }
}
