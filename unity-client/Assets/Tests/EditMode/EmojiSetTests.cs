using System.IO;
using System.Linq;
using MoodSwings.Core;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    /// <summary>Finding emoji in chat messages, and the quick chat buttons.</summary>
    public class EmojiSetTests
    {
        private const string Listing =
            "# a comment\n1f480\tskull\tFaces\n1f44d\tthumbsup\tHands\n2764\theart\tHearts\n1f5ff\tmoai\tThings\n";

        [SetUp]
        public void SetUp() => EmojiSet.Load(Listing);

        [TearDown]
        public void TearDown() => EmojiSet.Load(File.ReadAllText(ListingPath()));

        private static string ListingPath() => Path.Combine(Application.dataPath, "Resources", "Emoji", "emoji.txt");

        private static string Kinds(string text) =>
            string.Join("|", EmojiSet.Split(text).Select(s => s.Emoji != null ? "<" + s.Emoji.Name + ">" : s.Text));

        [Test]
        public void TheListing_IsReadWithItsCommentsLeftOut()
        {
            CollectionAssert.AreEqual(new[] { "skull", "thumbsup", "heart", "moai" }, EmojiSet.All.Select(e => e.Name).ToArray());
            Assert.AreEqual("Hands", EmojiSet.ByName("thumbsup").Group);
            Assert.AreEqual(":skull:", EmojiSet.Find("1f480").Shortcode);
            Assert.AreEqual("\U0001F480", EmojiSet.Find("1f480").Text);
        }

        [Test]
        public void TextIsSplitIntoWordsAndEmoji()
        {
            Assert.AreEqual("GG |<skull>| nice|<moai>", Kinds("GG \U0001F480 nice\U0001F5FF"));
            Assert.AreEqual("", Kinds(""));
            Assert.AreEqual("just words", Kinds("just words"));
        }

        [Test]
        public void AnEmojiPresentationSelector_AndASkinTone_DoNotChangeWhichEmojiItIs()
        {
            Assert.AreEqual("<heart>", Kinds("\u2764\uFE0F"));
            Assert.AreEqual("<heart>", Kinds("\u2764"));
            Assert.AreEqual("<thumbsup>", Kinds("\U0001F44D\U0001F3FD"));
        }

        [Test]
        public void ASymbolTheFontCanDraw_StaysAsText_AndAStraySelectorIsDropped()
        {
            Assert.AreEqual("\u263A", Kinds("\u263A\uFE0F"));
        }

        [Test]
        public void AnEmojiWithNoImage_IsLeftOut_NotShownAsAnEmptyBox()
        {
            Assert.AreEqual("a  b", Kinds("a \U0001F9FF b"));
            Assert.AreEqual("", Kinds("\U0001F468\u200D\U0001F469\u200D\U0001F467"), "a family: none of it is in the list");
        }

        [Test]
        public void Shortcodes_BecomeTheEmojiTheyName_AndOtherColonsAreLeftAlone()
        {
            Assert.AreEqual("\U0001F480 gg \U0001F5FF", EmojiSet.ExpandShortcodes(":skull: gg :moai:"));
            Assert.AreEqual("12:30 :nope: and :skull", EmojiSet.ExpandShortcodes("12:30 :nope: and :skull"));
            Assert.AreEqual("", EmojiSet.ExpandShortcodes(""));
            Assert.IsNull(EmojiSet.ExpandShortcodes(null));
        }

        [Test]
        public void ACodeWithSeveralParts_IsTurnedIntoItsText()
        {
            Assert.AreEqual("\U0001F468\u200D\U0001F469", EmojiSet.TextOf("1f468-200d-1f469"));
        }

        [Test]
        public void QuickChat_IsTheWebClientsSet()
        {
            var sent = QuickChat.Messages.Select(m => m.Text).ToArray();

            CollectionAssert.AreEqual(new[] { "GL;HF", "GG", "\U0001F480", "\U0001F5FF", "\U0001F921", "\U0001F62D", "\U0001F451" }, sent);
            Assert.AreEqual("GL;HF", QuickChat.Messages[0].Label);
            Assert.IsNull(QuickChat.Messages[0].EmojiHex);
            Assert.AreEqual("1f480", QuickChat.Messages[2].EmojiHex);
        }

        [Test]
        public void TheRealList_HasAnImageForEveryEmoji_IncludingTheQuickChatOnes()
        {
            EmojiSet.Load(File.ReadAllText(ListingPath()));
            var folder = Path.GetDirectoryName(ListingPath());

            Assert.Greater(EmojiSet.All.Count, 50);
            foreach (var emoji in EmojiSet.All)
            {
                Assert.IsTrue(File.Exists(Path.Combine(folder, emoji.Hex + ".png")), emoji.Name + " has no image");
            }

            foreach (var message in QuickChat.Messages.Where(m => m.EmojiHex != null))
            {
                Assert.IsNotNull(EmojiSet.Find(message.EmojiHex), message.EmojiHex + " is a quick chat button but not in the list");
            }

            Assert.AreEqual(EmojiSet.All.Count, EmojiSet.All.Select(e => e.Name).Distinct().Count(), "shortcodes are unique");
        }
    }
}
