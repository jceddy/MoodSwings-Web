using System.Collections;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.UI;
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
    }
}
