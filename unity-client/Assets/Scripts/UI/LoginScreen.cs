using System;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.EventSystems;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Username/password login with "remember me". Registration and password
    /// reset open the website. An unverified account (HTTP 403) gets an
    /// inline "resend verification email" form. Shown with an optional
    /// string arg (e.g. "Your session has expired") displayed as the error.
    /// </summary>
    public sealed class LoginScreen : UiScreen
    {
        private InputField _username;
        private InputField _password;
        private Toggle _remember;
        private Text _error;
        private Button _login;
        private GameObject _verifyPanel;
        private GameObject _resendLink;
        private InputField _verifyEmail;
        private Button _resend;
        private Text _verifyStatus;
        private Text _footer;
        private bool _built;
        private bool _busy;

        public bool VerifyPanelVisible => _verifyPanel != null && _verifyPanel.activeSelf;

        public string ErrorText => _error != null ? _error.text : null;

        public override void OnShown(object args)
        {
            EnsureBuilt();

            var auth = AppServices.Auth;
            _username.text = auth.LastUsername ?? string.Empty;
            _password.text = string.Empty;
            _remember.isOn = auth.RememberMe;
            _error.text = args as string ?? string.Empty;
            ShowVerifyPanel(false);
            SetBusy(false);

            (string.IsNullOrEmpty(_username.text) ? _username : _password).ActivateInputField();
            ShowVersions();
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

            var column = UiFactory.CenteredColumn(transform, 640f, 20f);

            UiFactory.TitleBlock(column, theme);

            var description = UiFactory.Label(column, "A simulator for the Mood Swings TCG.", 28, theme.textPrimary);
            UiFactory.Size(description.gameObject, height: 40f);

            _username = UiFactory.Input(column, "Username", theme);
            _password = UiFactory.Input(column, "Password", theme, password: true);
            _remember = UiFactory.Toggle(column, "Remember me", theme, true);

            _error = UiFactory.Label(column, string.Empty, 26, theme.danger);
            UiFactory.Size(_error.gameObject, height: 60f);

            _login = UiFactory.Button(column, "Log in", theme, OnLoginClicked);

            BuildVerifyPanel(column, theme);

            UiFactory.Link(column, "Create an account", theme, () => OpenSitePage("/register.html"));
            UiFactory.Link(column, "Forgot your password?", theme, () => OpenSitePage("/forgot-password.html"));
            _resendLink = UiFactory.Link(column, "Didn't get your verification email?", theme, () => ShowVerifyPanel(true)).gameObject;

            if (AppExit.IsAvailable)
            {
                var quit = UiFactory.Link(column, "Quit", theme, () => AppExit.Quit());
                quit.gameObject.name = "Quit";
            }

            var footer = UiFactory.Create("Footer", transform);
            footer.anchorMin = new Vector2(0f, 0f);
            footer.anchorMax = new Vector2(1f, 0f);
            footer.pivot = new Vector2(0.5f, 0f);
            footer.sizeDelta = new Vector2(0f, 60f);
            _footer = footer.gameObject.AddComponent<Text>();
            _footer.font = UiFactory.Font;
            _footer.fontSize = 22;
            _footer.color = theme.textMuted;
            _footer.alignment = TextAnchor.MiddleCenter;

            _username.onEndEdit.AddListener(_ =>
            {
                if (EnterPressed())
                {
                    _password.ActivateInputField();
                }
            });
            _password.onEndEdit.AddListener(_ =>
            {
                if (EnterPressed())
                {
                    OnLoginClicked();
                }
            });
        }

        private void BuildVerifyPanel(RectTransform column, UiTheme theme)
        {
            var panel = UiFactory.Create("VerifyPanel", column);
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 12f;
            layout.padding = new RectOffset(16, 16, 16, 16);
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            panel.gameObject.AddComponent<Image>().color = theme.panel;

            UiFactory.Label(panel, "Enter your email and we'll send a new link.", 24, theme.textMuted, TextAnchor.MiddleCenter);
            _verifyEmail = UiFactory.Input(panel, "Email address", theme);
            _resend = UiFactory.Button(panel, "Send verification email", theme, OnResendClicked, primary: false);
            _verifyStatus = UiFactory.Label(panel, string.Empty, 24, theme.textPrimary);
            _verifyStatus.gameObject.SetActive(false); // an empty label would still reserve its height

            _verifyPanel = panel.gameObject;
            _verifyPanel.SetActive(false);
        }

        private void Update()
        {
            if (!KeyInput.TabPressed || EventSystem.current == null)
            {
                return;
            }

            var order = new Selectable[] { _username, _password, _remember, _login };
            var current = EventSystem.current.currentSelectedGameObject;
            var index = -1;
            for (var i = 0; i < order.Length; i++)
            {
                if (current != null && current == order[i].gameObject)
                {
                    index = i;
                }
            }

            var step = KeyInput.ShiftHeld ? -1 : 1;
            var next = order[((index + step) % order.Length + order.Length) % order.Length];
            next.Select();
            if (next is InputField field)
            {
                field.ActivateInputField();
            }
        }

        private static bool EnterPressed()
        {
            return KeyInput.EnterPressed;
        }

        /// <summary>Shows the resend form; its link then hides, so the column stays clear of the footer.</summary>
        private void ShowVerifyPanel(bool show)
        {
            _verifyPanel.SetActive(show);
            _resendLink.SetActive(!show);
            SetVerifyStatus(string.Empty);
        }

        private void SetVerifyStatus(string text)
        {
            _verifyStatus.text = text;
            _verifyStatus.gameObject.SetActive(!string.IsNullOrEmpty(text));
        }

        private void SetBusy(bool busy)
        {
            _busy = busy;
            _login.interactable = !busy;
            _username.interactable = !busy;
            _password.interactable = !busy;
        }

        private async void OnLoginClicked()
        {
            try
            {
                await SubmitAsync(_username.text, _password.text, _remember.isOn);
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        /// <summary>The login button's action, public so tests can drive it without typing.</summary>
        public async Task SubmitAsync(string username, string password, bool remember)
        {
            if (_busy)
            {
                return;
            }

            _error.text = string.Empty;
            SetBusy(true);
            var result = await AppServices.Auth.LoginAsync(username, password, remember);
            if (this == null)
            {
                return;
            }

            SetBusy(false);
            switch (result.Outcome)
            {
                case LoginOutcome.Success:
                    _password.text = string.Empty;
                    Router.ClearHistory();
                    Router.Show<HomeScreen>(result.User, addToHistory: false);
                    break;
                case LoginOutcome.EmailNotVerified:
                    _error.text = result.Message ?? "Please verify your email address before logging in.";
                    ShowVerifyPanel(true);
                    break;
                case LoginOutcome.InvalidCredentials:
                    _error.text = result.Message ?? "Invalid username or password.";
                    break;
                case LoginOutcome.NetworkError:
                    _error.text = "Can't reach the server. Check your connection and try again.";
                    break;
                case LoginOutcome.Maintenance:
                    // The client's MaintenanceEntered event already switched screens.
                    break;
                default:
                    _error.text = result.Message ?? "Login failed. Please try again.";
                    break;
            }
        }

        private async void OnResendClicked()
        {
            try
            {
                _resend.interactable = false;
                SetVerifyStatus("Sending...");
                var result = await AppServices.Auth.ResendVerificationAsync(_verifyEmail.text);
                if (this == null)
                {
                    return;
                }

                _resend.interactable = true;
                SetVerifyStatus(result.Ok
                    ? result.Value.Message ?? "If that account needs verification, a new email is on its way."
                    : result.Failure == ApiFailureKind.Network
                        ? "Can't reach the server. Check your connection and try again."
                        : result.Message ?? "Couldn't send the email. Please try again.");
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        private async void ShowVersions()
        {
            var client = "Client " + Application.version;
            _footer.text = client;

            var version = await AppServices.Api.GetServerVersionAsync();
            if (this == null)
            {
                return;
            }

            _footer.text = client + "   |   Server " + (version.Ok && version.Value.Length <= 20 ? version.Value : "unavailable");
        }

        private static void OpenSitePage(string path)
        {
            Application.OpenURL(AppServices.Api.Config.SiteRoot + path);
        }
    }
}
