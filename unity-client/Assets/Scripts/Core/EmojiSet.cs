using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;

namespace MoodSwings.Core
{
    /// <summary>One emoji the chat can draw, and what it is called.</summary>
    public sealed class EmojiEntry
    {
        /// <summary>The code points joined by '-' in lower-case hex, like "1f480" -- also the name of its image.</summary>
        public string Hex { get; set; }

        /// <summary>The shortcode without the colons: "skull".</summary>
        public string Name { get; set; }

        public string Group { get; set; }

        /// <summary>The emoji as text, as it goes to the server and the other players.</summary>
        public string Text { get; set; }

        public string Shortcode => ":" + Name + ":";
    }

    /// <summary>A stretch of a message: plain text, or one emoji.</summary>
    public sealed class EmojiSegment
    {
        public string Text { get; set; }

        /// <summary>Set when this stretch is one emoji the chat has an image for.</summary>
        public EmojiEntry Emoji { get; set; }
    }

    /// <summary>
    /// The emoji the chat knows (the list in Assets/Resources/Emoji/emoji.txt, made by tools/fetch_emoji.py), and finding
    /// them in text. UI-free. The app's text can't draw emoji, so a message is split into its words and its emoji, and each
    /// emoji is drawn as an image; any emoji it has no image for is left out rather than shown as an empty box.
    /// </summary>
    public static class EmojiSet
    {
        private static readonly Regex Shortcodes = new Regex(@":([a-z0-9_]+):", RegexOptions.Compiled);

        private static List<EmojiEntry> _all = new List<EmojiEntry>();
        private static Dictionary<string, EmojiEntry> _byHex = new Dictionary<string, EmojiEntry>();
        private static Dictionary<string, EmojiEntry> _byName = new Dictionary<string, EmojiEntry>();

        public static IReadOnlyList<EmojiEntry> All => _all;

        public static bool IsLoaded => _all.Count > 0;

        /// <summary>Reads the list (hex, shortcode and group on each line, tab-separated; lines starting with # are comments).</summary>
        public static void Load(string listing)
        {
            var all = new List<EmojiEntry>();
            foreach (var raw in (listing ?? string.Empty).Split('\n'))
            {
                var line = raw.Trim();
                if (line.Length == 0 || line[0] == '#')
                {
                    continue;
                }

                var parts = line.Split('\t');
                if (parts.Length < 2)
                {
                    continue;
                }

                all.Add(new EmojiEntry
                {
                    Hex = parts[0].Trim().ToLowerInvariant(),
                    Name = parts[1].Trim(),
                    Group = parts.Length > 2 ? parts[2].Trim() : string.Empty,
                    Text = TextOf(parts[0].Trim()),
                });
            }

            _all = all;
            _byHex = all.GroupBy(e => e.Hex).ToDictionary(g => g.Key, g => g.First());
            _byName = all.GroupBy(e => e.Name).ToDictionary(g => g.Key, g => g.First());
        }

        public static EmojiEntry Find(string hex) => hex != null && _byHex.TryGetValue(hex, out var entry) ? entry : null;

        public static EmojiEntry ByName(string name) => name != null && _byName.TryGetValue(name, out var entry) ? entry : null;

        /// <summary>The characters for a code like "1f468-200d-1f469".</summary>
        public static string TextOf(string hex)
        {
            var text = new StringBuilder();
            foreach (var part in hex.Split('-'))
            {
                if (int.TryParse(part, System.Globalization.NumberStyles.HexNumber, null, out var codePoint))
                {
                    text.Append(char.ConvertFromUtf32(codePoint));
                }
            }

            return text.ToString();
        }

        /// <summary>
        /// ":skull:" becomes the emoji itself, for what is sent to the server (the picker puts shortcodes in the box, since
        /// the box can't show an emoji either). A shortcode that isn't one of ours is left as it was typed.
        /// </summary>
        public static string ExpandShortcodes(string text) =>
            string.IsNullOrEmpty(text)
                ? text
                : Shortcodes.Replace(text, match => ByName(match.Groups[1].Value)?.Text ?? match.Value);

        /// <summary>Splits a message into its plain text and the emoji in it.</summary>
        public static List<EmojiSegment> Split(string text)
        {
            var segments = new List<EmojiSegment>();
            if (string.IsNullOrEmpty(text))
            {
                return segments;
            }

            var plain = new StringBuilder();
            void FlushPlain()
            {
                if (plain.Length > 0)
                {
                    segments.Add(new EmojiSegment { Text = plain.ToString() });
                    plain.Clear();
                }
            }

            var i = 0;
            while (i < text.Length)
            {
                var start = i;
                var codePoint = ReadCodePoint(text, ref i);

                // Invisible on their own: the emoji-presentation selector and the joiner.
                if (codePoint == 0xFE0F || codePoint == 0x200D)
                {
                    continue;
                }

                if (!StartsEmoji(codePoint))
                {
                    plain.Append(text, start, i - start);
                    continue;
                }

                // One emoji: its code point, an emoji-presentation selector, a skin tone, and any that are joined to it.
                var parts = new List<int> { codePoint };
                var scan = i;
                while (scan < text.Length)
                {
                    var next = scan;
                    var following = ReadCodePoint(text, ref next);
                    if (following == 0xFE0F || (following >= 0x1F3FB && following <= 0x1F3FF))
                    {
                        scan = next;
                    }
                    else if (following == 0x200D && next < text.Length)
                    {
                        var joined = ReadCodePoint(text, ref next);
                        parts.Add(0x200D);
                        parts.Add(joined);
                        scan = next;
                    }
                    else
                    {
                        break;
                    }
                }

                i = scan;
                var hex = string.Join("-", parts.Where(p => p != 0xFE0F).Select(p => p.ToString("x")));
                var entry = Find(hex) ?? Find(parts[0].ToString("x"));
                if (entry != null)
                {
                    FlushPlain();
                    segments.Add(new EmojiSegment { Text = text.Substring(start, i - start), Emoji = entry });
                }
                else if (codePoint < 0x10000)
                {
                    // A symbol the font can draw by itself (a heart, a smiley), so it stays as text.
                    plain.Append(text, start, i - start);
                }
                // else: an emoji with no image: left out.
            }

            FlushPlain();
            return segments;
        }

        // Which characters begin an emoji: the ones in the list, and the supplementary-plane pictographs the font can't
        // draw (which are dropped when there is no image for them).
        private static bool StartsEmoji(int codePoint) =>
            codePoint >= 0x1F000 || _byHex.ContainsKey(codePoint.ToString("x"));

        private static int ReadCodePoint(string text, ref int index)
        {
            if (char.IsHighSurrogate(text[index]) && index + 1 < text.Length && char.IsLowSurrogate(text[index + 1]))
            {
                var codePoint = char.ConvertToUtf32(text[index], text[index + 1]);
                index += 2;
                return codePoint;
            }

            return text[index++];
        }
    }
}
