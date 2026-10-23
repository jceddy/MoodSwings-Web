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

        // --- push notifications --------------------------------------------------------------------------

        private sealed class PlayPushProvider : IPushTokenProvider
        {
            public string Token = "fcm-play-token";

            public string LaunchUrl;

            public bool IsAvailable => true;

            public string Platform => "android";

            public event System.Action<string> TokenRefreshed
            {
                add { }
                remove { }
            }

            public System.Threading.Tasks.Task<string> GetTokenAsync() => System.Threading.Tasks.Task.FromResult(Token);

            public string TakeLaunchUrl()
            {
                var url = LaunchUrl;
                LaunchUrl = null;
                return url;
            }
        }

        private static IEnumerator HomeWithPush(PhaseFiveSceneTests.PlayServer server, PlayPushProvider provider)
        {
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok", pushProvider: provider);
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
        }

        [UnityTest]
        public IEnumerator Push_OnHome_TheDeviceTokenIsRegistered()
        {
            var server = Serve();
            yield return HomeWithPush(server, new PlayPushProvider());

            var post = server.Posts("/notifications/device-token").Single();
            Assert.AreEqual("fcm-play-token", (string)post["token"]);
            Assert.AreEqual("android", (string)post["platform"]);
        }

        [UnityTest]
        public IEnumerator Push_WithoutAProvider_NothingIsRegistered()
        {
            var server = Serve();
            yield return Home(server);

            Assert.AreEqual(0, server.Posts("/notifications/device-token").Count());
            Assert.IsNull(Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude).FirstOrDefault(t => t.name == "Push notifications"));
        }

        [UnityTest]
        public IEnumerator Push_ATappedNotification_OpensItsGame()
        {
            var server = Serve();
            server.StateFor = id => PhaseFiveSceneTests.Load(405);
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok", pushProvider: new PlayPushProvider { LaunchUrl = "/game/?id=406" });
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=406")));
        }

        [UnityTest]
        public IEnumerator Push_ATappedFriendRequestNotification_OpensFriends()
        {
            var server = Serve();
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok", pushProvider: new PlayPushProvider { LaunchUrl = "/game/?open_friends=1" });
            yield return MainSceneTests.WaitFor<FriendsScreen>();
        }

        [UnityTest]
        public IEnumerator Push_LoggingOut_TakesTheTokenBackFirst()
        {
            var server = Serve();
            yield return HomeWithPush(server, new PlayPushProvider());

            yield return PhaseTwoSceneTests.Click("Log out");
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual("fcm-play-token", (string)server.Posts("/notifications/device-token/remove").Single()["token"]);
            var calls = server.Calls;
            Assert.Less(calls.FindIndex(c => c.StartsWith("POST /notifications/device-token/remove")), calls.FindIndex(c => c.StartsWith("POST /logout")),
                "while the session still works");
        }

        [UnityTest]
        public IEnumerator Push_TheDeviceSwitch_IsInSettings_AndRemovesTheTokenWhenOff()
        {
            var server = Serve();
            yield return HomeWithPush(server, new PlayPushProvider());
            yield return PhaseTwoSceneTests.Click("Settings");
            yield return MainSceneTests.WaitFor<SettingsScreen>();
            yield return PhaseTwoSceneTests.Frames(8);

            var toggle = Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude).First(t => t.name == "Push notifications");
            Assert.IsTrue(toggle.isOn);

            toggle.isOn = false;
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.AreEqual(1, server.Posts("/notifications/device-token/remove").Count());
            Assert.IsFalse(AppServices.Device.PushOn);

            toggle.isOn = true;
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.AreEqual(2, server.Posts("/notifications/device-token").Count(), "registered again");
            Assert.IsTrue(AppServices.Device.PushOn);
        }

        // --- the hidden dev/prod switch ------------------------------------------------------------------

        private static IEnumerator LoginScreenUp(PhaseFiveSceneTests.PlayServer server)
        {
            yield return MainSceneTests.Launch(server.Handle);
            yield return MainSceneTests.WaitFor<LoginScreen>();
            yield return PhaseTwoSceneTests.Frames(6);
        }

        private static void TapVersion(int times)
        {
            var screen = MainSceneTests.Screen<LoginScreen>();
            for (var i = 0; i < times; i++)
            {
                screen.OnVersionTapped();
            }
        }

        private static GameObject Banner() =>
            Object.FindObjectsByType<Transform>(FindObjectsInactive.Include).First(t => t.name == "Server banner").gameObject;

        [UnityTest]
        public IEnumerator ServerSwitch_SevenTapsOnTheVersionText_PutTheAppOnTheDevServer_AndBackAgain()
        {
            var server = Serve();
            yield return LoginScreenUp(server);
            Assert.IsFalse(Banner().activeSelf, "no tag off the dev server");

            TapVersion(LoginScreen.VersionTapsToSwitch - 1);
            yield return PhaseTwoSceneTests.Frames(4);
            Assert.IsFalse(AppServices.IsDevServer, "six taps aren't enough");

            TapVersion(1);
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsTrue(AppServices.IsDevServer);
            Assert.AreEqual(MoodSwings.Networking.ApiConfig.DevSiteRoot, AppServices.Api.Config.SiteRoot);
            Assert.IsTrue(Banner().activeSelf, "a tag says which server this is");
            Assert.IsTrue(Texts().Contains("Dev server mode is on."), string.Join(" | ", Texts()));
            Assert.IsInstanceOf<LoginScreen>(Object.FindAnyObjectByType<ScreenRouter>().Current, "still at the login screen");
            ScreenshotHelper.Capture("login-dev-server");

            TapVersion(LoginScreen.VersionTapsToSwitch);
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsFalse(AppServices.IsDevServer);
            Assert.AreEqual(MoodSwings.Networking.ApiConfig.ProdSiteRoot, AppServices.Api.Config.SiteRoot);
            Assert.IsFalse(Banner().activeSelf);
            Assert.IsTrue(Texts().Contains("Back on the production server."));
        }

        [UnityTest]
        public IEnumerator ServerSwitch_TapsSpreadOutOverTime_DontAddUp()
        {
            var server = Serve();
            yield return LoginScreenUp(server);

            TapVersion(LoginScreen.VersionTapsToSwitch - 1);
            yield return new WaitForSecondsRealtime(2.2f);
            TapVersion(1);
            yield return PhaseTwoSceneTests.Frames(4);

            Assert.IsFalse(AppServices.IsDevServer, "the count started over after the pause");
        }

        [UnityTest]
        public IEnumerator ServerSwitch_TappingTheRealFooter_Counts()
        {
            var server = Serve();
            yield return LoginScreenUp(server);

            var footer = Object.FindObjectsByType<Button>(FindObjectsInactive.Exclude).First(b => b.name == "Footer");
            for (var i = 0; i < LoginScreen.VersionTapsToSwitch; i++)
            {
                footer.onClick.Invoke();
            }

            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsTrue(AppServices.IsDevServer);
        }

        // --- the board's layout ---------------------------------------------------------------------------

        private static Newtonsoft.Json.Linq.JObject Quiet(int gameId, System.Action<Newtonsoft.Json.Linq.JObject> edit = null) =>
            PhaseFiveSceneTests.Load(gameId, s =>
            {
                s["round"]["pending_decision"] = null;
                edit?.Invoke(s);
            });

        [UnityTest]
        public IEnumerator Back_AfterAScreenWasShownOverItself_DoesNotReturnToAnEmptyBoard()
        {
            var server = Serve();
            server.State = Quiet(406, s => { });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            var router = Object.FindAnyObjectByType<ScreenRouter>();

            // As when a notification for another game is tapped while a board is up.
            router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, 405));
            yield return MainSceneTests.WaitFor<BoardScreen>();
            Assert.IsTrue(router.Back());
            yield return PhaseTwoSceneTests.Frames(3);

            Assert.IsInstanceOf<HomeScreen>(router.Current, "straight back to where the first board was opened from");
        }

        [UnityTest]
        public IEnumerator TheCameraBackground_FollowsThePalette_AndTheDevTagStaysInsideTheSafeArea()
        {
            MainSceneTests.DarkMode = true;
            ScreenSafeArea.Override = new Rect(100f, 0f, Screen.width - 200f, Screen.height);
            try
            {
                var server = Serve();
                yield return LoginScreenUp(server);
                Assert.AreEqual(AppServices.Theme.background, Camera.main.backgroundColor, "so a notch isn't a light strip in dark mode");

                TapVersion(7);
                yield return PhaseTwoSceneTests.Frames(3);
                var tag = (RectTransform)Banner().transform;
                var corners = new Vector3[4];
                tag.GetWorldCorners(corners);
                var right = RectTransformUtility.WorldToScreenPoint(Camera.main, corners[2]).x;
                Assert.LessOrEqual(right, Screen.width - 100f, "inside the safe area, not up against the edge");
            }
            finally
            {
                ScreenSafeArea.Override = null;
                MainSceneTests.DarkMode = false;
                AppServices.Theme.Apply(dark: false);
            }
        }

        // Game 405 with its first mood's rules saying "while in play" and its value moved by them, and (optionally) its
        // second mood suppressed by that first one.
        private static Newtonsoft.Json.Linq.JObject WithAnOngoingEffect(bool suppressing) => Quiet(405, s =>
        {
            var inPlay = (Newtonsoft.Json.Linq.JArray)s["in_play"];
            inPlay[0]["rules_text"] = "While in play - This mood's value is 6 if there are two or more blue moods.";
            inPlay[0]["base_value"] = 3;
            inPlay[0]["value"] = 6;
            if (suppressing)
            {
                inPlay[1]["is_suppressed"] = true;
                inPlay[1]["value"] = 0;
                inPlay[1]["suppressions"] = Newtonsoft.Json.Linq.JArray.Parse(
                    "[{\"expiry\":\"while_source_in_play\",\"suppressed_by_card_id\":" + (int)inPlay[0]["card_id"] + ",\"suppressed_by_name\":\"" + (string)inPlay[0]["name"] + "\"}]");
            }
        });

        [UnityTest]
        public IEnumerator AMoodWithAnOngoingEffect_Glows_AndOthersDoNot()
        {
            var server = Serve();
            server.State = WithAnOngoingEffect(suppressing: false);
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            var name = (string)server.State["in_play"][0]["name"];
            var other = (string)server.State["in_play"][1]["name"];
            var aura = PhaseFiveSceneTests.Child("Card " + name).Find("Aura");
            Assert.IsNotNull(aura, "the glow is part of the card");
            Assert.IsNotNull(aura.Find("Halo"));
            Assert.IsNotNull(aura.Find("Ripple"));
            Assert.AreEqual(0, aura.GetSiblingIndex(), "under the card's own pieces");
            Assert.IsNull(PhaseFiveSceneTests.Child("Card " + other).Find("Aura"), "a mood with nothing going on has no glow");
            Assert.IsFalse(aura.GetComponentsInChildren<UnityEngine.UI.Graphic>().Any(g => g.raycastTarget), "and it never takes a tap");

            // It moves: the halo's brightness changes as it breathes.
            var halo = aura.GetComponent<CardAura>().Halo;
            var before = halo.color.a;
            var changed = false;
            for (var i = 0; i < 40 && !changed; i++)
            {
                yield return null;
                changed = !Mathf.Approximately(halo.color.a, before);
            }

            Assert.IsTrue(changed, "it pulses");
            ScreenshotHelper.Capture("effect-aura");
        }

        [UnityTest]
        public IEnumerator AMoodSuppressingAnother_IsLinkedToIt_AndNoGlowOnTheSuppressedOne()
        {
            var server = Serve();
            server.State = WithAnOngoingEffect(suppressing: true);
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            var layer = Object.FindAnyObjectByType<EffectLinksLayer>();
            Assert.IsNotNull(layer);
            Assert.AreEqual(1, layer.LineCount, "one line, from the source to what it suppresses");
            Assert.IsFalse(layer.raycastTarget);
            yield return PhaseTwoSceneTests.Frames(30);
            Assert.Greater(layer.VertexCount, 0, "and it is drawn");

            var other = (string)server.State["in_play"][1]["name"];
            Assert.IsNull(PhaseFiveSceneTests.Child("Card " + other).Find("Aura"), "a suppressed mood is switched off, so it doesn't glow");

            for (var i = 0; i < 4; i++)
            {
                yield return PhaseTwoSceneTests.Frames(12);
                ScreenshotHelper.Capture("effect-link-" + i);
            }
        }

        [UnityTest]
        public IEnumerator TheLinks_GoWhenTheEffectEnds()
        {
            var server = Serve();
            server.State = WithAnOngoingEffect(suppressing: true);
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            Assert.AreEqual(1, Object.FindAnyObjectByType<EffectLinksLayer>().LineCount);

            server.State = WithAnOngoingEffect(suppressing: false);
            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual(0, Object.FindAnyObjectByType<EffectLinksLayer>().LineCount);
        }

        [UnityTest]
        public IEnumerator TheCenter_DoesNotRepeatWhoseTurnItIs_AndKeepsAModestButton()
        {
            var server = Serve();
            server.State = Quiet(406, s => { });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.AreEqual("Your turn", PhaseFiveSceneTests.Board().TurnText);
            Assert.AreEqual(string.Empty, PhaseFiveSceneTests.Board().BannerText, "that is on the scoreboards");
            Assert.IsNull(PhaseFiveSceneTests.Child("Banner"), "and the empty line takes no room");
            StringAssert.DoesNotContain("First to", PhaseFiveSceneTests.Board().RoundText);

            var pass = (RectTransform)PhaseFiveSceneTests.Child("Primary action");
            Assert.LessOrEqual(pass.rect.height, 60f);
            Assert.LessOrEqual(pass.rect.width, 300f);
        }

        [UnityTest]
        public IEnumerator TheScoreboard_ShowsHowManyPlaysTheCurrentPlayerHasLeft()
        {
            var server = Serve();
            server.State = Quiet(406, s => s["round"]["plays_remaining"] = 1);
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            var label = PhaseFiveSceneTests.Child("Plays left");
            Assert.IsNotNull(label);
            Assert.AreEqual("1 play left", label.GetComponent<Text>().text);
            Assert.AreEqual(1, Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Count(t => t.name == "Plays left"), "only whose turn it is");
            ScreenshotHelper.Capture("plays-left-one");

            server.State = Quiet(406, s => s["round"]["plays_remaining"] = 3);
            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual("3 plays left", PhaseFiveSceneTests.Child("Plays left").GetComponent<Text>().text);
            ScreenshotHelper.Capture("plays-left-three");
        }

        private sealed class ZapSounds : ISoundPlayer
        {
            public readonly System.Collections.Generic.List<SoundId> Played = new System.Collections.Generic.List<SoundId>();

            public void Play(SoundId sound) => Played.Add(sound);
        }

        private static IEnumerator WaitUntilTrue(System.Func<bool> condition, float seconds)
        {
            var waited = 0f;
            while (!condition() && waited < seconds)
            {
                waited += Time.unscaledDeltaTime;
                yield return null;
            }
        }

        [UnityTest]
        public IEnumerator AMoodThatTakesOthersOutOfPlay_StrikesThemWithLightning_BeforeTheyAreDiscarded()
        {
            var sounds = new ZapSounds();
            GameFeedback.Sound = sounds;
            try
            {
                var server = Serve();
                server.State = Quiet(406, s => { });
                yield return PhaseFiveSceneTests.OpenBoard(server, 406);
                Assert.AreEqual(0, PhaseFiveSceneTests.Board().ZappedCardCount);

                // A Shock is played, and Frustration (the other player's) goes to the discard pile.
                server.State = Quiet(406, s =>
                {
                    var inPlay = (Newtonsoft.Json.Linq.JArray)s["in_play"];
                    var struck = inPlay[1];
                    inPlay.RemoveAt(1);
                    ((Newtonsoft.Json.Linq.JArray)s["discard_pile"]).Add(struck);
                    var shock = inPlay[0].DeepClone();
                    shock["card_id"] = 14190;
                    shock["name"] = "Shock";
                    shock["effect_key"] = "shock";
                    inPlay.Add(shock);
                });

                // The struck mood stays on the table when the board is redrawn without it ...
                yield return WaitUntilTrue(() => PhaseFiveSceneTests.Board().ZappedCardCount > 0, 7f);
                Assert.AreEqual(1, PhaseFiveSceneTests.Board().ZappedCardCount, "still on the table, about to be struck");
                CollectionAssert.DoesNotContain(sounds.Played, SoundId.Zap, "the sound goes with the bolt");

                // ... until the bolt lands, with its sound.
                yield return WaitUntilTrue(() => PhaseFiveSceneTests.Board().LightningBoltCount > 0, 2f);
                Assert.AreEqual(1, PhaseFiveSceneTests.Board().LightningBoltCount);
                CollectionAssert.Contains(sounds.Played, SoundId.Zap);
                yield return new WaitForSeconds(0.25f);
                Assert.Greater(Object.FindAnyObjectByType<LightningLayer>().VertexCount, 0, "something is being drawn");
                ScreenshotHelper.Capture("lightning");

                // Then it fades away, and nothing is left behind.
                yield return WaitUntilTrue(() => PhaseFiveSceneTests.Board().ZappedCardCount == 0 && PhaseFiveSceneTests.Board().LightningBoltCount == 0, 3f);
                Assert.AreEqual(0, PhaseFiveSceneTests.Board().ZappedCardCount);
                Assert.AreEqual(0, PhaseFiveSceneTests.Board().LightningBoltCount);
                Assert.AreEqual(1, sounds.Played.Count(s => s == SoundId.Zap), "it zapped once");
            }
            finally
            {
                GameFeedback.Sound = null;
            }
        }

        // The face of the mood named so on the table, which is the child that turns (null when the card is simply upright).
        private static Transform FaceOf(string mood) => PhaseFiveSceneTests.Child("Card " + mood)?.Find("Face");

        private static float TurnOf(Transform face) => Mathf.Round(face.localEulerAngles.z);

        [UnityTest]
        public IEnumerator AMoodWhoseAlternateValueIsInEffect_LiesTurnedAround_OnTheTable()
        {
            if (CardArtLibrary.ForCard(53) == null)
            {
                Assert.Inconclusive("The card art has not been generated on this machine (tools/convert_card_art.py).");
            }

            var server = Serve();
            // Ambition worth 3, or 5 under its condition -- and the server says it is: the alternate value is in effect.
            server.State = Quiet(406, s =>
            {
                var ambition = s["in_play"][0];
                ambition["has_dice_value"] = true;
                ambition["alt_value"] = 5;
                ambition["base_value"] = 3;
                ambition["value"] = 5;
                ambition["alt_value_active"] = true;
            });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            var face = FaceOf("Ambition");
            Assert.IsNotNull(face, "the card's face is its own object, so it can turn while the badges stay put");
            Assert.AreEqual(180f, TurnOf(face), "turned around");
            Assert.IsNull(FaceOf("Frustration"), "a mood on its printed value stays upright");
            ScreenshotHelper.Capture("alt-value-turned");

            // Its condition stops holding: upright again.
            server.State = Quiet(406, s =>
            {
                var ambition = s["in_play"][0];
                ambition["has_dice_value"] = true;
                ambition["alt_value"] = 5;
                ambition["base_value"] = 3;
                ambition["value"] = 3;
                ambition["alt_value_active"] = false;
            });
            yield return PhaseFiveSceneTests.Poll();
            Assert.IsNull(FaceOf("Ambition"), "its condition no longer holds");
        }

        private static bool HasValueChip(string mood) =>
            PhaseFiveSceneTests.Child("Card " + mood)?.GetComponentsInChildren<Transform>().Any(t => t.name == "Value") == true;

        [UnityTest]
        public IEnumerator ACardTurnedOntoItsPrintedAlternateValue_HasNoValueBadge_ButOneOnItsOtherValueDoes()
        {
            if (CardArtLibrary.ForCard(53) == null)
            {
                Assert.Inconclusive("The card art has not been generated on this machine (tools/convert_card_art.py).");
            }

            var server = Serve();
            server.State = Quiet(406, s =>
            {
                var ambition = s["in_play"][0];
                ambition["has_dice_value"] = true;
                ambition["alt_value"] = 5;
                ambition["base_value"] = 3;
                ambition["value"] = 5;
                ambition["alt_value_active"] = true;
                // Another mood, changed by something else: it keeps its badge.
                s["in_play"][1]["value"] = (int)s["in_play"][1]["base_value"] + 2;
            });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsNotNull(FaceOf("Ambition"), "turned onto its alternate value");
            Assert.IsFalse(HasValueChip("Ambition"), "which the turned art already prints: no badge over it");
            Assert.IsTrue(HasValueChip("Frustration"), "a value changed some other way still gets its badge");

            // The same card on a chaos-adjusted value keeps the badge.
            server.State = Quiet(406, s =>
            {
                var ambition = s["in_play"][0];
                ambition["has_dice_value"] = true;
                ambition["alt_value"] = 5;
                ambition["base_value"] = 3;
                ambition["value"] = 7;
                ambition["chaos_value_delta"] = 2;
                ambition["alt_value_active"] = true;
            });
            yield return PhaseFiveSceneTests.Poll();
            Assert.IsNotNull(FaceOf("Ambition"));
            Assert.IsTrue(HasValueChip("Ambition"), "a chaos adjustment makes the value something else");
        }

        [UnityTest]
        public IEnumerator AMoodOnItsAlternateValueOnlyAsADiceBoost_IsNotTurnedAround_BecauseTheServerSaysNot()
        {
            if (CardArtLibrary.ForCard(53) == null)
            {
                Assert.Inconclusive("The card art has not been generated on this machine (tools/convert_card_art.py).");
            }

            var server = Serve();
            // Worth 5, the alternate, because Encouragement is applying its dice value: not a condition being met.
            server.State = Quiet(406, s =>
            {
                var ambition = s["in_play"][0];
                ambition["has_dice_value"] = true;
                ambition["alt_value"] = 5;
                ambition["base_value"] = 3;
                ambition["value"] = 5;
                ambition["boosted_by_card_id"] = s["in_play"][1]["card_id"];
                ambition["alt_value_active"] = false;
            });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsNull(FaceOf("Ambition"));
        }

        [UnityTest]
        public IEnumerator ASuppressedMoodWithItsAlternateValueLockedIn_LiesThreeQuartersAround()
        {
            if (CardArtLibrary.ForCard(53) == null)
            {
                Assert.Inconclusive("The card art has not been generated on this machine (tools/convert_card_art.py).");
            }

            var server = Serve();
            server.State = Quiet(406, s =>
            {
                var ambition = s["in_play"][0];
                ambition["has_dice_value"] = true;
                ambition["alt_value"] = 5;
                ambition["base_value"] = 3;
                ambition["value"] = 0;
                ambition["value_locked"] = true;
                ambition["alt_value_active"] = false;
                ambition["is_suppressed"] = true;
            });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.AreEqual(90f, TurnOf(FaceOf("Ambition")), "a quarter turn clockwise for suppressed, a half turn more for the alternate value: 270 clockwise");

            // Suppressed alone is the quarter turn the table already used.
            server.State = Quiet(406, s =>
            {
                s["in_play"][0]["is_suppressed"] = true;
                s["in_play"][0]["value"] = 0;
            });
            yield return PhaseFiveSceneTests.Poll();
            Assert.AreEqual(270f, TurnOf(FaceOf("Ambition")));
        }

        [UnityTest]
        public IEnumerator TheCloseUp_OfATurnedAroundMood_IsTheRightWayUp_AndSaysWhy()
        {
            if (CardArtLibrary.ForCard(53) == null)
            {
                Assert.Inconclusive("The card art has not been generated on this machine (tools/convert_card_art.py).");
            }

            var server = Serve();
            server.State = Quiet(406, s =>
            {
                var ambition = s["in_play"][0];
                ambition["has_dice_value"] = true;
                ambition["alt_value"] = 5;
                ambition["base_value"] = 3;
                ambition["value"] = 5;
                ambition["alt_value_active"] = true;
            });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card Ambition").GetComponent<Button>());

            Assert.IsTrue(PhaseFiveSceneTests.Board().DetailOpen);
            Assert.IsTrue(Texts().Any(t => t.Contains("alternate value (5)") && t.Contains("turned around")), string.Join(" | ", Texts()));
            var holder = Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).First(t => t.name == "Card" && t.parent != null && t.parent.name == "Content");
            Assert.IsNull(holder.GetComponentsInChildren<Transform>().FirstOrDefault(t => t.name == "Face"), "the close-up is not turned: it is there to be read");
        }

        [UnityTest]
        public IEnumerator ABlissInPlay_NamesTheColorDiscardedToIt_WhenClicked()
        {
            var server = Serve();
            server.State = Quiet(405, s =>
            {
                var card = s["in_play"][0];
                card["effect_key"] = "bliss";
                card["name"] = "Bliss";
                card["bliss_discard_color"] = "red";
            });
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            PhaseFiveSceneTests.Child("Card Bliss").GetComponent<UnityEngine.UI.Button>().onClick.Invoke();
            yield return PhaseTwoSceneTests.Frames();

            StringAssert.Contains("Discarded a", PhaseFiveSceneTests.Board().DetailText);
            StringAssert.Contains(">red</color>", PhaseFiveSceneTests.Board().DetailText);
            StringAssert.Contains("to Bliss", PhaseFiveSceneTests.Board().DetailText);
        }

        private static UnityEngine.UI.Slider SliderNamed(string name) =>
            Object.FindObjectsByType<UnityEngine.UI.Slider>(FindObjectsInactive.Exclude).FirstOrDefault(x => x.name == name);

        [UnityTest]
        public IEnumerator TheMainTheme_PlaysWhileTheAppIsOpen_AtTheDeviceVolume()
        {
            yield return Home(Serve());
            yield return new WaitForSeconds(0.8f);

            var music = MusicPlayer.Instance;
            Assert.AreEqual(MusicTheme.Main, music.Theme);
            Assert.AreEqual(AppServices.Device.MusicVolume, music.Gain, 0.0001f);
            Assert.Greater(music.LevelOf(MusicTheme.Main), 0f, "it is fading in (so the clip loaded)");
            Assert.Greater(music.VolumeOf(MusicTheme.Main), 0f);
        }

        [UnityTest]
        public IEnumerator OnTheBoard_TheMusicFollowsTheColorOfTheLatestMood_AndLeavingBringsTheMainThemeBack()
        {
            var server = Serve();
            server.State = Quiet(407, s => { });
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);

            // BotSageQuick's Confusion (blue) was played last -- not the mood listed last (Tranquility, green).
            var expected = MusicTheme.Blue;
            Assert.AreEqual(expected, MusicPlayer.Instance.Theme);

            yield return new WaitForSeconds(MusicPlayer.FadeSeconds + 0.5f);
            Assert.AreEqual(1f, MusicPlayer.Instance.LevelOf(expected), 0.05f, "faded all the way in");
            Assert.AreEqual(0f, MusicPlayer.Instance.LevelOf(MusicTheme.Main), 0.05f, "and the main theme out");

            Object.FindAnyObjectByType<ScreenRouter>().Show<HomeScreen>();
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.AreEqual(MusicTheme.Main, MusicPlayer.Instance.Theme);
        }

        [UnityTest]
        public IEnumerator Settings_HaveMusicAndEffectsVolumes_AndMuteForEach()
        {
            yield return Home(Serve());
            Object.FindAnyObjectByType<ScreenRouter>().Show<SettingsScreen>();
            yield return MainSceneTests.WaitFor<SettingsScreen>();

            var music = SliderNamed("Music volume");
            var effects = SliderNamed("Effects volume");
            Assert.IsNotNull(music);
            Assert.IsNotNull(effects);
            Assert.AreEqual(AppServices.Device.MusicVolume, music.value, 0.0001f);
            Assert.AreEqual(AppServices.Device.EffectsVolume, effects.value, 0.0001f);
            foreach (var scroll in Object.FindObjectsByType<UnityEngine.UI.ScrollRect>(FindObjectsInactive.Exclude))
            {
                scroll.verticalNormalizedPosition = 0f;
            }

            yield return PhaseTwoSceneTests.Frames(3);
            ScreenshotHelper.Capture("settings-device");
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Music"));
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Sound effects"));

            music.value = 0.2f;
            effects.value = 0.4f;
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.AreEqual(0.2f, AppServices.Device.MusicVolume, 0.0001f);
            Assert.AreEqual(0.4f, AppServices.Device.EffectsVolume, 0.0001f);
            Assert.AreEqual(0.2f, MusicPlayer.Instance.Gain, 0.0001f, "the music follows the slider as it moves");
            Assert.IsTrue(Texts().Contains("20%"));
            Assert.IsTrue(Texts().Contains("40%"));

            PhaseTwoSceneTests.FindToggle("Music").isOn = false;
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.IsFalse(AppServices.Device.MusicOn);
            Assert.AreEqual(0f, MusicPlayer.Instance.Gain, "muted");
            Assert.IsTrue(AppServices.Device.SoundOn, "the effects are a separate switch");
        }

        [UnityTest]
        public IEnumerator TheScreens_SitOnAWoodTable_LightOrDark_ThatCoversTheWholeDisplay()
        {
            ScreenSafeArea.Override = new Rect(100f, 0f, Screen.width - 200f, Screen.height);
            try
            {
                yield return Home(Serve());
                var backdrops = Object.FindObjectsByType<Backdrop>(FindObjectsInactive.Include);
                Assert.AreEqual(1, backdrops.Length);
                Assert.IsNotNull(backdrops[0].Texture, "the picture loaded");
                StringAssert.Contains("light", backdrops[0].Texture.name);

                var rect = (RectTransform)backdrops[0].transform;
                var canvas = Object.FindAnyObjectByType<ScreenRouter>().GetComponent<RectTransform>();
                Assert.AreEqual(canvas.rect.width, rect.rect.width, 0.5f, "under the notch and the corners too, not just the safe area");
                Assert.AreEqual(canvas.rect.height, rect.rect.height, 0.5f);

                Assert.IsTrue(Backdrop.IsShowing);
                Assert.AreEqual(0f, MainSceneTests.Screen<HomeScreen>().transform.Find("Background").GetComponent<UnityEngine.UI.Image>().color.a, "a screen's own backing is clear");
                ScreenshotHelper.Capture("backdrop-light");
            }
            finally
            {
                ScreenSafeArea.Override = null;
            }

            MainSceneTests.DarkMode = true;
            try
            {
                yield return Home(Serve());
                var dark = Object.FindObjectsByType<Backdrop>(FindObjectsInactive.Include).Single();
                StringAssert.Contains("dark", dark.Texture.name);
            }
            finally
            {
                MainSceneTests.DarkMode = false;
                AppServices.Theme.Apply(dark: false);
            }
        }

        [UnityTest]
        public IEnumerator TheDevTag_SitsWellInFromTheScreenCorner()
        {
            // The dev tag is a good way in from the right edge and the top.
            yield return Home(Serve());
            yield return PhaseTwoSceneTests.Frames(3);
            var tag = (RectTransform)Object.FindObjectsByType<Transform>(FindObjectsInactive.Include).First(t => t.name == "Server banner");
            Assert.LessOrEqual(tag.anchoredPosition.x, -90f);
            Assert.LessOrEqual(tag.anchoredPosition.y, -20f);
        }

        private sealed class FakePicker : IFilePicker
        {
            public string Folder;

            public bool IsAvailable => true;

            public System.Threading.Tasks.Task<PickedPath> PickFolderAsync() => System.Threading.Tasks.Task.FromResult(
                Folder == null ? null : new PickedPath { Path = Folder, Name = System.IO.Path.GetFileName(Folder) });

            public System.Threading.Tasks.Task<PickedPath> PickZipAsync() => System.Threading.Tasks.Task.FromResult<PickedPath>(null);
        }

        private static string AsText(Sprite sprite) => sprite == null ? "none" : sprite.texture.name;

        [UnityTest]
        public IEnumerator ArtPacks_CanBeAdded_Chosen_AndRemoved_InSettings()
        {
            var source = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "moodswings-test-source-" + System.Guid.NewGuid().ToString("N"), "Test Pack");
            System.IO.Directory.CreateDirectory(source);
            var texture = new Texture2D(64, 88, TextureFormat.RGBA32, false);
            texture.SetPixels(Enumerable.Repeat(Color.red, 64 * 88).ToArray());
            texture.Apply();
            System.IO.File.WriteAllBytes(System.IO.Path.Combine(source, "7-courage.png"), ImageConversion.EncodeToPNG(texture));
            Object.Destroy(texture);

            MainSceneTests.FilePicker = new FakePicker { Folder = source };
            try
            {
                yield return Home(Serve());
                var shipped = AsText(CardArtLibrary.ForCard(7));
                Assert.AreNotEqual("Art pack image", shipped);

                Object.FindAnyObjectByType<ScreenRouter>().Show<SettingsScreen>();
                yield return MainSceneTests.WaitFor<SettingsScreen>();
                Assert.IsNotNull(PhaseFiveSceneTests.Child("Art: Default"), "the shipped art is always there to pick");
                StringAssert.Contains("in use", PhaseFiveSceneTests.Child("Art: Default").GetComponentInChildren<Text>().text);

                PhaseFiveSceneTests.ButtonNamed("Add art pack folder").onClick.Invoke();
                var waited = 0f;
                while (PhaseFiveSceneTests.Child("Art: Test Pack") == null && waited < 6f)
                {
                    waited += Time.unscaledDeltaTime;
                    yield return null;
                }

                Assert.IsNotNull(PhaseFiveSceneTests.Child("Art: Test Pack"), "the pack is an entry beside Default");
                StringAssert.Contains("Added Test Pack", MainSceneTests.Screen<SettingsScreen>().StatusText);
                Assert.IsNull(AppServices.Device.ArtPack, "adding doesn't switch to it");
                Assert.AreEqual(shipped, AsText(CardArtLibrary.ForCard(7)));

                PhaseFiveSceneTests.ButtonNamed("Art: Test Pack").onClick.Invoke();
                yield return PhaseTwoSceneTests.Frames(2);
                Assert.AreEqual("test-pack", AppServices.Device.ArtPack);
                Assert.AreEqual("Art pack image", AsText(CardArtLibrary.ForCard(7)), "card 7 now has the pack's art");
                Assert.AreNotEqual("Art pack image", AsText(CardArtLibrary.ForCard(8)), "and the cards it leaves alone keep the shipped art");
                StringAssert.Contains("in use", PhaseFiveSceneTests.Child("Art: Test Pack").GetComponentInChildren<Text>().text);

                PhaseFiveSceneTests.ButtonNamed("Art: Default").onClick.Invoke();
                yield return PhaseTwoSceneTests.Frames(2);
                Assert.IsNull(AppServices.Device.ArtPack);
                Assert.AreEqual(shipped, AsText(CardArtLibrary.ForCard(7)));

                // Removing takes two taps.
                PhaseFiveSceneTests.ButtonNamed("Remove Test Pack").onClick.Invoke();
                yield return PhaseTwoSceneTests.Frames(2);
                Assert.IsNotNull(PhaseFiveSceneTests.Child("Art: Test Pack"), "the first tap only asks");
                PhaseFiveSceneTests.ButtonNamed("Remove Test Pack").onClick.Invoke();
                yield return PhaseTwoSceneTests.Frames(2);
                Assert.IsNull(PhaseFiveSceneTests.Child("Art: Test Pack"));
                Assert.IsEmpty(AppServices.ArtPacks.List());
            }
            finally
            {
                MainSceneTests.FilePicker = null;
                CardArtLibrary.ActivePackId = null;
                var parent = System.IO.Path.GetDirectoryName(source);
                if (System.IO.Directory.Exists(parent))
                {
                    System.IO.Directory.Delete(parent, recursive: true);
                }
            }
        }

        [UnityTest]
        public IEnumerator AnArtPackInUse_IsRememberedAcrossARestart_AndADeletedOneFallsBackToDefault()
        {
            yield return Home(Serve());
            var store = AppServices.ArtPacks;
            var folder = System.IO.Path.Combine(store.Root, "..", "src-" + System.Guid.NewGuid().ToString("N"));
            System.IO.Directory.CreateDirectory(folder);
            var texture = new Texture2D(40, 56, TextureFormat.RGBA32, false);
            System.IO.File.WriteAllBytes(System.IO.Path.Combine(folder, "3-charity.png"), ImageConversion.EncodeToPNG(texture));
            Object.Destroy(texture);
            var pack = store.AddFolder(folder, "Kept").Pack;
            AppServices.Device.ArtPack = pack.Id;

            // Start the services again over the same storage, as a new run of the app would.
            var root = store.Root;
            AppServices.Override(AppServices.Api, null, null, null, artPacksFolder: root);
            Assert.AreEqual(root, AppServices.ArtPacks.Root);
            CardArtLibrary.ActivePackId = AppServices.ArtPacks.Find(AppServices.Device.ArtPack)?.Id;

            store.Delete(pack.Id);
            Assert.IsNull(store.Find(pack.Id));
            CardArtLibrary.Forget(pack.Id);
            Assert.IsNull(CardArtLibrary.ActivePackId, "the pack is gone, so it is the default again");
            System.IO.Directory.Delete(folder, recursive: true);
        }

        [UnityTest]
        public IEnumerator OpeningAnotherGame_DoesNotLeaveTheLastOnesTextUnderLoading()
        {
            var server = Serve();
            server.State = Quiet(405, s => { });
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            Assert.IsNotEmpty(PhaseFiveSceneTests.Board().RoundText, "the game that was up has its round line");

            // The next game's state hasn't arrived yet when its board is shown (the server is slow to answer).
            MainSceneTests.ResponseDelayMilliseconds = 1500;
            try
            {
                Object.FindAnyObjectByType<ScreenRouter>().Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, 406), addToHistory: false);
                yield return PhaseTwoSceneTests.Frames(3);

                var board = PhaseFiveSceneTests.Board();
                Assert.IsTrue(Texts().Contains("Loading the game..."));
                Assert.IsEmpty(board.RoundText, "so nothing of the last game is drawn under the loading message");
                Assert.IsEmpty(board.BannerText);
                Assert.IsFalse(board.Draft.IsShown);
                Assert.IsNull(PhaseFiveSceneTests.Child("Card Laziness"), "or its cards");

                // Let the slow answer arrive while this test's board is still there.
                yield return new WaitForSeconds(1.8f);
            }
            finally
            {
                MainSceneTests.ResponseDelayMilliseconds = 0;
            }
        }

        [UnityTest]
        public IEnumerator ALongScreenName_IsSetSmallerBeforeTheScoreboardGrows_AndIsNotBrokenInTheMiddleOfAWord()
        {
            foreach (var username in new[] { "TheAngryRedBird", "AVeryLongScreenNameIndeedXYZ" })
            {
                var server = Serve();
                server.State = Quiet(405, s =>
                {
                    var opponent = s["players"].First(p => (string)p["username"] != "bshaftoe");
                    opponent["username"] = username;
                    opponent["is_bot"] = true;
                });
                yield return PhaseFiveSceneTests.OpenBoard(server, 405);
                yield return PhaseTwoSceneTests.Frames(4);

                var info = (RectTransform)PhaseFiveSceneTests.Child("Info " + username);
                Assert.IsNotNull(info);
                var name = info.GetComponentsInChildren<Text>().First(t => t.gameObject.name == "Name");
                var generator = name.cachedTextGenerator;
                Assert.Greater(generator.lineCount, 0);
                Assert.IsTrue(generator.lineCount == 1 || generator.lines[1].startCharIdx >= username.Length,
                    username + " stays whole on its first line, not cut mid-word over " + generator.lineCount + " lines");
                var grown = info.anchorMax.x - info.anchorMin.x > 0.165f + 0.0001f;
                if (username == "TheAngryRedBird")
                {
                    Assert.Less(name.fontSize, 22, "shrunk first");
                    Assert.GreaterOrEqual(name.fontSize, 15);
                    Assert.IsFalse(grown, "and the box is left as it was, since the smaller name fits");
                }
                else
                {
                    Assert.AreEqual(15, name.fontSize, "as small as it goes");
                    Assert.IsTrue(grown, "so now the box grows");
                    Assert.LessOrEqual(info.anchorMax.x, 1f);
                }

                ScreenshotHelper.Capture("long-screen-name-" + username.Length);
            }
        }

        [UnityTest]
        public IEnumerator CreativityCopyingShock_AsksForShocksTargets()
        {
            var server = Serve();
            server.State = Quiet(406, s =>
            {
                var hand = s["you"]["hand"][0];
                hand["name"] = "Creativity";
                hand["effect_key"] = "creativity";
                hand["choice_fields"] = Newtonsoft.Json.Linq.JArray.Parse(
                    "[{\"key\":\"copy_card_id\",\"type\":\"mood\",\"scope\":\"any\",\"required\":false,\"label\":\"A mood to copy\"}]");
                var ambition = (int)s["in_play"][0]["card_id"];
                var frustration = (int)s["in_play"][1]["card_id"];
                var shock = "{\"extra_fields\":[{\"key\":\"target_mood_ids\",\"type\":\"mood\",\"scope\":\"any\",\"multi\":true,\"required\":false,"
                    + "\"label\":\"Moods to put into the discard pile (value 3 or less, up to 2, one per player)\",\"filter\":{\"max_value\":3},"
                    + "\"count\":{\"max\":2},\"constraint\":{\"type\":\"distinct_owners\"},\"includes_self\":true}],\"cost_payable\":true}";
                hand["copy_simulation"] = Newtonsoft.Json.Linq.JObject.Parse("{\"" + ambition + "\":" + shock + ",\"" + frustration + "\":" + shock + "}");
            });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card Creativity").GetComponent<UnityEngine.UI.Button>());
            Assert.IsTrue(PhaseFiveSceneTests.Board().Choices.IsOpen);

            // Copy a mood: its own questions appear.
            var option = Object.FindObjectsByType<UnityEngine.UI.Button>(FindObjectsInactive.Exclude).First(b => b.name.StartsWith("Option Ambition"));
            yield return PhaseFiveSceneTests.Tap(option);
            yield return PhaseTwoSceneTests.Frames(3);

            var texts = Texts();
            Assert.IsTrue(texts.Any(t => t.StartsWith("Moods to put into the discard pile")), "the copied Shock's target question is asked: " + string.Join(" | ", texts));
            Assert.IsTrue(Object.FindObjectsByType<UnityEngine.UI.Button>(FindObjectsInactive.Exclude).Any(b => b.name.Contains("[self]")), "and the copy itself can be named");
        }

        [UnityTest]
        public IEnumerator ACreativityCopyInPlay_IsMarkedAsACopy_OnTheCardAndInItsDetail()
        {
            var server = Serve();
            server.State = Quiet(406, s => s["in_play"][0]["is_creativity_copy"] = true);
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            var name = (string)server.State["in_play"][0]["name"];
            var card = PhaseFiveSceneTests.Child("Card " + name);
            Assert.IsNotNull(card.Find("Copy badge"), "a pill on the card");
            Assert.AreEqual("COPY", card.Find("Copy badge").GetComponentInChildren<UnityEngine.UI.Text>().text);
            var other = (string)server.State["in_play"][1]["name"];
            Assert.IsNull(PhaseFiveSceneTests.Child("Card " + other).Find("Copy badge"), "and on no other");

            card.GetComponent<UnityEngine.UI.Button>().onClick.Invoke();
            yield return PhaseTwoSceneTests.Frames(2);
            StringAssert.Contains("A copy made by Creativity, playing as " + name, PhaseFiveSceneTests.Board().DetailText);
            ScreenshotHelper.Capture("creativity-copy");
        }

        private static Newtonsoft.Json.Linq.JObject FinishedGame(bool youMadeIt) => Quiet(405, s =>
        {
            s["game"]["status"] = "completed";
            s["game"]["winner_usernames"] = Newtonsoft.Json.Linq.JArray.Parse("[\"bshaftoe\"]");
            s["game"]["created_by_user_id"] = youMadeIt ? 2 : 18;
            s["round"]["pending_decision"] = null;
        });

        [UnityTest]
        public IEnumerator AFinishedGame_OffersTheLobbyAndARematch_InTheCenter()
        {
            var server = Serve();
            server.State = FinishedGame(youMadeIt: true);
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsNotNull(PhaseFiveSceneTests.Child("Lobby action"), "a way back");
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Rematch action"), "you made it, so you can rematch it");
            Assert.IsNull(PhaseFiveSceneTests.Child("Primary action"), "and there is no Pass or Next game");
            ScreenshotHelper.Capture("game-over-buttons");

            PhaseFiveSceneTests.ButtonNamed("Rematch action").onClick.Invoke();
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.IsInstanceOf<NewGameScreen>(Object.FindAnyObjectByType<ScreenRouter>().Current, "the new game form, set up like this one");
        }

        [UnityTest]
        public IEnumerator AFinishedGameYouDidNotMake_HasNoRematch_ButStillTheLobby()
        {
            var server = Serve();
            server.State = FinishedGame(youMadeIt: false);
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsNotNull(PhaseFiveSceneTests.Child("Lobby action"));
            Assert.IsNull(PhaseFiveSceneTests.Child("Rematch action"));

            PhaseFiveSceneTests.ButtonNamed("Lobby action").onClick.Invoke();
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.IsInstanceOf<HomeScreen>(Object.FindAnyObjectByType<ScreenRouter>().Current, "back to where the game was opened from");
        }

        [UnityTest]
        public IEnumerator AGameStillOn_HasNeither()
        {
            var server = Serve();
            server.State = Quiet(405, s => { });
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsNull(PhaseFiveSceneTests.Child("Lobby action"));
            Assert.IsNull(PhaseFiveSceneTests.Child("Rematch action"));
        }

        private static int StateReads() { lock (MainSceneTests.Issued) { return MainSceneTests.Issued.Count(u => u.Contains("/games/state")); } }

        [UnityTest]
        public IEnumerator TheBoardIsNotReadAgain_WhileOneOfYourMovesIsOnItsWay()
        {
            var server = Serve();
            server.State = Quiet(406, s => { });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            // The server is slow to answer your move: longer than the board's reading interval.
            MainSceneTests.ResponseDelayMilliseconds = 5500;
            try
            {
                var before = StateReads();
                PhaseFiveSceneTests.ButtonNamed("Primary action").onClick.Invoke();
                yield return new WaitForSeconds(4.6f);

                Assert.AreEqual(before, StateReads(), "no second read of the board while the move is still being made");

                yield return new WaitForSeconds(2.5f);
                Assert.Greater(StateReads(), before, "the move reads the board itself once it is done");
            }
            finally
            {
                MainSceneTests.ResponseDelayMilliseconds = 0;
            }
        }

        [UnityTest]
        public IEnumerator OneServerErrorReadingTheBoard_IsNotShown_ButTwoInARowAre()
        {
            var server = Serve();
            server.State = Quiet(406, s => { });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            server.FailStateRequests = 1;
            yield return new WaitForSeconds(3.8f);
            Assert.IsEmpty(PhaseFiveSceneTests.Board().MessageText, "one hiccup is not worth a red message");

            server.FailStateRequests = 2;
            yield return new WaitForSeconds(7.5f);
            StringAssert.Contains("Something went wrong", PhaseFiveSceneTests.Board().MessageText);

            yield return new WaitForSeconds(3.5f);
            Assert.IsEmpty(PhaseFiveSceneTests.Board().MessageText, "and it goes when the board reads fine again");
        }

        [UnityTest]
        public IEnumerator TheAndroidBackButton_GoesBackOneScreenAtATime()
        {
            var server = Serve();
            server.State = Quiet(406, s => { });
            yield return Home(server);
            var router = Object.FindAnyObjectByType<ScreenRouter>();

            // Menu > lobby > a game, then back out again.
            router.Show<PlayScreen>();
            yield return MainSceneTests.WaitFor<PlayScreen>();
            router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, 406));
            yield return MainSceneTests.WaitFor<BoardScreen>();

            AndroidBack.SimulatedPresses = 1;
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.IsInstanceOf<PlayScreen>(router.Current, "from the game to the lobby");

            AndroidBack.SimulatedPresses = 1;
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.IsInstanceOf<HomeScreen>(router.Current, "from the lobby to the menu");

            // At the top of the app there is nowhere further back (a phone puts the app away; nothing else happens).
            AndroidBack.SimulatedPresses = 1;
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.IsInstanceOf<HomeScreen>(router.Current);
            Assert.AreEqual(0, AndroidBack.SimulatedPresses, "the press was used up");
        }

        [UnityTest]
        public IEnumerator TheAndroidBackButton_ClosesAnOpenMenuBeforeLeavingTheGame()
        {
            var server = Serve();
            server.State = Quiet(406, s => { });
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            yield return PhaseFiveSceneTests.OpenMenu();
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Menu overlay"));

            AndroidBack.SimulatedPresses = 1;
            yield return PhaseTwoSceneTests.Frames(3);

            Assert.IsInstanceOf<BoardScreen>(Object.FindAnyObjectByType<ScreenRouter>().Current, "still on the game");
            Assert.IsNull(PhaseFiveSceneTests.Child("Menu overlay"), "with the menu put away");
        }

        [UnityTest]
        public IEnumerator WithAQuestionWaitingOnYou_TheMenuAndResignAreStillInReach()
        {
            var server = Serve();
            server.State = PhaseFiveSceneTests.Load(407);
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);
            Assert.IsTrue(PhaseFiveSceneTests.Board().Choices.IsOpen, "the question is up and can't be called off");
            Assert.IsFalse(PhaseFiveSceneTests.Board().Choices.Cancellable);

            var menuCanvas = PhaseFiveSceneTests.Child("Menu").GetComponent<Canvas>();
            Assert.IsTrue(menuCanvas.overrideSorting, "the menu button is drawn above the question");

            yield return PhaseFiveSceneTests.OpenMenu();
            var resign = PhaseFiveSceneTests.ButtonNamed("Resign");
            Assert.IsNotNull(resign);
            Assert.IsTrue(resign.interactable, "Resign stays enabled");

            yield return PhaseFiveSceneTests.Tap(resign);
            Assert.IsTrue(PhaseFiveSceneTests.Board().Confirm.IsOpen, "and asks first");
            StringAssert.Contains("Resign this game", PhaseFiveSceneTests.Board().Confirm.MessageText);
        }

        [UnityTest]
        public IEnumerator TheWindow_KeepsRunningInTheBackground_UnlessTheSettingIsOff()
        {
            var before = Application.runInBackground;
            try
            {
                yield return Home(Serve());
                Assert.IsTrue(Application.runInBackground, "on by default, so the music goes on when the window isn't in front");

                Object.FindAnyObjectByType<ScreenRouter>().Show<SettingsScreen>();
                yield return MainSceneTests.WaitFor<SettingsScreen>();
                var toggle = PhaseTwoSceneTests.FindToggle("Play sound in the background");
                Assert.IsNotNull(toggle, "offered on a computer");
                Assert.IsTrue(toggle.isOn);

                toggle.isOn = false;
                yield return PhaseTwoSceneTests.Frames(2);
                Assert.IsFalse(AppServices.Device.BackgroundSoundOn);
                Assert.IsFalse(Application.runInBackground, "so the window pauses (and the music with it) when left");

                toggle.isOn = true;
                yield return PhaseTwoSceneTests.Frames(2);
                Assert.IsTrue(Application.runInBackground);
            }
            finally
            {
                Application.runInBackground = before;
            }
        }

        [UnityTest]
        public IEnumerator BoardLayout_Screenshots()
        {
            foreach (var game in new[] { 405, 406, 407 })
            {
                var server = Serve();
                server.State = Quiet(game, s =>
                {
                    if (game == 406)
                    {
                        s["round"]["hurt_feelings_game_player_id"] = s["players"][1]["game_player_id"];
                    }
                });
                yield return PhaseFiveSceneTests.OpenBoard(server, game);
                yield return PhaseTwoSceneTests.Frames(6);
                ScreenshotHelper.Capture("layout-" + game);
            }
        }

        [UnityTest]
        public IEnumerator BoardLayout_InDarkMode_Screenshots()
        {
            MainSceneTests.DarkMode = true;
            try
            {
                foreach (var game in new[] { 405, 406 })
                {
                    var server = Serve();
                    server.State = Quiet(game, s => { });
                    yield return PhaseFiveSceneTests.OpenBoard(server, game);
                    yield return PhaseTwoSceneTests.Frames(6);
                    Assert.IsTrue(AppServices.Theme.IsDark);
                    ScreenshotHelper.Capture("dark-layout-" + game);
                    yield return PhaseFiveSceneTests.OpenMenu();
                    ScreenshotHelper.Capture("dark-menu-" + game);
                }

                yield return Home(Serve());
                ScreenshotHelper.Capture("dark-home");
                Object.FindAnyObjectByType<ScreenRouter>().Show<SettingsScreen>();
                yield return MainSceneTests.WaitFor<SettingsScreen>();
                ScreenshotHelper.Capture("dark-settings");
            }
            finally
            {
                MainSceneTests.DarkMode = false;
                AppServices.Theme.Apply(dark: false);
            }
        }
    }
}
