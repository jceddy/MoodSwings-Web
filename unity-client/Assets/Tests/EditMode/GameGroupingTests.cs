using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class GameGroupingTests
    {
        private static GamesResponse Past() => JsonConvert.DeserializeObject<GamesResponse>(TestFixtures.Read("games_past"));

        private static GameSummary Game(int id, int? number = null, int? draft = null, int? match = null, string status = "completed") =>
            new GameSummary { Id = id, MatchGameNumber = number, DraftMatchId = draft, GameMatchId = match, Status = status };

        [Test]
        public void AMatchsGames_ShareItsKey_AndAOneOffGameHasNone()
        {
            Assert.AreEqual("draft:7", GameDisplay.MatchKey(Game(1, 1, draft: 7)));
            Assert.AreEqual("game:11", GameDisplay.MatchKey(Game(2, 1, match: 11)));
            Assert.IsNull(GameDisplay.MatchKey(Game(3)));
        }

        [Test]
        public void TheGamesOfAMatch_BecomeOneEntry_AtTheFirstOnesPlace_LatestGameFirst()
        {
            var games = new[]
            {
                Game(10, 2, draft: 5), Game(20), Game(9, 1, draft: 5), Game(30, 1, match: 8), Game(11, 3, draft: 5), Game(40),
            };

            var entries = GameDisplay.Group(games);

            Assert.AreEqual(4, entries.Count);
            Assert.IsTrue(entries[0].IsMatch);
            CollectionAssert.AreEqual(new[] { 11, 10, 9 }, entries[0].MatchGames.Select(g => g.Id).ToArray(), "game 3, 2, 1");
            Assert.AreEqual(11, entries[0].Game.Id, "the latest stands for the match");
            Assert.IsFalse(entries[1].IsMatch);
            Assert.AreEqual(20, entries[1].Game.Id);
            Assert.IsTrue(entries[2].IsMatch);
            Assert.AreEqual(1, entries[2].MatchGames.Count);
            Assert.AreEqual(40, entries[3].Game.Id);
        }

        [Test]
        public void TwoMatchesWithTheSameNumber_AreNotConfused_WhenOneIsADraftAndOneIsNot()
        {
            var entries = GameDisplay.Group(new[] { Game(1, 1, draft: 4), Game(2, 1, match: 4) });

            Assert.AreEqual(2, entries.Count);
        }

        [Test]
        public void TheRealPastGames_GroupIntoMatches()
        {
            var past = Past().Games;
            var entries = GameDisplay.Group(past);

            Assert.IsTrue(entries.Count < past.Count);
            Assert.AreEqual(past.Count, entries.Sum(e => e.IsMatch ? e.MatchGames.Count : 1), "no game lost or repeated");
            var sealedMatch = entries.Single(e => e.MatchGames.Any(g => g.Id == 351));
            CollectionAssert.AreEqual(new[] { 351, 350, 349 }, sealedMatch.MatchGames.Select(g => g.Id).ToArray());
            var custom = entries.Single(e => e.MatchGames.Any(g => g.Id == 337));
            CollectionAssert.AreEqual(new[] { 337 }, custom.MatchGames.Select(g => g.Id).Where(id => id == 337).ToArray());
            Assert.AreEqual("game:11", GameDisplay.MatchKey(custom.Game));
        }

        [Test]
        public void TheMatchScore_AndResult_ReadFromEitherKindOfMatch()
        {
            var draft = Past().Games.First(g => g.Id == 351).DraftMatch;
            var game = Past().Games.First(g => g.Id == 337).GameMatch;

            Assert.AreEqual("Match score: you 1, opponent 2 (first to 2 wins)", GameDisplay.MatchScore(draft));
            Assert.AreEqual("jceddy won the match", GameDisplay.MatchResult(draft), "a draft names its winner singly");
            Assert.AreEqual("jceddy won the match", GameDisplay.MatchResult(game));
            Assert.IsNull(GameDisplay.MatchResult(new MatchSummary { Status = "in_progress" }));
            Assert.AreEqual("Match over", GameDisplay.MatchResult(new MatchSummary { Status = "completed" }));
            Assert.AreEqual("A & B won the match", GameDisplay.MatchResult(new MatchSummary { Status = "completed", WinnerUsernames = { "A", "B" } }));
        }

        [Test]
        public void AGamesWhen_IsItsDay()
        {
            Assert.AreEqual("Finished 2026-09-01", GameDisplay.WhenLine(new GameSummary { Status = "completed", CompletedAt = "2026-09-01 10:15:00" }));
            Assert.AreEqual("Started 2026-09-02", GameDisplay.WhenLine(new GameSummary { Status = "in_progress", StartedAt = "2026-09-02 08:00:00" }));
            Assert.AreEqual("Started 2026-09-03", GameDisplay.WhenLine(new GameSummary { Status = "in_progress", CreatedAt = "2026-09-03 08:00:00" }));
            Assert.AreEqual("Waiting to start", GameDisplay.WhenLine(new GameSummary { Status = "waiting" }));
            Assert.AreEqual("Finished", GameDisplay.WhenLine(new GameSummary { Status = "completed" }));
        }
    }
}
