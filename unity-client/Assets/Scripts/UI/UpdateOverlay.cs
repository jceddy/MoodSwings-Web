using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    public enum UpdateChoice
    {
        Update,
        Later,
        Skip,
    }

    /// <summary>
    /// "A new version is available": what is new, and Update / Later / Skip this version. While an update is being
    /// downloaded the buttons give way to its progress.
    /// </summary>
    public sealed class UpdateOverlay
    {
        private readonly GameObject _root;
        private readonly Text _title;
        private readonly Text _message;
        private readonly Text _notes;
        private readonly Text _status;
        private readonly GameObject _buttons;
        private readonly Text _updateLabel;
        private TaskCompletionSource<UpdateChoice> _answer;
        private bool _busy;

        public UpdateOverlay(Transform parent, UiTheme theme)
        {
            var root = UiFactory.Create("Update overlay", parent);
            _root = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.78f);

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = panel.anchorMax = panel.pivot = new Vector2(0.5f, 0.5f);
            panel.sizeDelta = new Vector2(980f, 0f);
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(40, 40, 36, 36);
            layout.spacing = 24f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            panel.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;

            _title = UiFactory.Label(panel, "A new version is available", 40, theme.textPrimary, TextAnchor.MiddleCenter, FontStyle.Bold);
            _message = UiFactory.Label(panel, string.Empty, 32, theme.textPrimary, TextAnchor.MiddleCenter);
            _notes = UiFactory.Label(panel, string.Empty, 26, theme.textMuted, TextAnchor.UpperLeft);
            _status = UiFactory.Label(panel, string.Empty, 28, theme.textPrimary, TextAnchor.MiddleCenter);

            var row = UiFactory.Row(panel, "Buttons", 24f, TextAnchor.MiddleCenter);
            _buttons = row.gameObject;
            var skip = UiFactory.Button(row.transform, "Skip this version", theme, () => Answer(UpdateChoice.Skip), primary: false);
            UiFactory.Size(skip.gameObject, 280f);
            var later = UiFactory.Button(row.transform, "Later", theme, () => Answer(UpdateChoice.Later), primary: false);
            UiFactory.Size(later.gameObject, 220f);
            var update = UiFactory.Button(row.transform, "Update", theme, () => Answer(UpdateChoice.Update));
            UiFactory.Size(update.gameObject, 280f);
            _updateLabel = update.GetComponentInChildren<Text>();

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public string MessageText => _message.text;

        public string NotesText => _notes.text;

        public string StatusText => _status.text;

        public bool ShowsButtons => _buttons.activeSelf;

        /// <summary>Offers the update. The answer is Later if the box is closed by Back, or another offer replaces this one.</summary>
        public Task<UpdateChoice> AskAsync(UpdateInfo update, string currentVersion, string updateLabel, string problem = null)
        {
            _answer?.TrySetResult(UpdateChoice.Later);
            _answer = new TaskCompletionSource<UpdateChoice>();
            _busy = false;

            // Over anything else up.
            _root.transform.SetAsLastSibling();

            _message.text = $"MOOD {update.Version} is ready. You have {currentVersion}.";
            var notes = update.Notes.Take(8).Select(n => "• " + n).ToList();
            _notes.text = string.Join("\n", notes);
            _notes.gameObject.SetActive(notes.Count > 0);
            _status.text = problem ?? string.Empty;
            _status.gameObject.SetActive(!string.IsNullOrEmpty(problem));
            _updateLabel.text = updateLabel;
            _buttons.SetActive(true);
            _root.SetActive(true);
            return _answer.Task;
        }

        /// <summary>Replaces the buttons with a status line while the update is on its way.</summary>
        public void ShowBusy(string status)
        {
            _busy = true;
            _buttons.SetActive(false);
            _status.text = status;
            _status.gameObject.SetActive(true);
        }

        public void Close()
        {
            _busy = false;
            _root.SetActive(false);
        }

        /// <summary>Back / Escape: Later. Not while an update is downloading. True if the box was open.</summary>
        public bool Dismiss()
        {
            if (!IsOpen)
            {
                return false;
            }

            if (!_busy)
            {
                Answer(UpdateChoice.Later);
            }

            return true;
        }

        private void Answer(UpdateChoice choice)
        {
            var answer = _answer;
            _answer = null;
            answer?.TrySetResult(choice);
        }

        /// <summary>For tests: press a button without a pointer.</summary>
        public void Press(UpdateChoice choice) => Answer(choice);
    }
}
