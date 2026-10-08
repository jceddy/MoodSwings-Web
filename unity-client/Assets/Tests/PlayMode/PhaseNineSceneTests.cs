using System.Collections;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.UI;
using Newtonsoft.Json;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>Phase 9: stats, achievements, and the Home menu that grew to reach them.</summary>
    public class PhaseNineSceneTests
    {
        private const string Stats =
            "{\"status\":\"ok\",\"username\":\"bshaftoe\",\"stats\":{\"game_wins\":12,\"game_losses\":8,\"game_win_percentage\":60," +
            "\"match_wins\":0,\"match_losses\":0,\"match_win_percentage\":null}," +
            "\"prior_weekly_sealed_pool_events\":[{\"period_start\":\"2026-09-28\",\"wins\":3,\"losses\":1,\"percentile\":15}]}";

        private static string Row(string slug, string title, string tier, int? target, int progress, string unlockedAt, bool hidden = false) =>
            "{\"slug\":\"" + slug + "\",\"title\":\"" + title + "\",\"description\":\"Description of " + title + ".\",\"tier\":\"" + tier + "\"," +
            "\"target\":" + (target.HasValue ? target.Value.ToString() : "null") + ",\"progress\":" + progress + "," +
            "\"unlocked_at\":" + (unlockedAt != null ? "\"" + unlockedAt + "\"" : "null") + ",\"hidden\":" + (hidden ? "true" : "false") + "}";

        private static string Catalog(params string[] extraA) =>
            "{\"status\":\"ok\",\"achievements\":{\"A\":[" +
            Row("first-win", "First Win", "Bronze", null, 1, "2026-09-01 10:00:00") + "," +
            Row("ten-wins", "Ten Wins", "Silver", 10, 4, null) +
            string.Concat(extraA.Select(r => "," + r)) + "]," +
            "\"J\":[" + Row("puzzler", "Puzzler", "Gold", null, 0, "2026-09-05 08:30:00") + "]," +
            "\"H\":[" + Row("secret", "???", "Diamond", null, 0, null, hidden: true) + "]}}";

        private static PhaseFiveSceneTests.PlayServer Serve(string achievements = null) => new PhaseFiveSceneTests.PlayServer
        {
            State = PhaseFiveSceneTests.Load(405),
            StatsJson = Stats,
            AchievementsJson = achievements ?? Catalog(),
        };

        private static string[] Texts() =>
            Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToArray();

        private static IEnumerator Home(PhaseFiveSceneTests.PlayServer server)
        {
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
        }

        private static IEnumerator Open<T>(PhaseFiveSceneTests.PlayServer server, string button) where T : UiScreen
        {
            yield return Home(server);
            yield return PhaseTwoSceneTests.Click(button);
            yield return MainSceneTests.WaitFor<T>();
            yield return PhaseTwoSceneTests.Frames(8);
        }

        // --- Home ----------------------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator Home_FitsEveryButtonOnScreen_TwoToARow()
        {
            yield return Home(Serve());

            var names = new[] { "Play  (3 waiting on you)", "Puzzles", "Decklists", "Friends", "Achievements", "Stats", "Settings", "Log out" };
            var canvas = Object.FindAnyObjectByType<Canvas>().rootCanvas.GetComponent<RectTransform>();
            var bounds = new Vector3[4];
            canvas.GetWorldCorners(bounds);
            foreach (var name in names)
            {
                var button = PhaseTwoSceneTests.FindButton(name);
                Assert.IsNotNull(button, name);
                var corners = new Vector3[4];
                ((RectTransform)button.transform).GetWorldCorners(corners);
                Assert.GreaterOrEqual(corners[0].y, bounds[0].y - 0.5f, name + " is above the bottom edge");
                Assert.LessOrEqual(corners[1].y, bounds[1].y + 0.5f, name + " is below the top edge");
            }

            var puzzles = (RectTransform)PhaseTwoSceneTests.FindButton("Puzzles").transform;
            var decklists = (RectTransform)PhaseTwoSceneTests.FindButton("Decklists").transform;
            Assert.AreEqual(puzzles.position.y, decklists.position.y, 0.5f, "paired in a row");
            ScreenshotHelper.Capture("home-grid");
        }

        [UnityTest]
        public IEnumerator Home_FlagsNewUnlocks_UntilTheyAreLookedAt()
        {
            var server = Serve();
            yield return Home(server);
            Assert.AreEqual("Achievements", MainSceneTests.Screen<HomeScreen>().AchievementsButtonText, "what was already unlocked is old news");

            server.AchievementsJson = Catalog(Row("fifty-wins", "Fifty Wins", "Gold", null, 50, "2026-10-01 12:00:00"));
            AppServices.Stats.RefreshAchievementsAsync();
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.AreEqual("Achievements  (1 new)", MainSceneTests.Screen<HomeScreen>().AchievementsButtonText);

            yield return PhaseTwoSceneTests.Click("Achievements  (1 new)");
            yield return MainSceneTests.WaitFor<AchievementsScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsTrue(Texts().Contains("Fifty Wins"));

            MainSceneTests.Screen<AchievementsScreen>().Router.Back();
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.AreEqual("Achievements", MainSceneTests.Screen<HomeScreen>().AchievementsButtonText);
        }

        // --- Achievements --------------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator Achievements_AreListedByCategory_WithTiersProgressAndTheTally()
        {
            yield return Open<AchievementsScreen>(Serve(), "Achievements");

            var texts = Texts();
            Assert.IsTrue(texts.Contains("2 of 4 unlocked"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("Volume & Milestones   1 / 2"));
            Assert.IsTrue(texts.Contains("Fun, Flavor & Meta   0 / 1"));
            Assert.IsTrue(texts.Contains("Puzzles   1 / 1"));
            Assert.IsTrue(texts.Contains("First Win") && texts.Contains("Ten Wins") && texts.Contains("???"));
            Assert.IsTrue(texts.Contains("A hidden achievement") || texts.Contains("Description of ???."));
            Assert.IsTrue(texts.Contains("4 / 10"), "progress toward the one being worked on");
            Assert.IsTrue(texts.Contains("BRONZE") && texts.Contains("SILVER") && texts.Contains("GOLD") && texts.Contains("DIAMOND"));
            Assert.AreEqual(1, Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Count(t => t.text == "4 / 10"));

            var marks = Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Count(t => t.name == "Unlocked mark" && t.text == "✓");
            Assert.AreEqual(2, marks, "a check for each unlocked one");
            ScreenshotHelper.Capture("achievements");
        }

        [UnityTest]
        public IEnumerator Achievements_CategoriesAppearInTheWebPagesOrder()
        {
            yield return Open<AchievementsScreen>(Serve(), "Achievements");

            var order = MainSceneTests.Screen<AchievementsScreen>().GetComponentsInChildren<Text>().Select(t => t.text)
                .Where(t => t.Contains("   ") && t.Contains(" / ")).Select(t => t.Split(' ')[0]).ToList();
            CollectionAssert.AreEqual(new[] { "Volume", "Fun,", "Puzzles" }, order);
        }

        [UnityTest]
        public IEnumerator Achievements_HideLockedKeepsOnlyTheUnlocked_AndIsRemembered()
        {
            yield return Open<AchievementsScreen>(Serve(), "Achievements");
            var toggle = PhaseFiveSceneTests.Child("Hide locked").GetComponent<Toggle>();
            Assert.IsFalse(toggle.isOn);

            toggle.isOn = true;
            yield return PhaseTwoSceneTests.Frames(4);

            var texts = Texts();
            Assert.IsTrue(texts.Contains("First Win"));
            Assert.IsFalse(texts.Contains("Ten Wins"));
            Assert.IsFalse(texts.Contains("Fun, Flavor & Meta   0 / 1"), "a category with nothing unlocked disappears");
            Assert.IsTrue(texts.Contains("2 of 4 unlocked"), "the tally still counts them all");
            Assert.IsTrue(AppServices.Device.HideLockedAchievements);
        }

        [UnityTest]
        public IEnumerator Achievements_WhenTheyCantBeLoaded_SaysSo()
        {
            var server = Serve();
            yield return Home(server);
            server.AchievementsJson = "{\"status\":\"error\",\"message\":\"Not available\"}";
            AppServices.Stats.Clear();
            yield return PhaseTwoSceneTests.Click("Achievements");
            yield return MainSceneTests.WaitFor<AchievementsScreen>();
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsTrue(Texts().Contains("Loading your achievements..."), string.Join(" | ", Texts()));
            Assert.IsFalse(string.IsNullOrEmpty(MainSceneTests.Screen<AchievementsScreen>().StatusText));
        }

        // --- Stats ---------------------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator Stats_ShowLifetimeRecords_AndPastWeeklySealedWeeks()
        {
            yield return Open<StatsScreen>(Serve(), "Stats");

            var texts = Texts();
            Assert.IsTrue(texts.Contains("12-8 (60%)"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("0-0"), "no percentage until a match is played");
            Assert.IsTrue(texts.Contains("3-1   Top 15%"));
            Assert.IsTrue(texts.Contains("2026-09-28"));
            ScreenshotHelper.Capture("stats");
        }

        [UnityTest]
        public IEnumerator Stats_WithNoWeeklySealedHistory_SaysSo()
        {
            var server = Serve();
            server.StatsJson = server.StatsJson.Replace("{\"period_start\":\"2026-09-28\",\"wins\":3,\"losses\":1,\"percentile\":15}", string.Empty);
            yield return Open<StatsScreen>(server, "Stats");

            Assert.IsTrue(Texts().Contains("You haven't finished a week yet."), string.Join(" | ", Texts()));
        }

        // --- signing out ---------------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator LoggingOut_ForgetsTheStatsAndAchievements()
        {
            yield return Home(Serve());
            Assert.IsTrue(AppServices.Stats.AchievementsLoaded);

            yield return PhaseTwoSceneTests.Click("Log out");
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsFalse(AppServices.Stats.AchievementsLoaded);
            Assert.IsNull(AppServices.Stats.Stats);
        }

        // --- card stats ----------------------------------------------------------------------------------

        private static string Fixture(string name) =>
            System.IO.File.ReadAllText(System.IO.Path.Combine(Application.dataPath, "Tests", "Fixtures", name + ".json"));

        /// <summary>A stats row for every card in the captured catalog: more in decks the later it comes, one drafted card.</summary>
        private static string CardStatsFor(out System.Collections.Generic.List<MoodSwings.Networking.BoardCard> catalog)
        {
            catalog = Newtonsoft.Json.JsonConvert.DeserializeObject<MoodSwings.Networking.CatalogResponse>(Fixture("cards_catalog")).Cards;
            var rows = new Newtonsoft.Json.Linq.JArray();
            for (var i = 0; i < catalog.Count; i++)
            {
                var card = catalog[i];
                var drafted = i == 0;
                rows.Add(new Newtonsoft.Json.Linq.JObject
                {
                    ["catalog_card_id"] = card.CardId,
                    ["name"] = card.Name,
                    ["set_code"] = i % 2 == 0 ? "BASE" : "PROMO",
                    ["collector_number"] = i + 1,
                    ["rarity"] = card.Rarity,
                    ["color"] = card.Color,
                    ["times_in_deck"] = i,
                    ["deck_win_rate"] = i == 0 ? null : (Newtonsoft.Json.Linq.JToken)0.5,
                    ["times_played"] = i * 2,
                    ["play_win_rate"] = i == 0 ? null : (Newtonsoft.Json.Linq.JToken)0.25,
                    ["quick_draft"] = new Newtonsoft.Json.Linq.JObject { ["average"] = drafted ? 3.5 : null, ["count"] = drafted ? 4 : 0 },
                    ["winston_draft"] = new Newtonsoft.Json.Linq.JObject { ["average"] = null, ["count"] = 0 },
                    ["grid_draft"] = new Newtonsoft.Json.Linq.JObject { ["average"] = null, ["count"] = 0 },
                    ["rotisserie_draft"] = new Newtonsoft.Json.Linq.JObject { ["average"] = null, ["count"] = 0 },
                });
            }

            return new Newtonsoft.Json.Linq.JObject { ["status"] = "ok", ["cards"] = rows }.ToString();
        }

        private static IEnumerator OpenCardStats(PhaseFiveSceneTests.PlayServer server)
        {
            yield return Open<StatsScreen>(server, "Stats");
            yield return PhaseTwoSceneTests.Click("Card stats");
            yield return MainSceneTests.WaitFor<CardStatsScreen>();
            yield return PhaseTwoSceneTests.Frames(10);
        }

        private static System.Collections.Generic.List<string> CardRows() =>
            MainSceneTests.Screen<CardStatsScreen>().GetComponentsInChildren<RectTransform>()
                .Where(t => t.name.StartsWith("Card ") && t.GetComponent<Button>() != null).Select(t => t.name.Substring(5)).ToList();

        [UnityTest]
        public IEnumerator CardStats_ListACardsFiguresAPageAtATime()
        {
            var server = Serve();
            server.CardStatsJson = CardStatsFor(out var catalog);
            yield return OpenCardStats(server);

            var expectedPages = (catalog.Count + MoodSwings.Core.CardStatsQuery.PageSize - 1) / MoodSwings.Core.CardStatsQuery.PageSize;
            Assert.AreEqual($"Page 1 of {expectedPages} ({catalog.Count} cards)", MainSceneTests.Screen<CardStatsScreen>().PageText);
            Assert.AreEqual(MoodSwings.Core.CardStatsQuery.PageSize, CardRows().Count);
            var first = CardRows()[0];
            Assert.AreEqual(catalog.Select(c => c.Name).OrderBy(n => n, System.StringComparer.OrdinalIgnoreCase).First(), first, "by name to begin with");
            var texts = Texts();
            Assert.IsTrue(texts.Any(t => t.StartsWith("In ") && t.Contains(" decks  ·  won ")), string.Join(" | ", texts.Take(20)));
            Assert.IsTrue(texts.Any(t => t.StartsWith("Played ") && t.Contains("×  ·  won 25%")));
            Assert.IsFalse(PhaseTwoSceneTests.FindButton("< Previous").interactable);
            Assert.IsTrue(PhaseTwoSceneTests.FindButton("Next >").interactable);
            ScreenshotHelper.Capture("card-stats");

            yield return PhaseTwoSceneTests.Click("Next >");
            Assert.AreEqual($"Page 2 of {expectedPages} ({catalog.Count} cards)", MainSceneTests.Screen<CardStatsScreen>().PageText);
            Assert.AreNotEqual(first, CardRows()[0]);
            Assert.IsTrue(PhaseTwoSceneTests.FindButton("< Previous").interactable);
        }

        [UnityTest]
        public IEnumerator CardStats_OrderByAnyColumn_AndFlipTheDirection()
        {
            var server = Serve();
            server.CardStatsJson = CardStatsFor(out var catalog);
            yield return OpenCardStats(server);

            // "Order by" steps through the columns: name, set, rarity, color, in decks.
            for (var i = 0; i < 4; i++)
            {
                yield return PhaseTwoSceneTests.Click(MainSceneTests.Screen<CardStatsScreen>().Query.Sort.Label);
            }

            Assert.AreEqual("In decks", MainSceneTests.Screen<CardStatsScreen>().Query.Sort.Label);
            Assert.AreEqual(catalog[0].Name, CardRows()[0], "the least-played card first");

            yield return PhaseTwoSceneTests.Click("▲ Low to high");
            Assert.AreEqual(catalog[catalog.Count - 1].Name, CardRows()[0], "and flipped, the most");
            Assert.IsNotNull(PhaseTwoSceneTests.FindButton("▼ High to low"));
        }

        [UnityTest]
        public IEnumerator CardStats_SearchAndSetFilter_NarrowTheList()
        {
            var server = Serve();
            server.CardStatsJson = CardStatsFor(out var catalog);
            yield return OpenCardStats(server);

            yield return PhaseTwoSceneTests.Click("All sets");
            Assert.AreEqual("BASE", MainSceneTests.Screen<CardStatsScreen>().Query.SetCode);
            var inBase = (catalog.Count + 1) / 2;
            var basePages = (inBase + MoodSwings.Core.CardStatsQuery.PageSize - 1) / MoodSwings.Core.CardStatsQuery.PageSize;
            Assert.AreEqual($"Page 1 of {basePages} ({inBase} cards)", MainSceneTests.Screen<CardStatsScreen>().PageText);

            var field = PhaseFiveSceneTests.Child("Search").GetComponent<InputField>();
            field.text = catalog[0].Name;
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.IsTrue(CardRows().Contains(catalog[0].Name));
            Assert.IsTrue(CardRows().Count <= 3, "only the cards with that in their name");

            field.text = "zzzz no such card";
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.IsTrue(Texts().Contains("No cards match."));
            Assert.AreEqual("Page 1 of 1 (0 cards)", MainSceneTests.Screen<CardStatsScreen>().PageText);
        }

        [UnityTest]
        public IEnumerator CardStats_ATappedCard_ShowsItsText()
        {
            var server = Serve();
            server.CardStatsJson = CardStatsFor(out var catalog);
            yield return OpenCardStats(server);

            var name = CardRows()[0];
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + name).GetComponent<Button>());
            yield return PhaseTwoSceneTests.Frames(4);

            var card = catalog.First(c => c.Name == name);
            Assert.IsTrue(Texts().Contains(card.Name));
            Assert.IsTrue(MainSceneTests.Screen<CardStatsScreen>().HandleBack(), "Back closes the card first");
            Assert.IsFalse(MainSceneTests.Screen<CardStatsScreen>().HandleBack());
        }

        [UnityTest]
        public IEnumerator CardStats_WhenTheyCantBeLoaded_SaySo()
        {
            var server = Serve();
            server.CardStatsJson = "{\"status\":\"error\",\"message\":\"Not available\"}";
            yield return OpenCardStats(server);

            Assert.IsTrue(Texts().Contains("Loading the card stats..."));
            Assert.IsFalse(string.IsNullOrEmpty(MainSceneTests.Screen<CardStatsScreen>().StatusText));
        }

        // --- tournaments ---------------------------------------------------------------------------------

        private static string Tour(int id, string name, string deckType, string status = "registration", string mySeat = "joined", int creator = 2,
            string bracket = "single_elimination", string format = "duel", string creatorName = null, int joined = 3) =>
            "{\"id\":" + id + ",\"name\":\"" + name + "\",\"bracket_type\":\"" + bracket + "\",\"registration_mode\":\"open\",\"created_by_user_id\":" + creator + "," +
            "\"match_params\":{\"format\":\"" + format + "\",\"deck_type\":\"" + deckType + "\"},\"min_participants\":4,\"max_participants\":8,\"status\":\"" + status + "\"," +
            "\"winner_user_id\":null,\"my_participant_status\":" + (mySeat == null ? "null" : "\"" + mySeat + "\"") + ",\"winner_username\":null,\"joined_count\":" + joined +
            (creatorName != null ? ",\"creator_username\":\"" + creatorName + "\"" : string.Empty) + "}";

        private static string TourList(params string[] tournaments) => "{\"status\":\"ok\",\"tournaments\":[" + string.Join(",", tournaments) + "]}";

        private static string OwnDecks(params string[] names) =>
            "{\"status\":\"ok\",\"friends\":[],\"own\":[" +
            string.Join(",", names.Select((n, i) =>
                "{\"id\":" + (20 + i) + ",\"name\":\"" + n + "\",\"visibility\":\"private\",\"card_count\":15,\"sideboard_card_count\":0,\"updated_at\":\"2026-09-01 10:00:00\"}")) + "]}";

        private static IEnumerator OpenTournaments(PhaseFiveSceneTests.PlayServer server)
        {
            yield return Open<TournamentsScreen>(server, "Tournaments");
        }

        private static Button Named(string name) => PhaseFiveSceneTests.ButtonNamed(name);

        private static TournamentsScreen TournamentsList() => MainSceneTests.Screen<TournamentsScreen>();

        [UnityTest]
        public IEnumerator Tournaments_ListInvitationsYoursAndTheOpenOnes()
        {
            var server = Serve();
            server.TournamentsMineJson = TourList(
                Tour(2, "Invited", "custom_duel", mySeat: "invited", creator: 3),
                Tour(1, "Mine", "structure", format: "standard", bracket: "swiss"),
                Tour(4, "Dead", "booster_draft", status: "cancelled"));
            server.TournamentsOpenJson = TourList(Tour(9, "Open", "custom_duel", mySeat: null, creator: 3, creatorName: "Alder", joined: 2));
            yield return OpenTournaments(server);

            var texts = Texts();
            Assert.IsTrue(texts.Contains("Invitations") && texts.Contains("Your tournaments") && texts.Contains("Open to join") && texts.Contains("Cancelled"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("Mine  -  Registration open (3 of 8 joined)"), "its maker sees the tally");
            Assert.IsTrue(texts.Contains("Swiss rounds  -  Traditional  -  Structure"));
            Assert.IsTrue(texts.Contains("Alder: Open"));
            Assert.IsTrue(texts.Contains("Single elimination  -  Power Duel  -  2 of 8 joined"));
            Assert.IsNotNull(Named("Accept Invited"));
            Assert.IsNotNull(Named("Decline Invited"));
            Assert.IsNotNull(Named("View Mine"));
            Assert.IsNull(Named("Withdraw Mine"), "its maker can't withdraw");
            Assert.IsNotNull(Named("Join Open"));
            Assert.IsNotNull(Named("View Dead"));
            ScreenshotHelper.Capture("tournaments");
        }

        [UnityTest]
        public IEnumerator Tournaments_JoiningAPowerDuel_AsksForADeckFirst()
        {
            var server = Serve();
            server.DecklistsJson = OwnDecks("Mine", "Other");
            server.TournamentsOpenJson = TourList(Tour(9, "Open", "custom_duel", mySeat: null, creator: 3, creatorName: "Alder"));
            yield return OpenTournaments(server);

            yield return PhaseFiveSceneTests.Tap(Named("Join Open"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsTrue(TournamentsList().DeckChoice.IsOpen);
            CollectionAssert.AreEqual(new[] { "Mine", "Other" }, TournamentsList().DeckChoice.DeckNames.ToArray());
            Assert.AreEqual(0, server.Posts("/tournaments/join").Count(), "nothing is sent before a deck is chosen");
            ScreenshotHelper.Capture("tournament-deck-choice");

            server.TournamentsOpenJson = TourList();
            Assert.IsTrue(TournamentsList().DeckChoice.Choose("Other"));
            yield return PhaseTwoSceneTests.Frames(10);

            var post = server.Posts("/tournaments/join").Single();
            Assert.AreEqual(9, (int)post["tournament_id"]);
            Assert.AreEqual(21, (int)post["saved_decklist_id"]);
            Assert.IsFalse(TournamentsList().DeckChoice.IsOpen);
            Assert.IsNull(Named("Join Open"), "the list is fetched again, and it's gone from the open ones");
        }

        [UnityTest]
        public IEnumerator Tournaments_CancellingTheDeckChoice_JoinsNothing()
        {
            var server = Serve();
            server.DecklistsJson = OwnDecks("Mine");
            server.TournamentsOpenJson = TourList(Tour(9, "Open", "custom_duel", mySeat: null, creator: 3, creatorName: "Alder"));
            yield return OpenTournaments(server);
            yield return PhaseFiveSceneTests.Tap(Named("Join Open"));
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.IsTrue(TournamentsList().HandleBack(), "Back closes the choice");
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.AreEqual(0, server.Posts("/tournaments/join").Count());
            Assert.IsFalse(TournamentsList().DeckChoice.IsOpen);
        }

        [UnityTest]
        public IEnumerator Tournaments_JoiningAnythingElse_JustJoins()
        {
            var server = Serve();
            server.TournamentsOpenJson = TourList(Tour(9, "Open", "structure", format: "standard", mySeat: null, creator: 3, creatorName: "Alder"));
            yield return OpenTournaments(server);

            yield return PhaseFiveSceneTests.Tap(Named("Join Open"));
            yield return PhaseTwoSceneTests.Frames(8);

            var post = server.Posts("/tournaments/join").Single();
            Assert.AreEqual(9, (int)post["tournament_id"]);
            Assert.IsNull(post["saved_decklist_id"]);
            Assert.IsFalse(TournamentsList().DeckChoice.IsOpen);
        }

        [UnityTest]
        public IEnumerator Tournaments_AcceptingAnInvitationToAPowerDuel_TakesYourDeck()
        {
            var server = Serve();
            server.DecklistsJson = OwnDecks("Mine");
            server.TournamentsMineJson = TourList(Tour(2, "Invited", "custom_duel", mySeat: "invited", creator: 3));
            yield return OpenTournaments(server);

            yield return PhaseFiveSceneTests.Tap(Named("Accept Invited"));
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsTrue(TournamentsList().DeckChoice.Choose("Mine"));
            yield return PhaseTwoSceneTests.Frames(8);

            var post = server.Posts("/tournaments/accept-invite").Single();
            Assert.AreEqual(2, (int)post["tournament_id"]);
            Assert.AreEqual(20, (int)post["saved_decklist_id"]);
        }

        [UnityTest]
        public IEnumerator Tournaments_DeclineWithdrawAndChangeDeck_EachPostTheirOwnRoute()
        {
            var server = Serve();
            server.DecklistsJson = OwnDecks("Mine", "Other");
            server.TournamentsMineJson = TourList(
                Tour(2, "Invited", "custom_duel", mySeat: "invited", creator: 3),
                Tour(3, "Joined", "custom_duel", creator: 3));
            yield return OpenTournaments(server);

            Assert.IsNotNull(Named("Edit deck Joined"));
            yield return PhaseFiveSceneTests.Tap(Named("Decline Invited"));
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.AreEqual(2, (int)server.Posts("/tournaments/decline-invite").Single()["tournament_id"]);

            yield return PhaseFiveSceneTests.Tap(Named("Withdraw Joined"));
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.AreEqual(3, (int)server.Posts("/tournaments/withdraw").Single()["tournament_id"]);

            yield return PhaseFiveSceneTests.Tap(Named("Edit deck Joined"));
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsTrue(TournamentsList().DeckChoice.Choose("Other"));
            yield return PhaseTwoSceneTests.Frames(6);
            var change = server.Posts("/tournaments/submit-deck").Single();
            Assert.AreEqual(3, (int)change["tournament_id"]);
            Assert.AreEqual(21, (int)change["saved_decklist_id"]);
        }

        [UnityTest]
        public IEnumerator Tournaments_ARefusal_ShowsTheServersMessage()
        {
            var server = Serve();
            server.TournamentsOpenJson = TourList(Tour(9, "Open", "structure", format: "standard", mySeat: null, creator: 3, creatorName: "Alder"));
            server.OnTournamentPost = (path, body) => MainSceneTests.Reply(400, "{\"status\":\"error\",\"message\":\"The tournament is full.\"}");
            yield return OpenTournaments(server);

            yield return PhaseFiveSceneTests.Tap(Named("Join Open"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual("The tournament is full.", TournamentsList().StatusText);
        }

        // The tournament view --------------------------------------------------------------------------------

        private const string BracketState =
            "{\"status\":\"ok\",\"tournament\":{\"id\":7,\"name\":\"Spring Open\",\"bracket_type\":\"single_elimination\",\"registration_mode\":\"open\",\"created_by_user_id\":2," +
            "\"match_params\":{\"format\":\"duel\",\"deck_type\":\"custom_duel\"},\"min_participants\":4,\"max_participants\":8,\"status\":\"in_progress\",\"winner_user_id\":null}," +
            "\"participants\":[" +
            "{\"id\":11,\"user_id\":2,\"username\":\"bshaftoe\",\"status\":\"joined\",\"seed\":1,\"deck_name\":\"Mine\"}," +
            "{\"id\":12,\"user_id\":3,\"username\":\"Alder\",\"status\":\"joined\",\"seed\":2,\"deck_name\":null}," +
            "{\"id\":13,\"user_id\":4,\"username\":\"Birch\",\"status\":\"joined\",\"seed\":3,\"deck_name\":null}," +
            "{\"id\":14,\"user_id\":5,\"username\":\"Cedar\",\"status\":\"joined\",\"seed\":4,\"deck_name\":null}]," +
            "\"rounds\":[{\"id\":70,\"bracket\":\"single\",\"round_number\":1,\"status\":\"in_progress\"},{\"id\":71,\"bracket\":\"single\",\"round_number\":2,\"status\":\"pending\"}]," +
            "\"matches_by_round\":{\"70\":[" +
            "{\"id\":700,\"slot\":1,\"participant1_id\":11,\"participant2_id\":12,\"winner_participant_id\":null,\"game_id\":900,\"status\":\"in_progress\"}," +
            "{\"id\":701,\"slot\":2,\"participant1_id\":13,\"participant2_id\":14,\"winner_participant_id\":13,\"game_id\":901,\"status\":\"completed\"}]," +
            "\"71\":[{\"id\":702,\"slot\":1,\"participant1_id\":null,\"participant2_id\":13,\"winner_participant_id\":null,\"game_id\":null,\"status\":\"pending\"}]}," +
            "\"standings\":null,\"pods\":null,\"viewer_has_cast_access\":false,\"viewer_cast_reveals_hands\":null,\"cast_grants\":[]}";

        private static IEnumerator OpenTournament(PhaseFiveSceneTests.PlayServer server, string name, int id)
        {
            server.TournamentsMineJson = TourList(Tour(id, name, "custom_duel", status: "in_progress"));
            yield return OpenTournaments(server);
            yield return PhaseFiveSceneTests.Tap(Named("View " + name));
            yield return MainSceneTests.WaitFor<TournamentScreen>();
            yield return PhaseTwoSceneTests.Frames(10);
        }

        private static TournamentScreen TournamentView() => MainSceneTests.Screen<TournamentScreen>();

        [UnityTest]
        public IEnumerator TheTournamentView_ShowsTheBracket_AndTheWayToYourGame()
        {
            var server = Serve();
            server.TournamentStateJson = BracketState;
            yield return OpenTournament(server, "Spring Open", 7);

            var texts = Texts();
            Assert.IsTrue(texts.Contains("Spring Open"));
            Assert.IsTrue(texts.Contains("Single elimination  -  In progress"));
            Assert.IsTrue(texts.Contains("Round 1") && texts.Contains("Round 2"));
            Assert.IsTrue(texts.Contains("bshaftoe vs Alder  -  in progress"));
            Assert.IsTrue(texts.Contains("Birch vs Cedar  -  Birch won"));
            Assert.IsTrue(texts.Contains("BYE vs Birch  -  waiting"));
            Assert.IsNotNull(Named("Go to your game"));
            Assert.IsNotNull(Named("Go to game 700"));
            Assert.IsNotNull(Named("View game 701"));
            Assert.IsNull(Named("Start tournament"), "it's already under way");
            Assert.IsNotNull(Named("Cancel tournament"), "its maker may still cancel it");
            ScreenshotHelper.Capture("tournament-view");
        }

        [UnityTest]
        public IEnumerator TheTournamentView_YourGame_OpensOnTheBoard()
        {
            var server = Serve();
            server.TournamentStateJson = BracketState;
            server.StateFor = id => PhaseFiveSceneTests.Load(405);
            yield return OpenTournament(server, "Spring Open", 7);

            yield return PhaseFiveSceneTests.Tap(Named("Go to your game"));
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=900")), "the match's game is the one opened");
        }

        [UnityTest]
        public IEnumerator TheTournamentView_ItsMakerStartsIt_OnceEnoughHaveJoined()
        {
            var server = Serve();
            server.TournamentStateJson = BracketState.Replace("\"status\":\"in_progress\",\"winner_user_id\":null", "\"status\":\"registration\",\"winner_user_id\":null")
                .Replace("\"rounds\":[{\"id\":70,\"bracket\":\"single\",\"round_number\":1,\"status\":\"in_progress\"},{\"id\":71,\"bracket\":\"single\",\"round_number\":2,\"status\":\"pending\"}]", "\"rounds\":[]")
                .Replace("\"matches_by_round\":{", "\"matches_by_round\":[],\"unused\":{");
            yield return OpenTournament(server, "Spring Open", 7);

            Assert.IsTrue(Texts().Contains("Players (4 of 8 joined, 4 needed)"), string.Join(" | ", Texts()));
            Assert.IsTrue(Texts().Contains("bshaftoe   -   Mine"), "the deck each has entered");
            yield return PhaseFiveSceneTests.Tap(Named("Start tournament"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual(7, (int)server.Posts("/tournaments/start").Single()["tournament_id"]);
        }

        [UnityTest]
        public IEnumerator TheTournamentView_CancellingAsksFirst()
        {
            var server = Serve();
            server.TournamentStateJson = BracketState;
            yield return OpenTournament(server, "Spring Open", 7);

            yield return PhaseFiveSceneTests.Tap(Named("Cancel tournament"));
            yield return PhaseTwoSceneTests.Frames(4);
            Assert.AreEqual(0, server.Posts("/tournaments/cancel").Count(), "asked first");
            Assert.IsTrue(TournamentView().HandleBack(), "Back says no");
            yield return PhaseTwoSceneTests.Frames(4);
            Assert.AreEqual(0, server.Posts("/tournaments/cancel").Count());

            yield return PhaseFiveSceneTests.Tap(Named("Cancel tournament"));
            yield return PhaseTwoSceneTests.Frames(4);
            var confirm = Object.FindObjectsByType<Button>(FindObjectsInactive.Exclude).First(b => b.GetComponentInChildren<Text>().text == "Cancel tournament" && b.name == "Button");
            confirm.onClick.Invoke();
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual(7, (int)server.Posts("/tournaments/cancel").Single()["tournament_id"]);
        }

        [UnityTest]
        public IEnumerator TheTournamentView_SomeoneElsesTournament_OffersNoStartOrCancel()
        {
            var server = Serve();
            server.TournamentStateJson = BracketState.Replace("\"created_by_user_id\":2", "\"created_by_user_id\":3");
            yield return OpenTournament(server, "Spring Open", 7);

            Assert.IsNull(Named("Start tournament"));
            Assert.IsNull(Named("Cancel tournament"));
            Assert.IsNotNull(Named("Go to your game"));
        }

        [UnityTest]
        public IEnumerator TheTournamentView_YourDraftedPool_CanBeLookedThrough()
        {
            var server = Serve();
            var catalog = JsonConvert.DeserializeObject<MoodSwings.Networking.CatalogResponse>(
                System.IO.File.ReadAllText(System.IO.Path.Combine(Application.dataPath, "Tests", "Fixtures", "cards_catalog.json"))).Cards;
            var state = Newtonsoft.Json.Linq.JObject.Parse(BracketState);
            state["participants"][0]["draft_pool_card_ids"] = Newtonsoft.Json.Linq.JArray.FromObject(catalog.Take(6));
            state["participants"][0]["current_deck_card_ids"] = Newtonsoft.Json.Linq.JArray.FromObject(catalog.Take(3));
            server.TournamentStateJson = state.ToString();
            yield return OpenTournament(server, "Spring Open", 7);

            yield return PhaseTwoSceneTests.Click("Your pool (6 cards)");
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.IsTrue(Texts().Contains("Your drafted pool"));
            Assert.IsTrue(TournamentView().HandleBack(), "Back closes it");

            yield return PhaseTwoSceneTests.Click("Your last deck (3 cards)");
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.IsTrue(Texts().Contains("The deck you last played"));
        }

        [UnityTest]
        public IEnumerator ABoosterDraftTournament_ShowsItsPods_AndContinuesToThePodDraft()
        {
            var server = Serve();
            var state = Newtonsoft.Json.Linq.JObject.Parse(BracketState);
            state["tournament"]["status"] = "drafting";
            state["tournament"]["match_params"]["deck_type"] = "booster_draft";
            state["rounds"] = new Newtonsoft.Json.Linq.JArray();
            state["matches_by_round"] = new Newtonsoft.Json.Linq.JArray();
            state["pods"] = Newtonsoft.Json.Linq.JArray.Parse(
                "[{\"pod_number\":1,\"kind\":\"regular\",\"status\":\"drafting\",\"current_round\":4,\"game_id\":null,\"seats\":[" +
                "{\"participant_id\":11,\"username\":\"bshaftoe\",\"seat_order\":0},{\"participant_id\":12,\"username\":\"Alder\",\"seat_order\":1}]," +
                "\"winner_username\":null,\"bracket_rounds\":[]}]");
            server.TournamentStateJson = state.ToString();
            server.PodDraftJson = PodState(round: 4, left: 4, right: 4);
            yield return OpenTournament(server, "Spring Open", 7);

            Assert.IsTrue(Texts().Contains("Pod 1 (round 4/15): bshaftoe, Alder"), string.Join(" | ", Texts()));
            Assert.IsNull(Named("Go to your game"));
            yield return PhaseTwoSceneTests.Click("Continue drafting");
            yield return MainSceneTests.WaitFor<PodDraftScreen>();
            yield return PhaseTwoSceneTests.Frames(10);

            Assert.AreEqual("Round 4 of 15  -  pod of 8", MainSceneTests.Screen<PodDraftScreen>().InfoText);
        }

        [UnityTest]
        public IEnumerator AGridDraftPod_ContinuesOnTheOrdinaryBoard()
        {
            var server = Serve();
            var state = Newtonsoft.Json.Linq.JObject.Parse(BracketState);
            state["tournament"]["status"] = "drafting";
            state["tournament"]["match_params"]["deck_type"] = "grid_draft_pod";
            state["rounds"] = new Newtonsoft.Json.Linq.JArray();
            state["matches_by_round"] = new Newtonsoft.Json.Linq.JArray();
            state["pods"] = Newtonsoft.Json.Linq.JArray.Parse(
                "[{\"pod_number\":1,\"kind\":\"regular\",\"status\":\"drafting\",\"current_round\":1,\"game_id\":55,\"seats\":[" +
                "{\"participant_id\":11,\"username\":\"bshaftoe\",\"seat_order\":0},{\"participant_id\":12,\"username\":\"Alder\",\"seat_order\":1}]," +
                "\"winner_username\":null,\"bracket_rounds\":[]}]");
            server.TournamentStateJson = state.ToString();
            yield return OpenTournament(server, "Spring Open", 7);

            Assert.IsTrue(Texts().Contains("Pod 1 (drafting): bshaftoe, Alder"), string.Join(" | ", Texts()));
            yield return PhaseTwoSceneTests.Click("Continue drafting");
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=55")));
        }

        // The pod draft ------------------------------------------------------------------------------------

        private static System.Collections.Generic.List<MoodSwings.Networking.BoardCard> CardCatalog() =>
            JsonConvert.DeserializeObject<MoodSwings.Networking.CatalogResponse>(
                System.IO.File.ReadAllText(System.IO.Path.Combine(Application.dataPath, "Tests", "Fixtures", "cards_catalog.json"))).Cards;

        private static string PodState(int round, int? left, int? right, int drafted = 4)
        {
            var catalog = CardCatalog();
            return "{\"status\":\"ok\",\"pod_status\":\"drafting\",\"current_round\":" + round + ",\"total_rounds\":15,\"pod_size\":8," +
                "\"drafted_cards\":" + JsonConvert.SerializeObject(catalog.Take(drafted)) + "," +
                "\"left\":" + (left.HasValue ? JsonConvert.SerializeObject(catalog.Skip(10).Take(left.Value)) : "null") + "," +
                "\"right\":" + (right.HasValue ? JsonConvert.SerializeObject(catalog.Skip(30).Take(right.Value)) : "null") + "}";
        }

        private static IEnumerator OpenPodDraft(PhaseFiveSceneTests.PlayServer server)
        {
            yield return Home(server);
            var router = Object.FindAnyObjectByType<ScreenRouter>();
            router.Show<PodDraftScreen>(7);
            yield return MainSceneTests.WaitFor<PodDraftScreen>();
            yield return PhaseTwoSceneTests.Frames(10);
        }

        private static PodDraftScreen PodDraft() => MainSceneTests.Screen<PodDraftScreen>();

        [UnityTest]
        public IEnumerator PodDraft_ShowsTwoBoosters_AndTakesACardFromEach()
        {
            var server = Serve();
            server.PodDraftJson = PodState(round: 2, left: 14, right: 14);
            yield return OpenPodDraft(server);
            var catalog = CardCatalog();
            var leftCard = catalog[10];
            var rightCard = catalog[30];

            Assert.AreEqual("Round 2 of 15  -  pod of 8", PodDraft().InfoText);
            Assert.IsTrue(Texts().Contains("Left booster (passed to your left)"));
            Assert.IsTrue(Texts().Contains("Right booster (passed to your right)"));
            Assert.AreEqual(14, Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).Count(t => t.name.StartsWith("left cell ")));
            Assert.AreEqual(14, Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).Count(t => t.name.StartsWith("right cell ")));
            Assert.IsFalse(PodDraft().CanTake, "nothing chosen yet");
            Assert.IsTrue(Texts().Contains("Your picks (4)"));
            ScreenshotHelper.Capture("pod-draft");

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("left cell " + leftCard.Name).GetComponentInChildren<Button>());
            Assert.IsTrue(PodDraft().CanTake);
            Assert.AreEqual(leftCard.Name, PodDraft().SelectedName);
            Assert.IsTrue(Texts().Contains("Take " + leftCard.Name));

            server.PodDraftJson = PodState(round: 2, left: null, right: 14, drafted: 5);
            yield return PhaseFiveSceneTests.Tap(Named("Take card"));
            yield return PhaseTwoSceneTests.Frames(10);

            var pick = server.Posts("/tournaments/pod-draft/pick").Single();
            Assert.AreEqual(7, (int)pick["tournament_id"]);
            Assert.AreEqual("left", (string)pick["direction"]);
            Assert.AreEqual(leftCard.CardId, (int)pick["card_id"]);
            Assert.IsTrue(Texts().Contains("Left booster (passed to your left): taken this round"), string.Join(" | ", Texts()));
            Assert.IsFalse(PodDraft().CanTake);
            Assert.IsTrue(Texts().Contains("Your picks (5)"));

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("right cell " + rightCard.Name).GetComponentInChildren<Button>());
            server.PodDraftJson = PodState(round: 3, left: 13, right: 13, drafted: 6);
            yield return PhaseFiveSceneTests.Tap(Named("Take card"));
            yield return PhaseTwoSceneTests.Frames(10);
            Assert.AreEqual("right", (string)server.Posts("/tournaments/pod-draft/pick").Last()["direction"]);
            Assert.AreEqual("Round 3 of 15  -  pod of 8", PodDraft().InfoText, "a new round: both boosters are back");
            Assert.IsNull(PodDraft().SelectedName, "and nothing is chosen");
        }

        [UnityTest]
        public IEnumerator PodDraft_TappingTheChosenCardAgain_LetsGoOfIt()
        {
            var server = Serve();
            server.PodDraftJson = PodState(round: 1, left: 14, right: 14);
            yield return OpenPodDraft(server);
            var card = CardCatalog()[10];

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("left cell " + card.Name).GetComponentInChildren<Button>());
            Assert.IsTrue(PodDraft().CanTake);
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("left cell " + card.Name).GetComponentInChildren<Button>());
            Assert.IsFalse(PodDraft().CanTake);
        }

        [UnityTest]
        public IEnumerator PodDraft_WhenBothAreTaken_WaitsForTheOthers()
        {
            var server = Serve();
            server.PodDraftJson = PodState(round: 5, left: null, right: null, drafted: 8);
            yield return OpenPodDraft(server);

            Assert.AreEqual("Round 5 of 15  -  pod of 8", PodDraft().InfoText);
            Assert.AreEqual("You've taken both cards this round. Waiting for the others.", PodDraft().NoteText);
        }

        [UnityTest]
        public IEnumerator PodDraft_WhenFinished_SaysSo_AndYourPicksCanBeRead()
        {
            var server = Serve();
            server.PodDraftJson = PodState(round: 15, left: null, right: null, drafted: 30).Replace("\"pod_status\":\"drafting\"", "\"pod_status\":\"completed\"");
            yield return OpenPodDraft(server);

            Assert.AreEqual("The draft is finished", PodDraft().InfoText);
            Assert.IsTrue(PodDraft().NoteText.StartsWith("You drafted 30 cards."));
            yield return PhaseTwoSceneTests.Click("Your picks (30)");
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.IsTrue(Texts().Contains("Your picks"));
            Assert.IsTrue(PodDraft().HandleBack());
        }

        // Making a tournament ------------------------------------------------------------------------------

        private static Toggle ToggleLabeled(string label) =>
            Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude).First(t => t.GetComponentInChildren<Text>().text == label);

        private static IEnumerator OpenNewTournament(PhaseFiveSceneTests.PlayServer server)
        {
            yield return OpenTournaments(server);
            yield return PhaseFiveSceneTests.Tap(Named("New tournament"));
            yield return MainSceneTests.WaitFor<NewTournamentScreen>();
            yield return PhaseTwoSceneTests.Frames(10);
        }

        private static NewTournamentScreen NewTournament() => MainSceneTests.Screen<NewTournamentScreen>();

        private static void TypeName(string name) =>
            Object.FindObjectsByType<InputField>(FindObjectsInactive.Exclude).First(f => f.name == "Tournament name field").text = name;

        [UnityTest]
        public IEnumerator NewTournament_NeedsANameAndYourDeck_BeforeItIsSent()
        {
            var server = Serve();
            server.DecklistsJson = OwnDecks("Mine");
            yield return OpenNewTournament(server);
            ScreenshotHelper.Capture("new-tournament");

            yield return PhaseFiveSceneTests.Tap(Named("Create tournament"));
            Assert.AreEqual("Give the tournament a name.", NewTournament().StatusText);

            TypeName("Spring Open");
            yield return PhaseFiveSceneTests.Tap(Named("Create tournament"));
            Assert.AreEqual("Choose the deck you will play.", NewTournament().StatusText);
            Assert.AreEqual(0, server.Posts("/tournaments").Count());
        }

        [UnityTest]
        public IEnumerator NewTournament_APowerDuel_SendsYourDeck_AndOpensTheTournament()
        {
            var server = Serve();
            server.DecklistsJson = OwnDecks("Mine", "Other");
            server.TournamentStateJson = BracketState.Replace("\"id\":7,", "\"id\":77,").Replace("\"status\":\"in_progress\",\"winner_user_id\":null", "\"status\":\"registration\",\"winner_user_id\":null");
            yield return OpenNewTournament(server);

            TypeName("Spring Open");
            ToggleLabeled("Other  (15 cards)").isOn = true;
            ToggleLabeled("Allow sideboarding").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            yield return PhaseFiveSceneTests.Tap(Named("Create tournament"));
            yield return MainSceneTests.WaitFor<TournamentScreen>();
            yield return PhaseTwoSceneTests.Frames(8);

            var body = server.Posts("/tournaments").Single();
            Assert.AreEqual("Spring Open", (string)body["name"]);
            Assert.AreEqual("duel", (string)body["format"]);
            Assert.AreEqual("custom_duel", (string)body["deck_type"]);
            Assert.AreEqual(21, (int)body["saved_decklist_id"]);
            Assert.AreEqual("power", (string)body["duel_deck_rules"]["preset"]);
            Assert.IsTrue((bool)body["allow_sideboarding"]);
            Assert.AreEqual(77, TournamentView().TournamentId, "the new tournament is opened");
        }

        [UnityTest]
        public IEnumerator NewTournament_PickingAFormat_ChangesWhatItAsksFor()
        {
            var server = Serve();
            server.DecklistsJson = OwnDecks("Mine");
            yield return OpenNewTournament(server);
            Assert.IsTrue(Texts().Contains("Your deck"), "Power Duel asks for a deck");

            ToggleLabeled("Grid Draft").isOn = true;
            yield return PhaseTwoSceneTests.Frames(4);
            Assert.IsFalse(Texts().Contains("Your deck"));
            Assert.IsTrue(Texts().Contains("Pods with playoffs"));

            ToggleLabeled("Pods with playoffs").isOn = true;
            yield return PhaseTwoSceneTests.Frames(4);
            Assert.IsTrue(Texts().Any(t => t.StartsWith("Single elimination: every pod's bracket")), "the bracket is forced");
            Assert.IsNull(Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude).FirstOrDefault(t => t.GetComponentInChildren<Text>().text == "Swiss rounds"));

            TypeName("Pod party");
            yield return PhaseFiveSceneTests.Tap(Named("Create tournament"));
            yield return PhaseTwoSceneTests.Frames(8);
            var body = server.Posts("/tournaments").Single();
            Assert.AreEqual("draft", (string)body["format"]);
            Assert.AreEqual("grid_draft_pod_playoff", (string)body["deck_type"]);
            Assert.AreEqual("single_elimination", (string)body["bracket_type"]);
            Assert.AreEqual("random_48", (string)body["grid_draft_pool_source"]);
            Assert.IsNull(body["saved_decklist_id"]);
        }

        [UnityTest]
        public IEnumerator NewTournament_PlayerCounts_StepWithinFourToSixteen()
        {
            var server = Serve();
            yield return OpenNewTournament(server);

            Assert.AreEqual("4", Texts().First(t => t == "4"));
            for (var i = 0; i < 20; i++)
            {
                NewTournament().GetComponentsInChildren<Button>().First(b => b.name == "More max").onClick.Invoke();
                yield return null;
            }

            Assert.AreEqual(16, NewTournament().Setup.MaxParticipants);
            NewTournament().GetComponentsInChildren<Button>().First(b => b.name == "More min").onClick.Invoke();
            yield return null;
            Assert.AreEqual(5, NewTournament().Setup.MinParticipants);

            for (var i = 0; i < 20; i++)
            {
                NewTournament().GetComponentsInChildren<Button>().First(b => b.name == "Fewer max").onClick.Invoke();
                yield return null;
            }

            Assert.AreEqual(4, NewTournament().Setup.MaxParticipants);
            Assert.AreEqual(4, NewTournament().Setup.MinParticipants, "the minimum comes down with the maximum");
        }

        [UnityTest]
        public IEnumerator NewTournament_InviteOnly_ListsYourFriendsToInvite()
        {
            var server = Serve();
            yield return OpenNewTournament(server);
            ToggleLabeled("Traditional").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            ToggleLabeled("Invite only").isOn = true;
            yield return PhaseTwoSceneTests.Frames(6);

            var friend = NewTournament().GetComponentsInChildren<Toggle>().First(t => t.name.StartsWith("Invite ") && t.name != "Invite only");
            Assert.IsTrue(Texts().Any(t => t.StartsWith("Invited: 0")), string.Join(" | ", Texts()));
            friend.isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            Assert.AreEqual(1, NewTournament().Setup.InviteUserIds.Count);

            TypeName("Friends only");
            yield return PhaseFiveSceneTests.Tap(Named("Create tournament"));
            StringAssert.StartsWith("Invite at least", NewTournament().StatusText, "a full field needs more invitations than that");
            Assert.AreEqual(0, server.Posts("/tournaments").Count());
        }

        // --- the Weekly Sealed Pool ----------------------------------------------------------------------

        private const string WeeklyStandings =
            "{\"status\":\"ok\",\"standings\":[" +
            "{\"user_id\":3,\"username\":\"Alder\",\"wins\":3,\"losses\":0,\"rank\":1,\"percentile\":10}," +
            "{\"user_id\":2,\"username\":\"bshaftoe\",\"wins\":2,\"losses\":1,\"rank\":2,\"percentile\":20}]}";

        private static IEnumerator OpenWeekly(PhaseFiveSceneTests.PlayServer server)
        {
            yield return Home(server);
            yield return PhaseTwoSceneTests.Click("Play  (3 waiting on you)");
            yield return MainSceneTests.WaitFor<PlayScreen>();
            yield return PhaseTwoSceneTests.Frames(6);
            yield return PhaseTwoSceneTests.Click("Weekly pool");
            yield return MainSceneTests.WaitFor<WeeklySealedPoolScreen>();
            yield return PhaseTwoSceneTests.Frames(10);
        }

        private static WeeklySealedPoolScreen Weekly() => MainSceneTests.Screen<WeeklySealedPoolScreen>();

        [UnityTest]
        public IEnumerator WeeklySealedPool_ShowsTheQueueAndTheStandings_WithYouMarked()
        {
            var server = Serve();
            server.WeeklyStandingsJson = WeeklyStandings;
            server.WeeklyQueueJson = "{\"status\":\"ok\",\"queued\":false,\"in_progress_count\":1,\"concurrent_match_cap\":2}";
            yield return OpenWeekly(server);

            Assert.AreEqual("1/2 matches in progress this week.", Weekly().QueueText);
            Assert.IsTrue(Weekly().JoinEnabled);
            Assert.IsFalse(Weekly().LeaveShown);
            Assert.IsTrue(Texts().Contains("#1 Alder  -  3-0 (top 10%)"), string.Join(" | ", Texts()));
            Assert.IsTrue(Texts().Contains("#2 bshaftoe (you)  -  2-1 (top 20%)"));
            ScreenshotHelper.Capture("weekly-sealed-pool");
        }

        [UnityTest]
        public IEnumerator WeeklySealedPool_AtTheMatchCap_JoiningIsOff()
        {
            var server = Serve();
            server.WeeklyQueueJson = "{\"status\":\"ok\",\"queued\":false,\"in_progress_count\":2,\"concurrent_match_cap\":2}";
            yield return OpenWeekly(server);

            Assert.IsFalse(Weekly().JoinEnabled);
            Assert.IsTrue(Texts().Contains("No standings yet -- be the first to finish a match this week!"));
        }

        [UnityTest]
        public IEnumerator WeeklySealedPool_JoiningWithNobodyWaiting_QueuesYou_AndLeavingTakesYouOut()
        {
            var server = Serve();
            yield return OpenWeekly(server);

            server.WeeklyQueueJson = "{\"status\":\"ok\",\"queued\":true,\"in_progress_count\":0,\"concurrent_match_cap\":2}";
            yield return PhaseFiveSceneTests.Tap(Named("Join queue"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual(1, server.Posts("/weekly-sealed-pool/queue").Count());
            Assert.IsTrue(Weekly().QueueText.StartsWith("You're in the queue, waiting for an opponent."));
            Assert.IsTrue(Weekly().LeaveShown);
            Assert.IsFalse(Weekly().JoinEnabled);

            server.WeeklyQueueJson = "{\"status\":\"ok\",\"queued\":false,\"in_progress_count\":0,\"concurrent_match_cap\":2}";
            yield return PhaseFiveSceneTests.Tap(Named("Leave queue"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual(1, server.Posts("/weekly-sealed-pool/queue/leave").Count());
            Assert.IsFalse(Weekly().LeaveShown);
            Assert.IsTrue(Weekly().JoinEnabled);
        }

        [UnityTest]
        public IEnumerator WeeklySealedPool_JoiningWhenSomeoneIsWaiting_OpensTheNewGame()
        {
            var server = Serve();
            server.WeeklyJoinJson = "{\"status\":\"paired\",\"game_id\":812,\"opponent_username\":\"Alder\"}";
            server.StateFor = id => PhaseFiveSceneTests.Load(405);
            yield return OpenWeekly(server);

            yield return PhaseFiveSceneTests.Tap(Named("Join queue"));
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=812")));
        }

        [UnityTest]
        public IEnumerator WeeklySealedPool_LastWeek_CanBeLookedAt_OrNotExist()
        {
            var server = Serve();
            server.WeeklyStandingsJson = WeeklyStandings;
            yield return OpenWeekly(server);

            yield return PhaseFiveSceneTests.Tap(Named("Last week"));
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsTrue(Texts().Contains("There was no Weekly Sealed Pool event last week."), string.Join(" | ", Texts()));
            Assert.IsFalse(Texts().Contains("#1 Alder  -  3-0 (top 10%)"));

            yield return PhaseFiveSceneTests.Tap(Named("This week"));
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsTrue(Texts().Contains("#1 Alder  -  3-0 (top 10%)"));
        }

        [UnityTest]
        public IEnumerator WeeklySealedPool_ARefusedJoin_ShowsTheServersMessage()
        {
            var server = Serve();
            server.WeeklyJoinJson = "{\"status\":\"error\",\"message\":\"You are already in the Weekly Sealed Pool queue.\"}";
            yield return OpenWeekly(server);

            yield return PhaseFiveSceneTests.Tap(Named("Join queue"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual("You are already in the Weekly Sealed Pool queue.", Weekly().StatusText);
        }

        // --- notification settings -----------------------------------------------------------------------

        private static IEnumerator OpenSettingsScreen(PhaseFiveSceneTests.PlayServer server)
        {
            yield return Open<SettingsScreen>(server, "Settings");
        }

        private static Toggle NotifyToggle(string label) =>
            Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude).First(t => t.name == "Notify " + label);

        [UnityTest]
        public IEnumerator Settings_ListWhatYouCanBeNotifiedAbout_WithTheSavedValues()
        {
            var server = Serve();
            yield return OpenSettingsScreen(server);

            Assert.IsTrue(Texts().Contains("Notify me when..."));
            Assert.IsTrue(NotifyToggle("It's my turn").isOn);
            Assert.IsFalse(NotifyToggle("One of my games finishes").isOn, "this one is saved off");
            Assert.IsFalse(NotifyToggle("Send every notification immediately").isOn);
            Assert.IsTrue(NotifyToggle("I unlock an achievement").isOn);
            var note = Texts().First(t => t.StartsWith("Discord: not linked."));
            StringAssert.Contains("can't receive push notifications yet", note);
        }

        [UnityTest]
        public IEnumerator Settings_ALinkedDiscord_IsNamed()
        {
            var server = Serve();
            server.DiscordStatusJson = "{\"status\":\"ok\",\"linked\":true,\"discord_username\":\"jed#1\"}";
            yield return OpenSettingsScreen(server);

            Assert.IsTrue(Texts().Any(t => t.StartsWith("Discord: linked as jed#1.")), string.Join(" | ", Texts()));
        }

        [UnityTest]
        public IEnumerator Settings_FlippingANotificationSwitch_SavesTheWholeSet()
        {
            var server = Serve();
            yield return OpenSettingsScreen(server);

            NotifyToggle("One of my games finishes").isOn = true;
            yield return PhaseTwoSceneTests.Frames(8);

            var body = server.Posts("/notifications/preferences").Single();
            Assert.IsTrue((bool)body["notify_game_finished"]);
            Assert.IsTrue((bool)body["notify_your_turn"], "the rest go along, or the server would reset them");
            Assert.IsTrue((bool)body["notify_friend_request"]);
            Assert.IsTrue((bool)body["notify_chat_message"]);
            Assert.IsTrue((bool)body["notify_timeout_warning"]);
            Assert.IsTrue((bool)body["notify_achievement_unlocked"]);
            Assert.IsFalse((bool)body["disable_cooldown"]);
            Assert.AreEqual("Saved.", MainSceneTests.Screen<SettingsScreen>().StatusText);
            Assert.IsTrue(NotifyToggle("One of my games finishes").isOn);
            ScreenshotHelper.Capture("settings-notifications");
        }

        [UnityTest]
        public IEnumerator Settings_AFailedNotificationSave_FlipsItBack()
        {
            var server = Serve();
            server.FailNotificationSave = true;
            yield return OpenSettingsScreen(server);

            NotifyToggle("It's my turn").isOn = false;
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsTrue(NotifyToggle("It's my turn").isOn, "rolled back");
            Assert.AreEqual("The server hiccuped.", MainSceneTests.Screen<SettingsScreen>().StatusText);
        }

        [UnityTest]
        public IEnumerator Settings_WhenTheNotificationSettingsCantBeLoaded_SaySo()
        {
            var server = Serve();
            server.NotificationPreferencesJson = "{\"status\":\"error\",\"message\":\"Not available\"}";
            yield return OpenSettingsScreen(server);

            Assert.IsTrue(Texts().Contains("Not available"), string.Join(" | ", Texts()));
            Assert.IsNull(Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude).FirstOrDefault(t => t.name.StartsWith("Notify ")));
        }

        [UnityTest]
        public IEnumerator Tournaments_ALongName_WrapsBesideTheButtons_WhichAreStacked()
        {
            var server = Serve();
            server.DecklistsJson = OwnDecks("Mine");
            var longName = "The Grand Annual Spring Championship Invitational of the Very Long Winded Tournament Committee Open Series";
            server.TournamentsMineJson = TourList(Tour(3, longName, "custom_duel", creator: 3));
            yield return OpenTournaments(server);

            var view = (RectTransform)Named("View " + longName).transform;
            var edit = (RectTransform)Named("Edit deck " + longName).transform;
            Assert.Greater(view.position.y, edit.position.y, "View sits above Edit deck");
            Assert.AreEqual(view.position.x, edit.position.x, 0.5f, "in one column");

            var text = Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).First(t => t.text.StartsWith("The Grand Annual"));
            var corners = new Vector3[4];
            text.rectTransform.GetWorldCorners(corners);
            var buttons = new Vector3[4];
            view.GetWorldCorners(buttons);
            Assert.LessOrEqual(corners[2].x, buttons[0].x + 0.5f, "the text ends before the buttons begin");
            Assert.Greater(text.rectTransform.rect.height, 60f, "and it took more than one line");
            ScreenshotHelper.Capture("tournaments-long-name");
        }

        [UnityTest]
        public IEnumerator Settings_HasNoBoardLayoutSetting_ThisBoardHasItsOwnLayout()
        {
            var server = Serve();
            yield return OpenSettingsScreen(server);

            Assert.IsFalse(Texts().Contains("Display"));
            Assert.IsFalse(Texts().Contains("Round / Score / Players position"));
        }
    }
}
