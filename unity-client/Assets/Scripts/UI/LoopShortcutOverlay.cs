using System;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// A Chaos Draft loop: a repeating effect keeps adding to the board each cycle, so the game offers to apply it a
    /// number of times in one go (up to a cap). Asked once per offer; "Not now" leaves it to be played out by hand.
    /// </summary>
    public sealed class LoopShortcutOverlay
    {
        private readonly GameObject _root;
        private readonly Text _message;
        private readonly Text _count;
        private readonly Text _applyLabel;
        private int _cap;
        private int _times;

        /// <summary>Called with how many times to apply it.</summary>
        public Action<int> Apply;

        public LoopShortcutOverlay(Transform parent, UiTheme theme)
        {
            var root = UiFactory.Create("Loop shortcut overlay", parent);
            _root = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.78f);

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = panel.anchorMax = panel.pivot = new Vector2(0.5f, 0.5f);
            panel.sizeDelta = new Vector2(980f, 0f);
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(40, 40, 36, 36);
            layout.spacing = 26f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            panel.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;

            _message = UiFactory.Label(panel, string.Empty, 32, theme.textPrimary, TextAnchor.MiddleCenter);

            var stepper = UiFactory.Row(panel, "Stepper", 20f, TextAnchor.MiddleCenter);
            var fewer = UiFactory.Button(stepper.transform, "-", theme, () => Step(-1), primary: false);
            fewer.gameObject.name = "Fewer times";
            UiFactory.Size(fewer.gameObject, 100f);
            _count = UiFactory.Label(stepper.transform, string.Empty, 36, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            UiFactory.Size(_count.gameObject, 300f, UiFactory.ControlHeight);
            var more = UiFactory.Button(stepper.transform, "+", theme, () => Step(1), primary: false);
            more.gameObject.name = "More times";
            UiFactory.Size(more.gameObject, 100f);

            var buttons = UiFactory.Row(panel, "Buttons", 24f, TextAnchor.MiddleCenter);
            var no = UiFactory.Button(buttons.transform, "Not now", theme, Close, primary: false);
            no.gameObject.name = "Not now";
            UiFactory.Size(no.gameObject, 300f);
            var yes = UiFactory.Button(buttons.transform, "Apply", theme, () =>
            {
                var times = _times;
                Close();
                Apply?.Invoke(times);
            });
            yes.gameObject.name = "Apply loop";
            UiFactory.Size(yes.gameObject, 360f);
            _applyLabel = yes.GetComponentInChildren<Text>();

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public string MessageText => _message.text;

        public int Times => _times;

        public void Open(ChaosLoopShortcut shortcut)
        {
            _cap = Mathf.Max(1, shortcut.Cap);
            _times = _cap;
            _message.text = shortcut.Label;
            Refresh();
            _root.SetActive(true);
        }

        public void Close() => _root.SetActive(false);

        /// <summary>Back leaves it for now.</summary>
        public bool Dismiss()
        {
            if (!IsOpen)
            {
                return false;
            }

            Close();
            return true;
        }

        private void Step(int by)
        {
            _times = Mathf.Clamp(_times + by, 1, _cap);
            Refresh();
        }

        private void Refresh()
        {
            _count.text = _times + (_times == 1 ? " time" : " times");
            _applyLabel.text = "Apply " + _times + (_times == 1 ? " time" : " times");
        }
    }
}
