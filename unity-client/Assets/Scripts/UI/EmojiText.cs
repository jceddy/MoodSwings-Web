using System.Collections.Generic;
using System.Text;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>The emoji images, loaded from Assets/Resources/Emoji once each.</summary>
    public static class EmojiSprites
    {
        private static readonly Dictionary<string, Sprite> Cache = new Dictionary<string, Sprite>();

        /// <summary>Reads the list of emoji the chat knows, once.</summary>
        public static void EnsureLoaded()
        {
            if (EmojiSet.IsLoaded)
            {
                return;
            }

            var listing = Resources.Load<TextAsset>("Emoji/emoji");
            if (listing != null)
            {
                EmojiSet.Load(listing.text);
            }
        }

        public static Sprite For(EmojiEntry emoji)
        {
            if (!Cache.TryGetValue(emoji.Hex, out var sprite) || sprite == null)
            {
                sprite = Resources.Load<Sprite>("Emoji/" + emoji.Hex);
                Cache[emoji.Hex] = sprite;
            }

            return sprite;
        }
    }

    /// <summary>
    /// Text with emoji in it. The text component can't draw an emoji (its font has none, and characters outside the Basic
    /// Multilingual Plane are skipped), so each emoji is replaced by a blank the width of a letter's height, and its image
    /// is laid over the blank once the text has been set out. Used for the chat; set the text with <see cref="SetText"/>.
    /// </summary>
    [RequireComponent(typeof(Text))]
    public sealed class EmojiText : MonoBehaviour
    {
        // An em space and a thin one: a little wider than the text is high, and empty, with room for the image between its words.
        private const string Blank = "  ";
        private const float SizeOverFont = 1.1f;

        private Text _text;
        private readonly List<EmojiEntry> _entries = new List<EmojiEntry>();
        private readonly List<int> _positions = new List<int>();
        private readonly List<Image> _images = new List<Image>();
        private bool _dirty;
        private string _placedFor;
        private Vector2 _placedSize;
        private int _placedFontSize;

        /// <summary>What was asked to be shown, emoji and all.</summary>
        public string Raw { get; private set; } = string.Empty;

        public int EmojiCount => _entries.Count;

        /// <summary>The emoji images that are showing now.</summary>
        public int ImagesShown
        {
            get
            {
                var shown = 0;
                foreach (var image in _images)
                {
                    if (image != null && image.gameObject.activeSelf)
                    {
                        shown++;
                    }
                }

                return shown;
            }
        }

        public IReadOnlyList<Image> Images => _images;

        private void Awake() => Prepare();

        private void Prepare()
        {
            if (_text != null)
            {
                return;
            }

            _text = GetComponent<Text>();
            // Characters are counted one for one: no tags to skip over.
            _text.supportRichText = false;
        }

        private void OnEnable() => Canvas.willRenderCanvases += Place;

        private void OnDisable() => Canvas.willRenderCanvases -= Place;

        public void SetText(string raw)
        {
            Prepare();
            EmojiSprites.EnsureLoaded();
            Raw = raw ?? string.Empty;
            _entries.Clear();
            _positions.Clear();

            var shown = new StringBuilder();
            foreach (var segment in EmojiSet.Split(Raw))
            {
                if (segment.Emoji == null)
                {
                    shown.Append(segment.Text);
                    continue;
                }

                _positions.Add(shown.Length);
                _entries.Add(segment.Emoji);
                shown.Append(Blank);
            }

            _text.text = shown.ToString();
            _dirty = true;
        }

        // After the canvas has laid the text out (this runs after its own rebuild), the images go where their blanks are.
        private void Place()
        {
            if (_text == null)
            {
                return;
            }

            if (_entries.Count == 0)
            {
                HideFrom(0);
                return;
            }

            var size = _text.rectTransform.rect.size;
            if (!_dirty && _placedFor == _text.text && size == _placedSize && _text.fontSize == _placedFontSize)
            {
                return;
            }

            var generator = _text.cachedTextGenerator;
            var characters = generator.characters;
            var lines = generator.lines;
            if (characters.Count == 0 || lines.Count == 0)
            {
                return;
            }

            var pixels = Mathf.Max(0.0001f, _text.pixelsPerUnit);
            var side = _text.fontSize * SizeOverFont;
            for (var i = 0; i < _entries.Count; i++)
            {
                var index = _positions[i];
                if (index >= characters.Count)
                {
                    HideFrom(i);
                    break;
                }


                var info = characters[index];
                var width = 0f;
                for (var c = 0; c < Blank.Length && index + c < characters.Count; c++)
                {
                    width += characters[index + c].charWidth;
                }

                var line = lines[0];
                for (var l = 0; l < lines.Count && lines[l].startCharIdx <= index; l++)
                {
                    line = lines[l];
                }

                var image = ImageAt(i);
                var rect = image.rectTransform;
                rect.sizeDelta = new Vector2(side, side);
                // The text's own coordinates run from its pivot, in pixels: the middle of the blank, the middle of its line.
                rect.anchorMin = rect.anchorMax = _text.rectTransform.pivot;
                rect.anchoredPosition = new Vector2(
                    (info.cursorPos.x + width * 0.5f) / pixels,
                    (line.topY - line.height * 0.5f) / pixels);
                image.sprite = EmojiSprites.For(_entries[i]);
                image.gameObject.SetActive(image.sprite != null);
            }

            HideFrom(_entries.Count);
            _dirty = false;
            _placedFor = _text.text;
            _placedSize = size;
            _placedFontSize = _text.fontSize;
        }

        private Image ImageAt(int index)
        {
            while (_images.Count <= index)
            {
                var holder = UiFactory.Create("Emoji", transform);
                var image = holder.gameObject.AddComponent<Image>();
                image.raycastTarget = false;
                image.preserveAspect = true;
                _images.Add(image);
            }

            return _images[index];
        }

        private void HideFrom(int index)
        {
            for (var i = index; i < _images.Count; i++)
            {
                if (_images[i] != null)
                {
                    _images[i].gameObject.SetActive(false);
                }
            }
        }
    }
}
