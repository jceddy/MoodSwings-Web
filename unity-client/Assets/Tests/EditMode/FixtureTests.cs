using System.IO;
using MoodSwings.Networking;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    /// <summary>
    /// Models checked against real server responses captured by
    /// tools/capture_fixtures.py. Add a test here whenever a new response
    /// model lands, so it's proven against what the server actually sends.
    /// </summary>
    public class FixtureTests
    {
        private static string Fixture(string name)
        {
            return File.ReadAllText(Path.Combine(Application.dataPath, "Tests", "Fixtures", name + ".json"));
        }

        [Test]
        public void Me_DeserializesWithPreferences()
        {
            var response = JsonConvert.DeserializeObject<UserResponse>(Fixture("me"));

            Assert.AreEqual("ok", response.Status);
            Assert.AreEqual(2, response.User.Id);
            Assert.AreEqual("bshaftoe", response.User.Username);
            Assert.IsNotNull(response.User.AllowCustomContent);
            Assert.AreEqual("above_play_area", response.User.BoardLayoutPreference);
            Assert.AreEqual(false, response.User.PauseBeforeOwnTurn);
        }

        [TestCase(405, 2)]
        [TestCase(406, 3)]
        [TestCase(407, 4)]
        public void GameStateFixtures_CoverTwoThreeAndFourPlayers(int gameId, int expectedPlayers)
        {
            var state = JObject.Parse(Fixture($"game_{gameId}_state"));

            Assert.AreEqual("ok", (string)state["status"]);
            Assert.AreEqual(expectedPlayers, ((JArray)state["players"]).Count);
            Assert.AreEqual(gameId, (int)state["game"]["id"]);
        }
    }
}
