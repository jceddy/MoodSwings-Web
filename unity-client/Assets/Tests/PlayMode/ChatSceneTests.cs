using System.Collections;
using System.Linq;
using MoodSwings.UI;
using Newtonsoft.Json.Linq;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>The chat in the board's menu: quick buttons, emoji, and what other people say.</summary>
    public class ChatSceneTests
    {
        private static IEnumerator OpenChat(PhaseFiveSceneTests.PlayServer server, int game = 406)
        {
            yield return PhaseFiveSceneTests.OpenBoard(server, game);
            yield return PhaseFiveSceneTests.OpenMenu();
            yield return PhaseFiveSceneTests.Tap(PhaseTwoSceneTests.FindButton("Chat"));
        }

        private static InputField Box() => PhaseFiveSceneTests.Child("ChatEntry").GetComponentInChildren<InputField>();

        private static string Posted(PhaseFiveSceneTests.PlayServer server, int index = 0) =>
            (string)server.Posts("/games/chat").ElementAt(index)["message_text"];

        [UnityTest]
        public IEnumerator TheQuickButtons_AreThere_LikeOnTheWeb()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(406) };
            yield return OpenChat(server);

            foreach (var name in new[] { "Quick GL;HF", "Quick GG", "Quick 1f480", "Quick 1f5ff", "Quick 1f921", "Quick 1f62d", "Quick 1f451" })
            {
                Assert.IsNotNull(PhaseFiveSceneTests.ButtonNamed(name), name);
            }

            // An emoji button shows its picture.
            var skull = PhaseFiveSceneTests.ButtonNamed("Quick 1f480");
            Assert.IsNotNull(skull.transform.Find("Emoji skull").GetComponent<Image>().sprite);
            ScreenshotHelper.Capture("chat-quick-buttons");
        }

        [UnityTest]
        public IEnumerator ATextQuickButton_SendsAtOnce_AndLeavesWhatIsInTheBox()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(406) };
            yield return OpenChat(server);
            Box().text = "half a thought";

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Quick GG"));

            Assert.AreEqual(1, server.Posts("/games/chat").Count());
            Assert.AreEqual("GG", Posted(server));
            Assert.AreEqual("table", (string)server.Posts("/games/chat").Single()["channel"]);
            Assert.AreEqual("half a thought", Box().text, "a quick message is not what was being typed");
        }

        [UnityTest]
        public IEnumerator AnEmojiQuickButton_SendsTheEmojiItself()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(406) };
            yield return OpenChat(server);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Quick 1f480"));
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Quick 1f451"));

            Assert.AreEqual("\U0001F480", Posted(server, 0));
            Assert.AreEqual("\U0001F451", Posted(server, 1));
        }

        [UnityTest]
        public IEnumerator ThePicker_PutsShortcodesInTheBox_WhichGoOutAsTheEmoji()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(406) };
            yield return OpenChat(server);
            Assert.IsNull(PhaseFiveSceneTests.Child("Emoji picker"), "closed until asked for");

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Emoji picker button"));
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Emoji picker"));
            ScreenshotHelper.Capture("chat-emoji-picker");

            Box().text = "nice ";
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Pick skull"));
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Pick fire"));
            Assert.AreEqual("nice :skull::fire:", Box().text);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Send chat"));

            Assert.AreEqual("nice \U0001F480\U0001F525", Posted(server));
            Assert.AreEqual(string.Empty, Box().text);
            Assert.IsNull(PhaseFiveSceneTests.Child("Emoji picker"), "put away once it has done its job");
        }

        [UnityTest]
        public IEnumerator Emoji_InWhatOthersSay_AreDrawnAsImages()
        {
            var server = new PhaseFiveSceneTests.PlayServer
            {
                State = PhaseFiveSceneTests.Load(406, s => s["chat_messages"] = JArray.Parse(
                    "[{\"id\":1,\"sender_username\":\"BotSage\",\"message_text\":\"GG \\ud83d\\udc80 nice \\ud83d\\udc51\",\"created_at\":\"2026-01-01 00:00:00\"}]")),
            };
            yield return OpenChat(server);
            yield return PhaseTwoSceneTests.Frames(6);

            var emoji = Object.FindObjectsByType<EmojiText>(FindObjectsInactive.Exclude).Single();
            Assert.AreEqual(2, emoji.EmojiCount);
            Assert.AreEqual(2, emoji.ImagesShown);
            StringAssert.StartsWith("BotSage: GG", emoji.GetComponent<Text>().text);
            StringAssert.DoesNotContain("\U0001F480", emoji.GetComponent<Text>().text);
            ScreenshotHelper.Capture("chat-with-emoji");
        }

        [UnityTest]
        public IEnumerator ASpectator_HasNoQuickButtons()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(406, s => s["you"]["game_player_id"] = null) };
            yield return OpenChat(server);

            Assert.IsNull(PhaseFiveSceneTests.Child("ChatQuick"));
            Assert.IsNull(PhaseFiveSceneTests.Child("Emoji picker button"));
        }
    }
}
