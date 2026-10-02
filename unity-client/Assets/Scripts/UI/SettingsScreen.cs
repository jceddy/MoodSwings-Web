using System;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The account preferences the web Settings dialog offers (minus the
    /// browser-only ones: card size and push notifications). Every toggle
    /// saves as soon as it's flipped; if the save fails it flips back and
    /// says why. Values are refreshed from /me on opening, since /login
    /// doesn't return them.
    /// </summary>
    public sealed class SettingsScreen : UiScreen
    {
        private const float ColumnWidth = 900f;

        private Text _status;
        private RectTransform _list;
        private bool _built;
        private int _loadRun;

        public string StatusText => _status != null ? _status.text : null;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            ClearList();
            SetStatus("Loading your settings...", isError: false);
            Load();
        }

        private void EnsureBuilt()
        {
            if (_built)
            {
                return;
            }

            _built = true;
            var theme = AppServices.Theme;
            UiFactory.Background(transform, theme.background);
            UiFactory.Header(transform, theme, "Settings", () => Router.Back());

            var column = UiFactory.Create("Column", transform);
            column.anchorMin = new Vector2(0.5f, 0f);
            column.anchorMax = new Vector2(0.5f, 1f);
            column.pivot = new Vector2(0.5f, 0.5f);
            column.offsetMin = new Vector2(-ColumnWidth / 2f, 40f);
            column.offsetMax = new Vector2(ColumnWidth / 2f, -130f);
            var layout = column.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 12f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _status = UiFactory.Label(column, string.Empty, 26, theme.textMuted);
            UiFactory.Size(_status.gameObject, height: 40f);

            UiFactory.ScrollList(column, out _list);
            UiFactory.Flexible(_list.parent.parent.gameObject, height: 1f);
        }

        private void SetStatus(string message, bool isError)
        {
            var theme = AppServices.Theme;
            _status.text = message ?? string.Empty;
            _status.color = isError ? theme.danger : theme.textMuted;
        }

        private void ClearList()
        {
            // Destroy() waits for the end of the frame, so hide the old rows too.
            foreach (Transform child in _list)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }
        }

        private async void Load()
        {
            var run = ++_loadRun;
            try
            {
                var result = await AppServices.Auth.RefreshUserAsync();
                if (this == null || run != _loadRun)
                {
                    return;
                }

                BuildRows();
                SetStatus(
                    result.Ok ? string.Empty : result.UserMessage("Couldn't load your latest settings.") + " Showing what we have.",
                    isError: !result.Ok);
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        private void BuildRows()
        {
            ClearList();
            var theme = AppServices.Theme;

            UiFactory.SectionTitle(_list, theme, "Game defaults");
            foreach (var preference in PreferenceCatalog.GameDefaults)
            {
                AddPreferenceRow(theme, preference);
            }

            UiFactory.SectionTitle(_list, theme, "Display");
            AddBoardLayoutRow(theme);

            UiFactory.SectionTitle(_list, theme, "Privacy");
            foreach (var preference in PreferenceCatalog.Privacy)
            {
                AddPreferenceRow(theme, preference);
            }

            UiFactory.SectionTitle(_list, theme, "On this device");
            AddDeviceRow(theme, "Sound effects", "Chimes for your turn, cards played, and the end of a round.",
                AppServices.Device.SoundOn, on => AppServices.Device.SoundOn = on);
            if (Application.isMobilePlatform)
            {
                AddDeviceRow(theme, "Vibration", "A short buzz for your turn and for questions that need an answer.",
                    AppServices.Device.VibrationOn, on => AppServices.Device.VibrationOn = on);
            }
        }

        private VerticalLayoutGroup NewPanel(UiTheme theme)
        {
            var rect = UiFactory.Create("Panel", _list);
            rect.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = rect.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(20, 16, 14, 14);
            layout.spacing = 6f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            return layout;
        }

        private static void AddDescription(UiTheme theme, Transform parent, string text)
        {
            // Indented to line up under the toggle's label, not its box.
            var row = UiFactory.Row(parent, "Description", 0f, TextAnchor.UpperLeft);
            row.padding = new RectOffset(56, 0, 0, 0);
            var label = UiFactory.Label(row.transform, text, 24, theme.textMuted, TextAnchor.UpperLeft);
            UiFactory.Flexible(label.gameObject, width: 1f);
        }

        // These switches live on the device, not the account, so there is nothing to send.
        private void AddDeviceRow(UiTheme theme, string label, string description, bool isOn, System.Action<bool> save)
        {
            var panel = NewPanel(theme);
            var toggle = UiFactory.Toggle(panel.transform, label, theme, isOn);
            AddDescription(theme, panel.transform, description);
            toggle.onValueChanged.AddListener(value => save(value));
        }

        private void AddPreferenceRow(UiTheme theme, BoolPreference preference)
        {
            var panel = NewPanel(theme);
            var toggle = UiFactory.Toggle(panel.transform, preference.Label, theme, AppServices.Preferences.Get(preference));
            AddDescription(theme, panel.transform, preference.Description);
            toggle.onValueChanged.AddListener(value => OnPreferenceToggled(preference, toggle, value));
        }

        private async void OnPreferenceToggled(BoolPreference preference, Toggle toggle, bool value)
        {
            try
            {
                toggle.interactable = false;
                var result = await AppServices.Preferences.SetAsync(preference, value);
                if (this == null)
                {
                    return;
                }

                toggle.interactable = true;
                if (result.Ok)
                {
                    SetStatus("Saved.", isError: false);
                }
                else
                {
                    toggle.SetIsOnWithoutNotify(!value);
                    SetStatus(result.UserMessage("Couldn't save that setting."), isError: true);
                }
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        private void AddBoardLayoutRow(UiTheme theme)
        {
            var panel = NewPanel(theme);
            var title = UiFactory.Label(panel.transform, "Round / Score / Players position", 30, theme.textPrimary, TextAnchor.MiddleLeft);
            UiFactory.Size(title.gameObject, height: 40f);

            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = false;
            var current = AppServices.Preferences.GetBoardLayout();

            AddLayoutOption(theme, panel, group, "Above the play area (default)", PreferenceCatalog.BoardLayoutAbovePlayArea, current);
            AddLayoutOption(theme, panel, group, "Below your hand", PreferenceCatalog.BoardLayoutBelowHand, current);
        }

        private void AddLayoutOption(UiTheme theme, VerticalLayoutGroup panel, ToggleGroup group, string label, string layout, string current)
        {
            var toggle = UiFactory.Toggle(panel.transform, label, theme, layout == current);
            toggle.group = group;
            toggle.onValueChanged.AddListener(isOn =>
            {
                // Switching fires for the option turned off as well; only act on the new choice.
                if (isOn && layout != AppServices.Preferences.GetBoardLayout())
                {
                    SaveBoardLayout(layout);
                }
            });
        }

        private async void SaveBoardLayout(string layout)
        {
            try
            {
                var result = await AppServices.Preferences.SetBoardLayoutAsync(layout);
                if (this == null)
                {
                    return;
                }

                if (result.Ok)
                {
                    SetStatus("Saved.", isError: false);
                    return;
                }

                // The saved layout didn't change, so rebuilding puts the choice back.
                BuildRows();
                SetStatus(result.UserMessage("Couldn't save that setting."), isError: true);
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }
    }
}
