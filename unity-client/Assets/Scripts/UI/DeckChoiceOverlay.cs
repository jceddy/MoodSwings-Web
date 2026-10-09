using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// A box over a screen to pick one saved deck from -- yours, then each friend's shared ones. Asked with
    /// <see cref="AskAsync"/>, which finishes with the deck chosen, or null if it's cancelled (Back counts too).
    /// </summary>
    public sealed class DeckChoiceOverlay
    {
        private readonly UiTheme _theme;
        private readonly GameObject _root;
        private readonly Text _title;
        private readonly Text _note;
        private readonly RectTransform _content;
        private TaskCompletionSource<DecklistSummary> _answer;

        public DeckChoiceOverlay(Transform parent, UiTheme theme)
        {
            _theme = theme;
            var root = UiFactory.Create("Deck choice overlay", parent);
            _root = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.82f);

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = new Vector2(0.5f, 0.1f);
            panel.anchorMax = new Vector2(0.5f, 0.9f);
            panel.pivot = new Vector2(0.5f, 0.5f);
            panel.offsetMin = new Vector2(-460f, 0f);
            panel.offsetMax = new Vector2(460f, 0f);
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(32, 32, 28, 28);
            layout.spacing = 14f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _title = UiFactory.Label(panel, "Choose a deck", 38, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            UiFactory.Size(_title.gameObject, height: 56f);
            _note = UiFactory.Label(panel, string.Empty, 24, theme.textMuted, TextAnchor.MiddleCenter);
            UiFactory.Size(_note.gameObject, height: 40f);

            var scroll = UiFactory.ScrollList(panel, out _content, spacing: 8f);
            UiFactory.Flexible(scroll.gameObject, height: 1f);

            var cancel = UiFactory.Button(panel, "Cancel", theme, () => Answer(null), primary: false);
            cancel.gameObject.name = "Cancel deck choice";
            UiFactory.Size(cancel.gameObject, 320f);

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public string NoteText => _note.text;

        /// <summary>The decks offered, in the order listed.</summary>
        public IReadOnlyList<string> DeckNames => _content.GetComponentsInChildren<Button>(true)
            .Where(b => b.name.StartsWith("Choose "))
            .Select(b => b.name.Substring("Choose ".Length))
            .ToList();

        /// <summary>Shows the decks (refreshing the list first when it hasn't been fetched) and waits for a choice.</summary>
        public async Task<DecklistSummary> AskAsync(string title, string note)
        {
            _answer?.TrySetResult(null);
            _answer = new TaskCompletionSource<DecklistSummary>();
            _title.text = title;
            Fill(note);
            _root.SetActive(true);

            if (!AppServices.Decklists.Loaded)
            {
                await AppServices.Decklists.RefreshAsync();
                if (_root != null && _root.activeSelf)
                {
                    Fill(note);
                }
            }

            return await _answer.Task;
        }

        private void Fill(string note)
        {
            foreach (Transform child in _content)
            {
                child.gameObject.SetActive(false);
                Object.Destroy(child.gameObject);
            }

            var decks = AppServices.Decklists;
            if (!decks.Loaded)
            {
                _note.text = "Loading your decks...";
                return;
            }

            var all = decks.Own.Select(d => (deck: d, owner: (string)null))
                .Concat(decks.Friends.SelectMany(f => f.Decklists.Select(d => (deck: d, owner: f.FriendUsername))))
                .ToList();
            _note.text = all.Count == 0 ? "No saved decks yet. Build one under Decklists on the home screen." : note ?? string.Empty;

            foreach (var (deck, owner) in all)
            {
                var chosen = deck;
                var button = UiFactory.Button(_content, string.Empty, _theme, () => Answer(chosen), primary: false);
                button.gameObject.name = "Choose " + deck.Name;
                UiFactory.Size(button.gameObject, height: 84f);
                var label = button.GetComponentInChildren<Text>();
                label.alignment = TextAnchor.MiddleLeft;
                label.fontSize = 26;
                label.rectTransform.offsetMin = new Vector2(24f, 0f);
                label.text = deck.Name + (owner != null ? $"   ({owner}'s)" : string.Empty) + "\n<size=22><color=#9a9ea8>"
                    + DecklistDisplay.Describe(deck) + "</color></size>";
                label.supportRichText = true;
            }
        }

        /// <summary>Back: cancels the choice. True if it was open.</summary>
        public bool Dismiss()
        {
            if (!IsOpen)
            {
                return false;
            }

            Answer(null);
            return true;
        }

        private void Answer(DecklistSummary deck)
        {
            _root.SetActive(false);
            var answer = _answer;
            _answer = null;
            answer?.TrySetResult(deck);
        }

        /// <summary>For tests: the named deck, as if tapped.</summary>
        public bool Choose(string deckName)
        {
            var button = _content.GetComponentsInChildren<Button>(true).FirstOrDefault(b => b.name == "Choose " + deckName);
            if (button == null)
            {
                return false;
            }

            button.onClick.Invoke();
            return true;
        }
    }
}
