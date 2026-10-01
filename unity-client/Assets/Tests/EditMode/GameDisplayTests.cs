using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class GameDisplayTests
    {
        private const string Me = "bshaftoe";

        private static GameSummary Active(int id) => JsonConvert.DeserializeObject<GamesResponse>(TestFixtures.Read("games"))
            .Games.Single(g => g.Id == id);

        private static GameSummary InProgress(string currentTurn = null, bool yourTurn = false, bool awaitingYou = false, params string[] awaiting) =>
            new GameSummary
            {
                Status = "in_progress",
                CurrentTurnUsername = currentTurn,
                IsYourTurn = yourTurn,
                IsAwaitingYourResponse = awaitingYou,
                AwaitingResponseUsernames = awaiting.ToList(),
            };

        private static GameSummary Finished(params string[] winners) =>
            new GameSummary { Status = "completed", WinnerUsernames = winners.ToList() };

        [Test]
        public void StatusLine_ForTheRealActiveGames()
        {
            Assert.AreEqual("Your response is needed", GameDisplay.StatusLine(Active(407), Me));
            Assert.AreEqual("Your response is needed", GameDisplay.StatusLine(Active(405), Me));
            Assert.AreEqual("Your turn", GameDisplay.StatusLine(Active(406), Me));
        }

        [Test]
        public void StatusLine_WhenItsSomeoneElsesTurn()
        {
            Assert.AreEqual("Waiting for BotSage", GameDisplay.StatusLine(InProgress("BotSage"), Me));
        }

        [Test]
        public void StatusLine_WhenWaitingOnOthersToRespond()
        {
            Assert.AreEqual(
                "Waiting for Alice, Bob to respond",
                GameDisplay.StatusLine(InProgress("BotSage", awaiting: new[] { "Alice", "Bob" }), Me));
        }

        [Test]
        public void StatusLine_WithNothingKnown_JustSaysInProgress()
        {
            Assert.AreEqual("In progress", GameDisplay.StatusLine(InProgress(), Me));
        }

        [Test]
        public void YourResponseOutranksYourTurn()
        {
            Assert.AreEqual("Your response is needed", GameDisplay.StatusLine(InProgress(yourTurn: true, awaitingYou: true), Me));
        }

        [Test]
        public void StatusLine_ForFinishedGames()
        {
            Assert.AreEqual("You won", GameDisplay.StatusLine(Finished(Me), Me));
            Assert.AreEqual("You won", GameDisplay.StatusLine(Finished("jceddy", Me), Me), "a shared win still counts");
            Assert.AreEqual("Won by Alice", GameDisplay.StatusLine(Finished("Alice"), Me));
            Assert.AreEqual("Won by Alice, Bob", GameDisplay.StatusLine(Finished("Alice", "Bob"), Me));
            Assert.AreEqual("Finished", GameDisplay.StatusLine(Finished(), Me));
        }

        [Test]
        public void StatusLine_ForOtherStatuses_IsReadableRatherThanRaw()
        {
            Assert.AreEqual("Abandoned", GameDisplay.StatusLine(new GameSummary { Status = "abandoned" }, Me));
            Assert.AreEqual("Waiting for ready", GameDisplay.StatusLine(new GameSummary { Status = "waiting_for_ready" }, Me));
        }

        [Test]
        public void EveryRealFinishedGame_GetsANonEmptyStatusLine_IncludingAbandonedOnes()
        {
            var past = JsonConvert.DeserializeObject<GamesResponse>(TestFixtures.Read("games_past")).Games;

            Assert.IsTrue(past.Any(g => g.Status == "abandoned"), "the capture includes abandoned games");
            foreach (var game in past)
            {
                Assert.IsNotEmpty(GameDisplay.StatusLine(game, Me), "game " + game.Id);
            }
        }

        [TestCase(true, false, true)]
        [TestCase(false, true, true)]
        [TestCase(false, false, false)]
        public void NeedsYou_MeansYourTurnOrYourResponse(bool yourTurn, bool awaitingYou, bool expected)
        {
            Assert.AreEqual(expected, GameDisplay.NeedsYou(InProgress(yourTurn: yourTurn, awaitingYou: awaitingYou)));
        }

        [Test]
        public void ACompletedGame_NeverNeedsYou()
        {
            var game = Finished(Me);
            game.IsYourTurn = true;

            Assert.IsFalse(GameDisplay.NeedsYou(game));
        }

        [Test]
        public void Opponents_ListsTheOthersInSeatOrder()
        {
            Assert.AreEqual("BotSage, BotSageQuick, BotSageDeep", GameDisplay.Opponents(Active(407), Me));
            Assert.AreEqual("BotSage", GameDisplay.Opponents(Active(405), Me));
        }

        [Test]
        public void Opponents_WhenAlone_SaysSo()
        {
            var game = new GameSummary { Players = new List<GamePlayerSummary> { new GamePlayerSummary { Username = Me } } };

            Assert.AreEqual("no opponents", GameDisplay.Opponents(game, Me));
        }

        [Test]
        public void Settings_ReadsLikeTheWebDialog()
        {
            Assert.AreEqual("Traditional  -  Structure  -  First to 3", GameDisplay.Settings(Active(407)));
            Assert.AreEqual("Traditional  -  jceddy's 75 Card  -  First to 2", GameDisplay.Settings("standard", "jceddys_75", 2));
        }

        [Test]
        public void Names_FallBackToTheRawIdPrettified_ForThingsThisClientDoesntKnowYet()
        {
            Assert.AreEqual("Weekly sealed pool", GameDisplay.DeckName("weekly_sealed_pool"));
            Assert.AreEqual("Some new format", GameDisplay.FormatName("some_new_format"));
            Assert.AreEqual("My Aggro List", GameDisplay.DeckName("custom", "My Aggro List"));
            Assert.AreEqual("Custom Decklist", GameDisplay.DeckName("custom", null));
        }

        private static OpenGameListing Posted(int createdBy, string creatorUsername) => new OpenGameListing
        {
            CreatedByUserId = createdBy,
            CreatorUsername = creatorUsername,
            TargetPlayerCount = 4,
            JoinedCount = 1,
            Settings = new OpenGameSettings { Format = "standard", DeckType = "power", WinsNeeded = 3 },
        };

        [Test]
        public void Listing_ShowsWhoPostedItAndHowFullItIs()
        {
            var listing = Posted(createdBy: 7, creatorUsername: "Alice");

            Assert.AreEqual("Alice's game  -  2 of 4 seated", GameDisplay.ListingTitle(listing, yourUserId: 2));
            Assert.AreEqual("Traditional  -  Power  -  First to 3", GameDisplay.ListingSettings(listing));
        }

        [Test]
        public void Listing_YouPosted_SaysYourGame()
        {
            Assert.AreEqual("Your game  -  2 of 4 seated", GameDisplay.ListingTitle(Posted(2, "bshaftoe"), yourUserId: 2));
        }

        [Test]
        public void Listing_YouPosted_WithNoCreatorName_StillSaysYourGame()
        {
            // The server omits creator_username from the list of listings you posted.
            Assert.AreEqual("Your game  -  2 of 4 seated", GameDisplay.ListingTitle(Posted(2, null), yourUserId: 2));
        }

        [TestCase(null)]
        [TestCase("")]
        [TestCase("   ")]
        public void Listing_OfSomeoneElseWithNoName_NeverShowsAsABareApostropheS(string name)
        {
            var title = GameDisplay.ListingTitle(Posted(7, name), yourUserId: 2);

            Assert.AreEqual("Someone's game  -  2 of 4 seated", title);
        }
    }
}
