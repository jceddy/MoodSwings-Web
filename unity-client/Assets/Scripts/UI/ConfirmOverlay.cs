using System;
using System.Threading.Tasks;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// A small yes/no box over a screen ("Resign this game?"), or a message with
    /// just an OK. Asked with <see cref="AskAsync"/>, which finishes when the
    /// player answers; closing it by Back counts as No.
    /// </summary>
    public sealed class ConfirmOverlay
    {
        private readonly GameObject _root;
        private readonly Text _message;
        private readonly Button _yes;
        private readonly Text _yesLabel;
        private readonly Button _no;
        private TaskCompletionSource<bool> _answer;

        public ConfirmOverlay(Transform parent, UiTheme theme)
        {
            var root = UiFactory.Create("Confirm overlay", parent);
            _root = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.78f);

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = panel.anchorMax = panel.pivot = new Vector2(0.5f, 0.5f);
            panel.sizeDelta = new Vector2(980f, 0f);
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(40, 40, 36, 36);
            layout.spacing = 30f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            panel.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;

            _message = UiFactory.Label(panel, string.Empty, 34, theme.textPrimary, TextAnchor.MiddleCenter);

            var buttons = UiFactory.Row(panel, "Buttons", 24f, TextAnchor.MiddleCenter);
            _no = UiFactory.Button(buttons.transform, "Cancel", theme, () => Answer(false), primary: false);
            UiFactory.Size(_no.gameObject, 300f);
            _yes = UiFactory.Button(buttons.transform, "OK", theme, () => Answer(true));
            UiFactory.Size(_yes.gameObject, 300f);
            _yesLabel = _yes.GetComponentInChildren<Text>();

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public string MessageText => _message.text;

        /// <summary>
        /// Shows the question. <paramref name="noLabel"/> null means a plain message
        /// with only a yes button. Asking while a question is already open answers
        /// the old one No first.
        /// </summary>
        public Task<bool> AskAsync(string message, string yesLabel = "OK", string noLabel = "Cancel")
        {
            _answer?.TrySetResult(false);
            _answer = new TaskCompletionSource<bool>();

            _message.text = message;
            _yesLabel.text = yesLabel;
            _no.gameObject.SetActive(noLabel != null);
            if (noLabel != null)
            {
                _no.GetComponentInChildren<Text>().text = noLabel;
            }

            _root.SetActive(true);
            return _answer.Task;
        }

        /// <summary>Back / Escape: answers No (or dismisses a plain message). True if a question was open.</summary>
        public bool Dismiss()
        {
            if (!IsOpen)
            {
                return false;
            }

            Answer(false);
            return true;
        }

        private void Answer(bool yes)
        {
            _root.SetActive(false);
            var answer = _answer;
            _answer = null;
            answer?.TrySetResult(yes);
        }

        /// <summary>For tests: press the named button without a pointer.</summary>
        public void Press(bool yes) => Answer(yes);
    }
}
