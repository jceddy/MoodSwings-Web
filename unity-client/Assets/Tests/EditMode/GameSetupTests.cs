using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class GameSetupTests
    {
        private static JObject Body(object body) => JObject.Parse(JsonConvert.SerializeObject(body));

        private static GamesResponse PastGames() =>
            JsonConvert.DeserializeObject<GamesResponse>(TestFixtures.Read("games_past"));

        [Test]
        public void Defaults_AreTraditionalStructureFirstToThree()
        {
            var setup = new GameSetup();

            Assert.AreEqual("standard", setup.Format);
            Assert.AreEqual("structure", setup.DeckType);
            Assert.AreEqual(3, setup.WinsNeeded);
            Assert.IsFalse(setup.DefaultSelectionsMode);
        }

        [Test]
        public void DirectGameBody_CarriesTheOpponentsAndSettings()
        {
            var setup = new GameSetup { DeckType = "power", WinsNeeded = 2, DefaultSelectionsMode = true };
            setup.OpponentUserIds.AddRange(new[] { 18, 20 });

            var body = Body(setup.ToDirectGameBody());

            Assert.AreEqual("standard", (string)body["format"]);
            Assert.AreEqual("power", (string)body["deck_type"]);
            Assert.AreEqual(2, (int)body["wins_needed"]);
            Assert.AreEqual(true, (bool)body["default_selections_mode"]);
            CollectionAssert.AreEqual(new[] { 18, 20 }, body["opponent_user_ids"].Values<int>().ToArray());
            Assert.IsNull(body["bot_goes_first"], "only sent when asked for");
            Assert.IsNull(body["target_player_count"], "that's for the open lobby");
        }

        [Test]
        public void DirectGameBody_SendsBotGoesFirstOnlyWhenChosen()
        {
            var setup = new GameSetup { BotGoesFirst = true };
            setup.OpponentUserIds.Add(18);

            Assert.AreEqual(true, (bool)Body(setup.ToDirectGameBody())["bot_goes_first"]);
        }

        [Test]
        public void OpenGameBody_HasTheTargetPlayerCount_AndNoOpponents()
        {
            var setup = new GameSetup { OpenLobbyPlayerCount = 3, DeckType = "jceddys_75" };
            setup.OpponentUserIds.Add(18); // ignored for an open game

            var body = Body(setup.ToOpenGameBody());

            Assert.AreEqual(3, (int)body["target_player_count"]);
            Assert.AreEqual("jceddys_75", (string)body["deck_type"]);
            Assert.AreEqual("standard", (string)body["format"]);
            Assert.IsNull(body["opponent_user_ids"]);
        }

        [Test]
        public void ValidateDirectGame_RequiresBetweenOneAndThreeOpponents()
        {
            var setup = new GameSetup();
            Assert.AreEqual("Pick at least one opponent.", setup.ValidateDirectGame());

            foreach (var opponents in new[] { 1, 2, 3 })
            {
                setup.OpponentUserIds = Enumerable.Range(1, opponents).ToList();
                Assert.IsNull(setup.ValidateDirectGame(), opponents + " opponents");
            }

            setup.OpponentUserIds = Enumerable.Range(1, 4).ToList();
            StringAssert.Contains("at most 3 opponents", setup.ValidateDirectGame());
        }

        [TestCase(1, false)]
        [TestCase(2, true)]
        [TestCase(3, true)]
        [TestCase(4, true)]
        [TestCase(5, false)]
        public void ValidateOpenGame_AllowsTwoToFourPlayers(int players, bool valid)
        {
            var setup = new GameSetup { OpenLobbyPlayerCount = players };

            Assert.AreEqual(valid, setup.ValidateOpenGame() == null);
        }

        [Test]
        public void AnUnsupportedDeck_IsRefusedForBothKinds()
        {
            var setup = new GameSetup { DeckType = "quick_draft" };
            setup.OpponentUserIds.Add(18);

            Assert.AreEqual("Pick a deck.", setup.ValidateDirectGame());
            Assert.AreEqual("Pick a deck.", setup.ValidateOpenGame());
        }

        [Test]
        public void DeckOptions_AreTheServersReadyMadeDeckTypes()
        {
            // The deck_type values php-app's GameService::deckCardIdsFor() builds without further input.
            CollectionAssert.AreEqual(
                new[] { "structure", "power", "jceddys_75", "one_of_each" }, GameSetup.DeckOptions.Select(o => o.Id).ToArray());
            foreach (var option in GameSetup.DeckOptions)
            {
                Assert.IsNotEmpty(option.Label);
                Assert.IsNotEmpty(option.Description);
            }
        }

        [Test]
        public void ForRematch_OfARealFinishedTraditionalGame_KeepsTheOpponentsAndSettings()
        {
            // Past game 362 in the captured data: Traditional, Structure, jceddy (1) vs bshaftoe (2).
            var game = PastGames().Games.Single(g => g.Id == 362);

            var setup = GameSetup.ForRematch(game, yourUserId: 2);

            Assert.IsNotNull(setup);
            CollectionAssert.AreEqual(new[] { 1 }, setup.OpponentUserIds);
            Assert.AreEqual("jceddy", setup.OpponentNames[1], "so the screen can name an opponent who isn't a friend or bot");
            Assert.AreEqual("structure", setup.DeckType);
            Assert.AreEqual(3, setup.WinsNeeded);
            Assert.IsNull(setup.ValidateDirectGame());
        }

        [Test]
        public void ForRematch_IsOnlyOfferedForGamesThisCanExpress()
        {
            var games = PastGames().Games;
            var offered = games.Where(g => GameSetup.ForRematch(g, 2) != null).ToList();

            Assert.AreEqual(4, offered.Count, "the four Traditional/Structure games in the capture");
            Assert.IsTrue(offered.All(g => g.Format == "standard" && g.DeckType == "structure"));
            Assert.IsNull(GameSetup.ForRematch(games.First(g => g.Format == "draft"), 2));
            Assert.IsNull(GameSetup.ForRematch(games.First(g => g.Format == "team" && g.DeckType == "structure"), 2));
        }
    }
}
