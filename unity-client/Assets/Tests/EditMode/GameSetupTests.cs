using System.Collections.Generic;
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
        [Test]
        public void TraditionalDuelTeamAndDraftFormats_AreOffered_TraditionalFirst()
        {
            CollectionAssert.AreEqual(new[] { "standard", "duel", "team", "closed_team", "draft", "sealed_deck", "sealed_pool_of_the_day" }, GameSetup.FormatOptions.Select(f => f.Id).ToArray());
            Assert.AreEqual("standard", new GameSetup().Format);
        }

        private static GameSetup Team(string format = "team", params int[] opponents) =>
            new GameSetup { Format = format, OpponentUserIds = opponents.ToList() };

        [Test]
        public void ATeamGame_NeedsExactlyThreeOpponents_AndAPartnerOrChance()
        {
            Assert.IsNotNull(Team(opponents: new[] { 18, 20 }).ValidateDirectGame(), "two isn't enough");
            StringAssert.Contains("exactly 3", Team(opponents: new[] { 18, 20 }).ValidateDirectGame());

            var three = Team(opponents: new[] { 18, 20, 21 });
            Assert.IsNotNull(three.ValidateDirectGame(), "no partner chosen yet");
            StringAssert.Contains("partner", three.ValidateDirectGame());

            three.PartnerUserId = 20;
            Assert.IsNull(three.ValidateDirectGame());

            three.PartnerUserId = 99;
            Assert.IsNotNull(three.ValidateDirectGame(), "the partner has to be one of the opponents");

            three.RandomTeams = true;
            Assert.IsNull(three.ValidateDirectGame(), "or left to chance");
        }

        [Test]
        public void ATeamGame_SendsItsPartner_OrAskForRandomTeams()
        {
            var chosen = Team("closed_team", 18, 20, 21);
            chosen.PartnerUserId = 20;
            var body = chosen.ToDirectGameBody();
            Assert.AreEqual("closed_team", body["format"]);
            Assert.AreEqual(20, body["partner_user_id"]);
            Assert.IsFalse(body.ContainsKey("random_teams"));

            chosen.RandomTeams = true;
            body = chosen.ToDirectGameBody();
            Assert.AreEqual(true, body["random_teams"]);
            Assert.IsFalse(body.ContainsKey("partner_user_id"));
        }

        [Test]
        public void ATeamGame_IsAlwaysFourPlayers_AndFromTheLobbyNeedsNoCount()
        {
            var setup = Team("team", 18);
            Assert.AreEqual(4, setup.PlayerCount);

            setup.PostToOpenLobby = true;
            setup.OpenLobbyPlayerCount = 2;
            Assert.AreEqual(4, setup.EffectiveOpenLobbyPlayerCount);
            Assert.AreEqual(4, setup.ToOpenGameBody()["target_player_count"]);
            Assert.IsFalse(setup.OpenLobbyCountIsChoosable);
            Assert.IsFalse(setup.ToOpenGameBody().ContainsKey("partner_user_id"), "teams are drawn at random once everyone has joined");
        }

        [Test]
        public void TheSmallPowerDeck_IsNotOfferedForTeamPlay()
        {
            Assert.IsTrue(new GameSetup().DecksForFormat.Any(d => d.Id == "power"));

            var setup = Team("team", 18, 20, 21);
            setup.DeckType = "power";
            Assert.IsFalse(setup.DecksForFormat.Any(d => d.Id == "power"));
            Assert.IsNotNull(setup.ValidateDirectGame());

            setup.Normalize(false);
            Assert.AreEqual("structure", setup.DeckType, "moved to a deck a team can use");
        }

        [Test]
        public void ChangingToTeamPlay_DropsWhatNoLongerApplies_AndPicksAPartner()
        {
            var setup = Team("team", 18, 20, 21);
            setup.BotGoesFirst = true;
            setup.SynchronousMode = true;

            setup.Normalize(true);

            Assert.IsFalse(setup.BotGoesFirst);
            Assert.IsFalse(setup.SynchronousMode, "synchronous is for two players");
            Assert.AreEqual(18, setup.PartnerUserId, "defaults to the first opponent");
            Assert.IsNull(setup.ValidateDirectGame());
        }

        [Test]
        public void LeavingTeamPlay_ForgetsThePartner()
        {
            var setup = new GameSetup { Format = "standard", OpponentUserIds = { 18, 20 }, PartnerUserId = 18, RandomTeams = true };

            setup.Normalize(false);

            Assert.IsNull(setup.PartnerUserId);
            Assert.IsFalse(setup.RandomTeams);
            Assert.IsFalse(setup.ToDirectGameBody().ContainsKey("partner_user_id"));
        }

        [Test]
        public void BestOfThree_IsAlwaysAvailableInTeamPlay()
        {
            Assert.IsTrue(Team("team", 18, 20, 21).BestOfThreeAvailable);
            Assert.IsTrue(Team("closed_team").BestOfThreeAvailable, "four players however many are picked so far");
        }

        [TestCase("team", 0, 1, 1)]
        [TestCase("team", 1, 0, 0)]
        [TestCase("team", 2, 3, 3)]
        [TestCase("closed_team", 0, 2, 2)]
        [TestCase("closed_team", 1, 3, 3)]
        [TestCase("closed_team", 3, 1, 1)]
        public void ATeamRematch_SeatsYouWithTheSamePartner(string format, int yourSeat, int partnerSeat, int expectedPartnerUserId)
        {
            // Users 0..3 sit in seats 0..3; the partner is whoever sits where the format puts them.
            var players = Enumerable.Range(0, 4).Select(i => new GamePlayerSummary { UserId = i, Username = "P" + i, SeatOrder = i }).ToList();
            var game = new GameSummary { Format = format, DeckType = "structure", Players = players };

            var setup = GameSetup.ForRematch(game, yourSeat);

            Assert.AreEqual(format, setup.Format);
            Assert.AreEqual(expectedPartnerUserId, setup.PartnerUserId);
            Assert.AreEqual(partnerSeat, expectedPartnerUserId);
            CollectionAssert.AreEquivalent(players.Select(p => p.UserId).Where(i => i != yourSeat).ToArray(), setup.OpponentUserIds);
        }

        [TestCase("standard", 1, true)]
        [TestCase("duel", 1, true)]
        [TestCase("standard", 2, false)]
        [TestCase("duel", 3, false)]
        public void BestOfThree_IsForTwoPlayers(string format, int opponents, bool expected)
        {
            var setup = new GameSetup { Format = format, OpponentUserIds = Enumerable.Range(1, opponents).ToList(), BestOfThree = true };

            Assert.AreEqual(expected, setup.BestOfThreeAvailable);
            Assert.AreEqual(expected, setup.ToDirectGameBody().ContainsKey("best_of_three"));
        }

        [Test]
        public void BestOfThree_IsSwitchedOffWhenAThirdPlayerJoins()
        {
            var setup = new GameSetup { OpponentUserIds = { 18 }, BestOfThree = true };
            setup.OpponentUserIds.Add(20);

            setup.Normalize(false);

            Assert.IsFalse(setup.BestOfThree);
        }

        [Test]
        public void ARematchOfAMatchGame_AsksForTheMatchAgain()
        {
            var game = new GameSummary
            {
                Format = "standard",
                DeckType = "structure",
                GameMatch = new MatchSummary { Status = "completed" },
                Players = new List<GamePlayerSummary>
                {
                    new GamePlayerSummary { UserId = 1, Username = "me" },
                    new GamePlayerSummary { UserId = 18, Username = "BotSage" },
                },
            };

            Assert.IsTrue(GameSetup.ForRematch(game, 1).BestOfThree);
        }

        [Test]
        public void TheFormat_GoesIntoBothBodies()
        {
            var setup = new GameSetup { Format = GameSetup.DuelFormat, OpponentUserIds = { 18 } };

            Assert.AreEqual("duel", setup.ToDirectGameBody()["format"]);
            Assert.AreEqual("duel", setup.ToOpenGameBody()["format"]);
        }

        [Test]
        public void ADuelFromTheLobby_SeatsExactlyTwo_WhateverWasAskedFor()
        {
            var setup = new GameSetup { Format = GameSetup.DuelFormat, PostToOpenLobby = true, OpenLobbyPlayerCount = 4 };

            Assert.AreEqual(2, setup.EffectiveOpenLobbyPlayerCount);
            Assert.AreEqual(2, setup.ToOpenGameBody()["target_player_count"]);
            Assert.IsFalse(setup.OpenLobbyCountIsChoosable);
            Assert.AreEqual(2, setup.PlayerCount);
        }

        [Test]
        public void ATraditionalLobbyGame_KeepsTheChosenCount()
        {
            var setup = new GameSetup { PostToOpenLobby = true, OpenLobbyPlayerCount = 3 };

            Assert.AreEqual(3, setup.ToOpenGameBody()["target_player_count"]);
            Assert.IsTrue(setup.OpenLobbyCountIsChoosable);
        }

        [TestCase("standard", 1, true, true)]
        [TestCase("duel", 1, true, true)]
        [TestCase("standard", 2, true, false)]
        [TestCase("standard", 3, true, false)]
        [TestCase("standard", 1, false, false)]
        public void Synchronous_NeedsTwoPlayers_ASupportedFormat_AndTheServersSaySo(string format, int opponents, bool serverAllows, bool expected)
        {
            var setup = new GameSetup { Format = format, OpponentUserIds = Enumerable.Range(1, opponents).ToList() };

            Assert.AreEqual(expected, setup.SynchronousModeAvailable(serverAllows));
        }

        [Test]
        public void Synchronous_IsSentOnlyWhenOn_AndSwitchedOffWhenItNoLongerApplies()
        {
            var setup = new GameSetup { OpponentUserIds = { 18 }, SynchronousMode = true };
            Assert.AreEqual(true, setup.ToDirectGameBody()["synchronous_mode"]);

            setup.OpponentUserIds.Add(20);
            setup.Normalize(synchronousModeAllowedByServer: true);

            Assert.IsFalse(setup.SynchronousMode);
            Assert.IsFalse(setup.ToDirectGameBody().ContainsKey("synchronous_mode"));
        }

        [Test]
        public void Synchronous_IsSwitchedOff_WhenTheServerTurnsTheFeatureOff()
        {
            var setup = new GameSetup { OpponentUserIds = { 18 }, SynchronousMode = true };

            setup.Normalize(synchronousModeAllowedByServer: false);

            Assert.IsFalse(setup.SynchronousMode);
        }

        [Test]
        public void ADuelRematch_KeepsItsFormat_ButAnOtherFormatStillHasNone()
        {
            var duel = new GameSummary
            {
                Format = "duel",
                DeckType = "power",
                WinsNeeded = 3,
                Players = new List<GamePlayerSummary>
                {
                    new GamePlayerSummary { UserId = 1, Username = "me" },
                    new GamePlayerSummary { UserId = 18, Username = "BotSage" },
                },
            };
            var draft = new GameSummary { Format = "draft", DeckType = "quick_draft", Players = duel.Players };

            var rematch = GameSetup.ForRematch(duel, 1);

            Assert.AreEqual("duel", rematch.Format);
            Assert.AreEqual("power", rematch.DeckType);
            Assert.IsNull(GameSetup.ForRematch(draft, 1), "drafts still have none");
        }

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

            Assert.AreEqual(5, offered.Count, "the four Traditional/Structure games in the capture, and the one Open Team Play/Structure game");
            Assert.IsTrue(offered.All(g => g.DeckType == "structure" && (g.Format == "standard" || g.Format == "team")));
            Assert.IsNull(GameSetup.ForRematch(games.First(g => g.Format == "draft"), 2));
            Assert.IsNull(GameSetup.ForRematch(games.First(g => g.Format == "team" && g.DeckType != "structure"), 2), "a drafted team game");

            // The real team game: you are seat 2 of 4, so your partner is seat 3.
            var team = GameSetup.ForRematch(games.First(g => g.Format == "team" && g.DeckType == "structure"), 2);
            Assert.AreEqual("team", team.Format);
            Assert.AreEqual(4, team.PartnerUserId, "Alice sits in seat 3, next to bshaftoe in seat 2");
        }
    }
}
