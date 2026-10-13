using System;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The account preferences the web Settings dialog offers (minus the
    /// browser-only ones: card size, the Round/Score/Players position -- this board has its
    /// own layout -- and push notifications). Every toggle
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
        private string _notificationsError;

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
                var notifications = AppServices.Notifications.LoadAsync(); // a failure just leaves that section saying so
                var result = await AppServices.Auth.RefreshUserAsync();
                var notificationsResult = await notifications;
                _notificationsError = notificationsResult.Ok ? null : notificationsResult.Message;
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

            UiFactory.SectionTitle(_list, theme, "Privacy");
            foreach (var preference in PreferenceCatalog.Privacy)
            {
                AddPreferenceRow(theme, preference);
            }

            AddNotificationRows(theme);

            UiFactory.SectionTitle(_list, theme, "On this device");
            AddDeviceRow(theme, "Dark mode", "A dark palette instead of the light one. The screens are built again, so you land back on the main menu.",
                AppServices.Device.DarkMode, SetDarkMode);
            AddDeviceRow(theme, "Sound effects", "Chimes for your turn, cards played, and the end of a round.",
                AppServices.Device.SoundOn, on => AppServices.Device.SoundOn = on);
            AddVolumeRow(theme, "Effects volume", AppServices.Device.EffectsVolume, value => AppServices.Device.EffectsVolume = value,
                preview: () =>
                {
                    if (AppServices.Device.SoundOn)
                    {
                        GameFeedback.Sound.Play(SoundId.Chat);
                    }
                });
            AddDeviceRow(theme, "Music", "The background music: the main theme, and on the board a theme for the color of the mood played last.",
                AppServices.Device.MusicOn, on => AppServices.Device.MusicOn = on);
            AddVolumeRow(theme, "Music volume", AppServices.Device.MusicVolume, value => AppServices.Device.MusicVolume = value);
            if (AppServices.Push.IsAvailable)
            {
                AddPushRow(theme);
            }

            if (Application.isMobilePlatform)
            {
                AddDeviceRow(theme, "Vibration", "A short buzz for your turn and for questions that need an answer.",
                    AppServices.Device.VibrationOn, on => AppServices.Device.VibrationOn = on);
            }
        }

        // The switches for what you're told about. They govern push on the website and Discord messages; this app can't
        // receive push itself yet, so the section says so rather than promising a buzz.
        private void AddNotificationRows(UiTheme theme)
        {
            UiFactory.SectionTitle(_list, theme, "Notify me when...");
            var notifications = AppServices.Notifications;
            if (notifications.Preferences == null)
            {
                var failed = UiFactory.Label(_list, _notificationsError ?? "Couldn't load the notification settings.", 24, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(failed.gameObject, height: 60f);
                return;
            }

            foreach (var toggle in NotificationsFlow.Switches)
            {
                var panel = NewPanel(theme);
                var box = UiFactory.Toggle(panel.transform, toggle.Label, theme, notifications.IsOn(toggle));
                box.gameObject.name = "Notify " + toggle.Label;
                if (!string.IsNullOrEmpty(toggle.Description))
                {
                    AddDescription(theme, panel.transform, toggle.Description);
                }

                var captured = toggle;
                box.onValueChanged.AddListener(value => OnNotificationToggled(captured, box, value));
            }

            var note = NewPanel(theme);
            var discord = notifications.DiscordLine();
            var line = UiFactory.Label(note.transform,
                (discord != null ? discord + ". " : string.Empty)
                + "These are sent as push notifications on the website and as Discord messages (link Discord there). This app can't receive push notifications yet.",
                24, theme.textMuted, TextAnchor.UpperLeft);
            line.gameObject.name = "Notifications note";
        }

        // Where the device can get push: a switch for this device alone (the account's own switches above decide what's sent).
        private void AddPushRow(UiTheme theme)
        {
            var panel = NewPanel(theme);
            var toggle = UiFactory.Toggle(panel.transform, "Push notifications", theme, AppServices.Push.Enabled);
            toggle.gameObject.name = "Push notifications";
            AddDescription(theme, panel.transform, "Alerts on this device for the things you asked to be told about above.");
            toggle.onValueChanged.AddListener(on => OnPushToggled(toggle, on));
        }

        private async void OnPushToggled(Toggle toggle, bool on)
        {
            try
            {
                toggle.interactable = false;
                var result = await AppServices.Push.SetEnabledAsync(on);
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
                    toggle.SetIsOnWithoutNotify(!on);
                    AppServices.Device.PushOn = !on;
                    SetStatus(result.Message, isError: true);
                }
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        private async void OnNotificationToggled(NotificationSwitch toggle, Toggle box, bool value)
        {
            try
            {
                box.interactable = false;
                var result = await AppServices.Notifications.SetAsync(toggle, value);
                if (this == null)
                {
                    return;
                }

                box.interactable = true;
                if (result.Ok)
                {
                    SetStatus("Saved.", isError: false);
                }
                else
                {
                    box.SetIsOnWithoutNotify(!value);
                    SetStatus(result.Message, isError: true);
                }
            }
            catch (Exception e)
            {
                Debug.LogException(e);
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

        // A slider for how loud something is, with the percentage beside its name. It is applied as it moves.
        private void AddVolumeRow(UiTheme theme, string label, float value, System.Action<float> save, System.Action preview = null)
        {
            var panel = NewPanel(theme);
            var heading = UiFactory.Row(panel.transform, "Volume heading", 12f, TextAnchor.MiddleLeft);
            UiFactory.Size(heading.gameObject, height: 36f);
            var name = UiFactory.Label(heading.transform, label, 26, theme.textPrimary, TextAnchor.MiddleLeft);
            UiFactory.Flexible(name.gameObject, width: 1f);
            var percent = UiFactory.Label(heading.transform, Percent(value), 26, theme.textMuted, TextAnchor.MiddleRight);
            percent.name = "Percent";
            UiFactory.Size(percent.gameObject, 90f, 36f);

            var slider = UiFactory.Slider(panel.transform, theme, value, preview);
            slider.gameObject.name = label;
            slider.onValueChanged.AddListener(v =>
            {
                save(v);
                percent.text = Percent(v);
            });
        }

        private static string Percent(float value) => Mathf.RoundToInt(value * 100f) + "%";

        // The screens take their colors when they are built, so a new palette means building them again.
        private void SetDarkMode(bool on)
        {
            if (AppServices.Device.DarkMode == on)
            {
                return;
            }

            AppServices.Device.DarkMode = on;
            AppServices.Theme.Apply(on);
            UnityEngine.SceneManagement.SceneManager.LoadScene(gameObject.scene.name);
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
    }
}
