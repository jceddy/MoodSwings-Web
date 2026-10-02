using System.IO;
using UnityEngine;

namespace MoodSwings.Tests
{
    public static class TestFixtures
    {
        /// <summary>
        /// A JSON response body from Assets/Tests/Fixtures. Most are real
        /// captures (tools/capture_fixtures.py); friends_invites_pending.json
        /// is hand-written to the server's documented columns, because the
        /// capture account had no pending requests to record.
        /// </summary>
        public static string Read(string name)
        {
            return File.ReadAllText(Path.Combine(Application.dataPath, "Tests", "Fixtures", name + ".json"));
        }
    }
}
