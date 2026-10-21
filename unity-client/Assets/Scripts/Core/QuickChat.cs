using System.Collections.Generic;

namespace MoodSwings.Core
{
    /// <summary>One canned chat message: a button that sends it at once.</summary>
    public sealed class QuickChatMessage
    {
        /// <summary>What the button says (for an emoji, empty: it shows the image).</summary>
        public string Label { get; set; }

        /// <summary>What is sent: the words, or the emoji itself.</summary>
        public string Text { get; set; }

        /// <summary>For an emoji button, which one (the name of its image).</summary>
        public string EmojiHex { get; set; }
    }

    /// <summary>The quick chat buttons, the same ones the web client has: GL;HF, GG and five emoji.</summary>
    public static class QuickChat
    {
        public static readonly IReadOnlyList<QuickChatMessage> Messages = new List<QuickChatMessage>
        {
            new QuickChatMessage { Label = "GL;HF", Text = "GL;HF" },
            new QuickChatMessage { Label = "GG", Text = "GG" },
            Emoji("1f480"), // skull
            Emoji("1f5ff"), // moai
            Emoji("1f921"), // clown
            Emoji("1f62d"), // sob
            Emoji("1f451"), // crown
        };

        private static QuickChatMessage Emoji(string hex) =>
            new QuickChatMessage { Label = string.Empty, Text = EmojiSet.TextOf(hex), EmojiHex = hex };
    }
}
