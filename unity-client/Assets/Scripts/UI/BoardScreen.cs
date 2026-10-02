using System;
using System.Collections;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.EventSystems;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// A game's board: every seat with its moods in front of it, the deck and
    /// discard in the middle, and your hand along the bottom. Opened with a
    /// <see cref="BoardSession"/> (playing or spectating). Seats are placed as
    /// the web client places them -- the next player in turn order at your
    /// left -- and the board polls every few seconds, which is also what makes
    /// the server advance bot turns. Click any card for a readable close-up; on
    /// your turn, clicking a card in your hand opens its play form. Pass,
    /// Advance turn, I'm ready and Resign sit beside your hand; a question some
    /// card effect has for you opens on its own and has to be answered. The Log
    /// and Chat buttons open the recent events and the game's chat.
    /// </summary>
    public sealed class BoardScreen : UiScreen
    {
        private const float PollSeconds = 3f;
        private const float HeaderHeight = 100f;
        private const float TableHeight = 980f;
        private const float ReferenceWidth = 1920f;
        private const float MoodWidth = 112f;
        private const float HandCardWidth = 140f;
        private const float DetailCardWidth = 460f;

        // Where each seat's zone sits, as fractions of the table area (x, y from the bottom).
        // Bands, in 980 px of table height: opponents on top (270), the sides and
        // piles in the middle (260), your moods (230), your hand at the bottom (210).
        private static readonly Dictionary<SeatZone, Rect> ZoneRects = new Dictionary<SeatZone, Rect>
        {
            [SeatZone.North] = Fraction(0.30f, 0.7245f, 0.70f, 1.0f),
            [SeatZone.Northwest] = Fraction(0.02f, 0.7245f, 0.49f, 1.0f),
            [SeatZone.Northeast] = Fraction(0.51f, 0.7245f, 0.98f, 1.0f),
            [SeatZone.West] = Fraction(0.01f, 0.4592f, 0.27f, 0.7245f),
            [SeatZone.East] = Fraction(0.73f, 0.4592f, 0.99f, 0.7245f),
            [SeatZone.South] = Fraction(0.15f, 0.2245f, 0.85f, 0.4592f),
        };

        private static readonly Rect PilesRect = Fraction(0.30f, 0.4592f, 0.70f, 0.7245f);
        // Starts a little above the screen edge so the cards aren't flush against it, and
        // stops short of the right edge, where the action buttons sit.
        private static readonly Rect HandRect = Fraction(0.03f, 0.012f, 0.80f, 0.2143f);

        private BoardSession _session;
        private bool _built;
        private RectTransform _table;
        private Text _banner;
        private Text _roundLine;
        private Text _message;
        private Text _loading;
        private Text _chatButtonLabel;
        private RectTransform _detailCardHolder;
        private Text _detailText;
        private GameObject _detail;
        private GameObject _logOverlay;
        private Text _logBody;
        private GameObject _chatOverlay;
        private Text _chatBody;
        private GameObject _chatEntry;
        private InputField _chatInput;
        private Text _chatError;
        private ChoiceOverlay _choices;
        private ConfirmOverlay _confirm;
        private RectTransform _dropZone;
        private Image _dropZoneImage;
        private RectTransform _dragGhost;
        private CanvasGroup _draggedCardGroup;
        private Button _primaryAction;
        private Text _primaryLabel;
        private Button _resignAction;
        private GameObject _actions;

        private sealed class Flight
        {
            public RectTransform Card;
            public CanvasGroup Group;
            public BoardCard Mood;
            public int OwnerId;
            public float Width;
        }

        private const float HoverDelaySeconds = 0.35f;
        private const float HoverCardWidth = 340f;

        private RectTransform _hoverPreview;
        private RectTransform _hoverCardHolder;
        private Text _hoverCaption;
        private BoardCard _hoverCard;
        private float _hoverSince;
        private float _hoverPointerX;

        private GameState _previous;
        private readonly HashSet<int> _entering = new HashSet<int>();
        private readonly List<Flight> _flights = new List<Flight>();
        private float _messageExpires;

        private BoardCard _playingCard;
        private string _decisionKey;
        private string _loopKey;
        private bool _messageFromRefresh;
        private float _nextHeaderUpdate;

        public string BannerText => _banner != null ? _banner.text : null;

        public string RoundText => _roundLine != null ? _roundLine.text : null;

        public string MessageText => _message != null ? _message.text : null;

        public bool DetailOpen => _detail != null && _detail.activeSelf;

        /// <summary>The play form or decision form, when one is open.</summary>
        public ChoiceOverlay Choices => _choices;

        public ConfirmOverlay Confirm => _confirm;

        /// <summary>A larger card is showing beside the pointer, because the mouse has rested on a card.</summary>
        public bool HoverPreviewShown => _hoverPreview != null && _hoverPreview.gameObject.activeSelf;

        /// <summary>A card is being dragged from the hand.</summary>
        public bool IsDragging => _dragGhost != null;

        /// <summary>The dragged card is over the part of the table where letting go plays it.</summary>
        public bool DragIsOverDropZone { get; private set; }

        /// <summary>How many seat zones, piles and hand areas are currently drawn; tests use it to see what's on the table.</summary>
        public int TableChildCount => _table != null ? _table.childCount : 0;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _session = args as BoardSession;
            CloseOverlays();
            _choices.Close();
            _confirm.Dismiss();
            _playingCard = null;
            _decisionKey = null;
            _loopKey = null;
            _previous = null;
            _entering.Clear();
            HideHover();
            foreach (Transform child in transform)
            {
                if (child.name == "Flying card")
                {
                    child.gameObject.SetActive(false);
                    Destroy(child.gameObject);
                }
            }

            CancelDrag();
            _chatError.text = string.Empty;
            SetMessage(string.Empty);
            _actions.SetActive(false);

            if (_session == null)
            {
                ClearTable();
                ShowLoading("No game to show.");
                return;
            }

            _session.Changed += Render;
            if (_session.State != null)
            {
                Render();
            }
            else
            {
                ClearTable();
                ShowLoading("Loading the game...");
            }

            Run(Refresh);
            PollWhileShown(PollSeconds, Refresh);
        }

        public override void OnHidden()
        {
            CancelDrag();
            HideHover();
            if (_session != null)
            {
                _session.Changed -= Render;
            }

            StopPolling();
        }

        public override bool HandleBack()
        {
            if (_confirm.Dismiss())
            {
                return true;
            }

            if (_detail.activeSelf || _logOverlay.activeSelf || _chatOverlay.activeSelf)
            {
                CloseOverlays();
                return true;
            }

            // A play form can be called off; a question you have to answer can't.
            return _choices.Cancel();
        }

        private void Update()
        {
            // A result worth a few seconds of attention (a round won) fades on its own.
            if (_messageExpires > 0f && Time.unscaledTime > _messageExpires)
            {
                _messageExpires = 0f;
                SetMessage(string.Empty);
            }

            // A mouse resting on a card for a moment brings up a larger copy of it.
            if (_hoverCard != null && !HoverPreviewShown && Time.unscaledTime - _hoverSince >= HoverDelaySeconds && !AnyPopUpOrDrag())
            {
                ShowHover();
            }

            // The action clock ticks every second; the rest of the board waits for the next poll.
            if (_session?.State == null || Time.unscaledTime < _nextHeaderUpdate)
            {
                return;
            }

            _nextHeaderUpdate = Time.unscaledTime + 0.25f;
            UpdateHeader(_session.State);
        }

        private async Task Refresh()
        {
            var result = await _session.RefreshAsync();
            if (this == null)
            {
                return;
            }

            if (result.Ok)
            {
                if (_messageFromRefresh)
                {
                    SetMessage(string.Empty);
                }

                // A game that's waiting to be dealt starts once everyone's ready.
                await _session.StartWhenReadyAsync();
            }
            else if (_session.State == null)
            {
                ShowLoading(result.Message);
            }
            else
            {
                SetMessage(result.Message, isError: true, fromRefresh: true);
            }
        }

        // --- structure -----------------------------------------------------------------------

        private void EnsureBuilt()
        {
            if (_built)
            {
                return;
            }

            _built = true;
            var theme = AppServices.Theme;
            UiFactory.Background(transform, theme.background);

            _table = UiFactory.Create("Table", transform);
            _table.anchorMin = Vector2.zero;
            _table.anchorMax = Vector2.one;
            _table.offsetMin = Vector2.zero;
            _table.offsetMax = new Vector2(0f, -HeaderHeight);

            BuildDropZone(theme);
            BuildActions(theme);
            BuildHeader(theme);

            _message = UiFactory.Label(transform, string.Empty, 24, theme.danger);
            _message.rectTransform.anchorMin = new Vector2(0.2f, 1f);
            _message.rectTransform.anchorMax = new Vector2(0.8f, 1f);
            _message.rectTransform.pivot = new Vector2(0.5f, 1f);
            _message.rectTransform.sizeDelta = new Vector2(0f, 34f);
            _message.rectTransform.anchoredPosition = new Vector2(0f, -HeaderHeight);

            _loading = UiFactory.Label(transform, "Loading the game...", 36, theme.textMuted);
            UiFactory.Stretch(_loading.rectTransform);

            BuildHoverPreview(theme);
            BuildDetailOverlay(theme);
            _logOverlay = BuildTextOverlay(theme, "Recent events", out _logBody, out _);
            _chatOverlay = BuildTextOverlay(theme, "Chat", out _chatBody, out var chatPanel);
            BuildChatEntry(theme, chatPanel);

            // Last, so they sit on top of everything else.
            _choices = new ChoiceOverlay(transform, theme);
            _confirm = new ConfirmOverlay(transform, theme);
        }

        private void BuildHeader(UiTheme theme)
        {
            var bar = UiFactory.Create("Header", transform);
            bar.anchorMin = new Vector2(0f, 1f);
            bar.anchorMax = new Vector2(1f, 1f);
            bar.pivot = new Vector2(0.5f, 1f);
            bar.sizeDelta = new Vector2(0f, HeaderHeight);

            var back = (RectTransform)UiFactory.Button(bar, "< Back", theme, () => Router.Back(), primary: false).transform;
            back.anchorMin = back.anchorMax = back.pivot = new Vector2(0f, 0.5f);
            back.sizeDelta = new Vector2(180f, UiFactory.ControlHeight);
            back.anchoredPosition = new Vector2(40f, 0f);

            _banner = UiFactory.Label(bar, string.Empty, 38, theme.textPrimary, TextAnchor.MiddleCenter, FontStyle.Bold);
            _banner.rectTransform.anchorMin = new Vector2(0.18f, 0.42f);
            _banner.rectTransform.anchorMax = new Vector2(0.82f, 1f);
            _banner.rectTransform.offsetMin = _banner.rectTransform.offsetMax = Vector2.zero;

            _roundLine = UiFactory.Label(bar, string.Empty, 24, theme.textMuted);
            _roundLine.horizontalOverflow = HorizontalWrapMode.Overflow;
            _roundLine.rectTransform.anchorMin = new Vector2(0.18f, 0f);
            _roundLine.rectTransform.anchorMax = new Vector2(0.82f, 0.42f);
            _roundLine.rectTransform.offsetMin = _roundLine.rectTransform.offsetMax = Vector2.zero;

            var chat = UiFactory.Button(bar, "Chat", theme, () => _chatOverlay.SetActive(true), primary: false);
            _chatButtonLabel = chat.GetComponentInChildren<Text>();
            PlaceHeaderButton((RectTransform)chat.transform, 40f);

            var log = UiFactory.Button(bar, "Log", theme, () => _logOverlay.SetActive(true), primary: false);
            PlaceHeaderButton((RectTransform)log.transform, 240f);
        }

        private static void PlaceHeaderButton(RectTransform rect, float fromRight)
        {
            rect.anchorMin = rect.anchorMax = rect.pivot = new Vector2(1f, 0.5f);
            rect.sizeDelta = new Vector2(180f, UiFactory.ControlHeight);
            rect.anchoredPosition = new Vector2(-fromRight, 0f);
        }

        // Where a dragged card is let go to play it: the whole table above the hand. Hidden unless a
        // card is being dragged.
        private void BuildDropZone(UiTheme theme)
        {
            _dropZone = UiFactory.Create("Drop zone", transform);
            _dropZone.anchorMin = Vector2.zero;
            _dropZone.anchorMax = Vector2.one;
            _dropZone.offsetMin = new Vector2(0f, HandRect.yMax * TableHeight + 6f);
            _dropZone.offsetMax = new Vector2(0f, -HeaderHeight);
            _dropZoneImage = _dropZone.gameObject.AddComponent<Image>();
            _dropZoneImage.raycastTarget = false;

            // Low and to the left, where no seat's moods are.
            var label = UiFactory.Label(_dropZone, "Drop here to play", 34, theme.accent, TextAnchor.LowerLeft, FontStyle.Bold);
            label.raycastTarget = false;
            UiFactory.Stretch(label.rectTransform);
            label.rectTransform.offsetMin = new Vector2(40f, 14f);
            label.rectTransform.offsetMax = Vector2.zero;

            _dropZone.gameObject.SetActive(false);
        }

        // Pass / Advance turn / I'm ready, and Resign, beside the hand. Which show, and whether
        // they can be pressed, is decided in UpdateActions.
        private void BuildActions(UiTheme theme)
        {
            var area = UiFactory.Create("Actions", transform);
            _actions = area.gameObject;
            area.anchorMin = new Vector2(0.82f, 0f);
            area.anchorMax = new Vector2(0.985f, 0f);
            area.pivot = new Vector2(0.5f, 0f);
            area.sizeDelta = new Vector2(0f, 170f);
            area.anchoredPosition = new Vector2(0f, 24f);

            var layout = area.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 14f;
            layout.childAlignment = TextAnchor.LowerCenter;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _primaryAction = UiFactory.Button(area, "Pass", theme, () => OnPrimaryAction());
            _primaryAction.gameObject.name = "Primary action";
            _primaryLabel = _primaryAction.GetComponentInChildren<Text>();
            UiFactory.Size(_primaryAction.gameObject, height: 76f);

            _resignAction = UiFactory.Button(area, "Resign", theme, () => Run(Resign), primary: false);
            _resignAction.gameObject.name = "Resign";
        }

        // A card shown large at the side of the screen away from the pointer; it never takes clicks.
        private void BuildHoverPreview(UiTheme theme)
        {
            _hoverPreview = UiFactory.Create("Hover preview", transform);
            _hoverPreview.anchorMin = _hoverPreview.anchorMax = _hoverPreview.pivot = new Vector2(0.5f, 0.5f);
            _hoverPreview.sizeDelta = new Vector2(HoverCardWidth + 40f, CardView.HeightFor(HoverCardWidth) + 90f);
            var group = _hoverPreview.gameObject.AddComponent<CanvasGroup>();
            group.blocksRaycasts = false;
            group.interactable = false;
            var layout = _hoverPreview.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 8f;
            layout.childAlignment = TextAnchor.UpperCenter;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;

            _hoverCardHolder = UiFactory.Create("Card", _hoverPreview);
            UiFactory.Size(_hoverCardHolder.gameObject, HoverCardWidth, CardView.HeightFor(HoverCardWidth));
            _hoverCaption = UiFactory.Label(_hoverPreview, string.Empty, 26, theme.textPrimary, TextAnchor.UpperCenter);
            _hoverCaption.supportRichText = true;
            _hoverPreview.gameObject.SetActive(false);
        }

        private void BuildDetailOverlay(UiTheme theme)
        {
            _detail = UiFactory.Create("CardDetail", transform).gameObject;
            var root = (RectTransform)_detail.transform;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.82f);
            // Anywhere on the dimmed backdrop closes it.
            var close = root.gameObject.AddComponent<Button>();
            close.targetGraphic = root.GetComponent<Image>();
            close.transition = Selectable.Transition.None;
            close.onClick.AddListener(() => _detail.SetActive(false));

            var content = UiFactory.Row(root, "Content", 48f, TextAnchor.MiddleCenter);
            var contentRect = (RectTransform)content.transform;
            contentRect.anchorMin = contentRect.anchorMax = contentRect.pivot = new Vector2(0.5f, 0.5f);
            contentRect.sizeDelta = new Vector2(1500f, 700f);

            _detailCardHolder = UiFactory.Create("Card", content.transform);
            UiFactory.Size(_detailCardHolder.gameObject, DetailCardWidth, CardView.HeightFor(DetailCardWidth));

            _detailText = UiFactory.Label(content.transform, string.Empty, 30, theme.textPrimary, TextAnchor.MiddleLeft);
            UiFactory.Size(_detailText.gameObject, 800f);

            _detail.SetActive(false);
        }

        private GameObject BuildTextOverlay(UiTheme theme, string title, out Text body, out RectTransform panelRect)
        {
            var root = UiFactory.Create(title + " overlay", transform);
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.72f);

            var panel = UiFactory.Create("Panel", root);
            panelRect = panel;
            panel.anchorMin = panel.anchorMax = panel.pivot = new Vector2(0.5f, 0.5f);
            panel.sizeDelta = new Vector2(1000f, 800f);
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(28, 28, 24, 24);
            layout.spacing = 14f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            var heading = UiFactory.Label(panel, title, 40, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(heading.gameObject, height: 56f);

            UiFactory.ScrollList(panel, out var content);
            UiFactory.Flexible(content.parent.parent.gameObject, height: 1f);
            body = UiFactory.Label(content, string.Empty, 26, theme.textPrimary, TextAnchor.UpperLeft);

            var overlay = root.gameObject;
            UiFactory.Button(panel, "Close", theme, () => overlay.SetActive(false), primary: false);

            overlay.SetActive(false);
            return overlay;
        }

        // A line to say something to the table, above the chat's Close button. Players only.
        private void BuildChatEntry(UiTheme theme, RectTransform panel)
        {
            _chatError = UiFactory.Label(panel, string.Empty, 24, theme.danger, TextAnchor.MiddleLeft);
            UiFactory.Size(_chatError.gameObject, height: 30f);
            _chatError.transform.SetSiblingIndex(panel.childCount - 2);

            var row = UiFactory.Row(panel, "ChatEntry", 12f, TextAnchor.MiddleCenter);
            _chatEntry = row.gameObject;
            row.transform.SetSiblingIndex(panel.childCount - 2);
            UiFactory.Size(_chatEntry, height: UiFactory.ControlHeight);

            _chatInput = UiFactory.Input(row.transform, "Say something to the table", theme);
            _chatInput.characterLimit = 500;
            UiFactory.Flexible(_chatInput.gameObject, width: 1f);
            _chatInput.onEndEdit.AddListener(_ =>
            {
                if (KeyInput.EnterPressed)
                {
                    Run(SendChat);
                }
            });

            var send = UiFactory.Button(row.transform, "Send", theme, () => Run(SendChat));
            send.gameObject.name = "Send chat";
            UiFactory.Size(send.gameObject, 180f);
        }

        private bool AnyPopUpOrDrag() =>
            IsDragging || _detail.activeSelf || _logOverlay.activeSelf || _chatOverlay.activeSelf
            || _choices.IsOpen || _confirm.IsOpen;

        /// <summary>Wires a card to bring up the preview while a mouse rests on it.</summary>
        private void MakeHoverable(Component card, BoardCard data)
        {
            var target = card.gameObject.AddComponent<HoverTarget>();
            target.Entered = e =>
            {
                _hoverCard = data;
                _hoverSince = Time.unscaledTime;
                _hoverPointerX = e.position.x;
            };
            target.Exited = () =>
            {
                if (_hoverCard == data)
                {
                    HideHover();
                }
            };
        }

        private void ShowHover()
        {
            var card = _hoverCard;
            foreach (Transform child in _hoverCardHolder)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }

            var view = CardView.Create(_hoverCardHolder, card, HoverCardWidth, AppServices.Theme, showValue: false);
            view.name = "Preview card";
            UiFactory.Stretch(view);

            var notes = new List<string>();
            if (card.ValueIsModified)
            {
                notes.Add($"Value now {card.Value} (printed {card.BaseValue})");
            }

            var colorNote = BoardDisplay.ColorNote(_session?.State, card);
            if (colorNote != null)
            {
                notes.Add($"<b><color=#{ColorUtility.ToHtmlStringRGB(CardView.IndicatorColor(card.Color))}>{colorNote}</color></b>");
            }

            if (card.IsSuppressed)
            {
                notes.Add($"<b><color=#{ColorUtility.ToHtmlStringRGB(AppServices.Theme.danger)}>{BoardDisplay.SuppressedByText(card)}</color></b>");
            }

            _hoverCaption.text = string.Join("\n", notes);
            _hoverCaption.gameObject.SetActive(notes.Count > 0);

            // On whichever side of the screen the pointer isn't, so it never covers what you're pointing at.
            var onLeft = _hoverPointerX < Screen.width / 2f;
            _hoverPreview.anchorMin = _hoverPreview.anchorMax = new Vector2(onLeft ? 0.88f : 0.12f, 0.5f);
            _hoverPreview.anchoredPosition = Vector2.zero;
            _hoverPreview.gameObject.SetActive(true);
        }

        private void HideHover()
        {
            _hoverCard = null;
            if (_hoverPreview != null)
            {
                _hoverPreview.gameObject.SetActive(false);
            }
        }

        private void CloseOverlays()
        {
            _detail.SetActive(false);
            _logOverlay.SetActive(false);
            _chatOverlay.SetActive(false);
        }

        private void SetMessage(string text, bool isError = true, bool fromRefresh = false, float forSeconds = 0f)
        {
            _messageExpires = forSeconds > 0f ? Time.unscaledTime + forSeconds : 0f;
            _message.text = text ?? string.Empty;
            _message.color = isError ? AppServices.Theme.danger : AppServices.Theme.accent;
            _messageFromRefresh = fromRefresh && !string.IsNullOrEmpty(text);
        }

        private void ShowLoading(string text)
        {
            _loading.text = text ?? string.Empty;
            _loading.gameObject.SetActive(true);
        }

        // --- drawing -------------------------------------------------------------------------

        private void ClearTable()
        {
            // Destroy() waits for the end of the frame, so hide the old pieces too.
            foreach (Transform child in _table)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }
        }

        private void UpdateHeader(GameState state)
        {
            _roundLine.text = BoardDisplay.HeaderLine(state, _session.IsSpectating, DateTime.UtcNow);
            _roundLine.color = BoardDisplay.ClockIsUrgent(state, DateTime.UtcNow)
                ? AppServices.Theme.danger
                : AppServices.Theme.textMuted;
        }

        private void Render()
        {
            var state = _session?.State;
            if (state == null)
            {
                return;
            }

            var theme = AppServices.Theme;
            _loading.gameObject.SetActive(false);
            React(state);

            _banner.text = BoardDisplay.TurnBanner(state);
            _banner.color = BoardDisplay.BannerNeedsViewer(state) ? theme.accent : theme.textPrimary;
            UpdateHeader(state);
            _chatButtonLabel.text = state.ChatMessages.Count > 0 ? $"Chat ({state.ChatMessages.Count})" : "Chat";
            _chatEntry.SetActive(!BoardDisplay.IsSpectator(state));
            _logBody.text = LogText(state);
            _chatBody.text = state.ChatMessages.Count == 0
                ? "No messages yet."
                : string.Join("\n\n", state.ChatMessages.Select(m => $"{m.SenderUsername}: {m.MessageText}"));

            ClearTable();
            var zones = BoardLayout.Assign(state.Players, state.You.GamePlayerId);
            foreach (var player in state.Players)
            {
                BuildSeat(theme, state, player, zones[player.GamePlayerId]);
            }

            // The table is rebuilt, so a card being dragged out of the old hand is gone, and so is
            // whatever the mouse was resting on.
            CancelDrag();
            HideHover();
            BuildPiles(theme, state);
            BuildHand(theme, state);
            UpdateActions(state);
            SyncOverlays(state);
            StartFlights();
        }

        // --- reacting to what just happened ----------------------------------------------------------

        /// <summary>Works out what changed since the last look: sounds and buzzes, a moment's message, cards to slide in.</summary>
        private void React(GameState state)
        {
            var cues = BoardCues.Between(_previous, state);
            _previous = state;
            foreach (var cue in cues)
            {
                if (cue.Kind == CueKind.CardPlayed)
                {
                    _entering.Add(cue.CardId);
                }

                if (!string.IsNullOrEmpty(cue.Text))
                {
                    SetMessage(cue.Text, isError: false, forSeconds: 7f);
                }

                GameFeedback.Play(cue, state.You.GamePlayerId, AppServices.Device);
            }
        }

        private void NoteFlight(RectTransform card, BoardCard mood, int ownerId, float width)
        {
            if (!_entering.Contains(mood.CardId))
            {
                return;
            }

            // Hidden until its stand-in has flown to where it belongs.
            var group = card.gameObject.AddComponent<CanvasGroup>();
            group.alpha = 0f;
            _flights.Add(new Flight { Card = card, Group = group, Mood = mood, OwnerId = ownerId, Width = width });
        }

        private void StartFlights()
        {
            if (_flights.Count > 0)
            {
                StartCoroutine(FlyCards(_flights.ToList()));
            }

            _flights.Clear();
            _entering.Clear();
        }

        private IEnumerator FlyCards(List<Flight> flights)
        {
            yield return null; // the layout settles before positions mean anything
            foreach (var flight in flights)
            {
                if (flight.Card != null)
                {
                    StartCoroutine(FlyOne(flight));
                }
            }
        }

        // A copy of the card slides from whoever played it to its place on the table, growing smaller as it lands.
        private IEnumerator FlyOne(Flight flight)
        {
            const float seconds = 0.4f;
            var target = flight.Card.position;
            var source = SourceOf(flight.OwnerId) ?? target;

            var ghost = CardView.Create(transform, flight.Mood, flight.Width, AppServices.Theme, showValue: true);
            ghost.name = "Flying card";
            ghost.anchorMin = ghost.anchorMax = ghost.pivot = new Vector2(0.5f, 0.5f);
            var group = ghost.gameObject.AddComponent<CanvasGroup>();
            group.blocksRaycasts = false;
            group.interactable = false;
            ghost.SetSiblingIndex(_detail.transform.GetSiblingIndex());

            var start = Time.unscaledTime;
            while (ghost != null && Time.unscaledTime - start < seconds)
            {
                var t = (Time.unscaledTime - start) / seconds;
                var eased = 1f - (1f - t) * (1f - t) * (1f - t);
                ghost.position = Vector3.Lerp(source, target, eased);
                ghost.localScale = Vector3.one * Mathf.Lerp(1.4f, 1f, eased);
                yield return null;
            }

            if (ghost != null)
            {
                ghost.gameObject.SetActive(false);
                Destroy(ghost.gameObject);
            }

            if (flight.Group != null)
            {
                flight.Group.alpha = 1f;
            }
        }

        /// <summary>Where a card played by this player comes from: your hand, or their seat.</summary>
        private Vector3? SourceOf(int ownerId)
        {
            var state = _session?.State;
            if (state == null)
            {
                return null;
            }

            var owner = BoardDisplay.PlayerById(state, ownerId);
            var place = ownerId == state.You.GamePlayerId
                ? _table.Find("Hand")
                : owner != null ? _table.Find("Seat " + owner.Username) : null;
            return place != null ? place.position : (Vector3?)null;
        }

        private static string LogText(GameState state)
        {
            var sections = new List<string>();

            var effects = BoardDisplay.EffectLines(state);
            if (effects.Count > 0)
            {
                sections.Add("In force right now:\n" + string.Join("\n", effects.Select(e => "- " + e)));
            }

            sections.Add(state.RecentEvents.Count == 0
                ? "Nothing has happened yet."
                : string.Join("\n\n", state.RecentEvents.Select(e => e.Description)));
            return string.Join("\n\n", sections);
        }

        private static Rect Fraction(float minX, float minY, float maxX, float maxY) =>
            new Rect(minX, minY, maxX - minX, maxY - minY);

        private static RectTransform Place(Transform parent, string name, Rect fraction)
        {
            var rect = UiFactory.Create(name, parent);
            rect.anchorMin = new Vector2(fraction.xMin, fraction.yMin);
            rect.anchorMax = new Vector2(fraction.xMax, fraction.yMax);
            rect.offsetMin = rect.offsetMax = Vector2.zero;
            return rect;
        }

        private void BuildSeat(UiTheme theme, GameState state, BoardPlayer player, SeatZone zone)
        {
            var area = ZoneRects[zone];
            var seat = Place(_table, "Seat " + player.Username, area);
            var layout = seat.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 6f;
            layout.padding = new RectOffset(8, 8, 4, 4);
            layout.childAlignment = TextAnchor.UpperCenter;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;

            BuildPlate(theme, state, player, seat);

            var moods = BoardDisplay.MoodsOf(state, player.GamePlayerId);
            if (moods.Count == 0)
            {
                return;
            }

            // A suppressed mood is turned on its side, by convention, so it takes a card's height in width.
            var spacing = 10f;
            var availableWidth = area.width * ReferenceWidth - 16f;
            var slots = moods.Sum(m => m.IsSuppressed ? CardView.HeightOverWidth : 1f);
            var width = Mathf.Min(MoodWidth, (availableWidth - spacing * (moods.Count - 1)) / slots);
            var row = UiFactory.Row(seat, "Moods", spacing, TextAnchor.UpperCenter);
            foreach (var mood in moods)
            {
                var card = mood;
                UnityEngine.Events.UnityAction open = () => ShowDetail(card, "In play for " + player.Username);
                if (!card.IsSuppressed)
                {
                    var upright = CardView.Create(row.transform, card, width, theme, showValue: true, onClick: open);
                    MakeHoverable(upright, card);
                    NoteFlight(upright, card, player.GamePlayerId, width);
                    continue;
                }

                var side = CardView.HeightFor(width);
                var slot = UiFactory.Create("Suppressed slot", row.transform);
                UiFactory.Size(slot.gameObject, side, side);
                var tapped = CardView.Create(slot, card, width, theme, showValue: true, onClick: open, onItsSide: true);
                MakeHoverable(tapped, card);
                tapped.anchorMin = tapped.anchorMax = tapped.pivot = new Vector2(0.5f, 0.5f);
                tapped.anchoredPosition = Vector2.zero;
                NoteFlight(tapped, card, player.GamePlayerId, width);
            }
        }

        private void BuildPlate(UiTheme theme, GameState state, BoardPlayer player, RectTransform seat)
        {
            var isTurn = state.Round.CurrentTurnGamePlayerId == player.GamePlayerId && state.Game.Status != "completed";
            var plate = UiFactory.RowPanel(seat, theme, height: 70f);
            plate.GetComponent<Image>().color = isTurn
                ? Color.Lerp(theme.panel, theme.accent, 0.35f)
                : theme.panel;
            UiFactory.Flexible(plate.gameObject, width: 1f);

            var marker = UiFactory.Create("Turn", plate.transform);
            marker.gameObject.AddComponent<Image>().color = isTurn ? theme.accent : new Color(1f, 1f, 1f, 0.12f);
            UiFactory.Size(marker.gameObject, 16f, 16f);

            var name = player.Username
                + (player.IsBot ? "  (bot)" : string.Empty)
                + (player.GamePlayerId == state.You.GamePlayerId ? "  (you)" : string.Empty)
                + (player.Resigned ? "  (resigned)" : string.Empty);
            var stats = $"Hand {player.HandCount}  -  Deck {player.DeckCount}  -  {BoardDisplay.ScoreLine(player)}";
            UiFactory.TwoLineText(plate.transform, theme, name, stats);

            if (state.Round.HurtFeelingsGamePlayerId == player.GamePlayerId)
            {
                // The card alone is too small here to recognize, so name it.
                UiFactory.Label(plate.transform, "Hurt Feelings", 22, theme.danger, TextAnchor.MiddleRight, FontStyle.Bold);
                CardView.CreateHurtFeelings(plate.transform, 46f, theme);
            }
        }

        private void BuildPiles(UiTheme theme, GameState state)
        {
            var piles = Place(_table, "Piles", PilesRect);
            var row = UiFactory.Row(piles, "PilesRow", 40f, TextAnchor.MiddleCenter);
            var rowRect = (RectTransform)row.transform;
            rowRect.anchorMin = Vector2.zero;
            rowRect.anchorMax = Vector2.one;
            rowRect.offsetMin = rowRect.offsetMax = Vector2.zero;

            BuildPile(theme, row.transform, "Deck", state.DeckCount, null);

            var top = state.DiscardPile.LastOrDefault();
            BuildPile(theme, row.transform, "Discard", state.DiscardPile.Count, top);
        }

        private void BuildPile(UiTheme theme, Transform parent, string title, int count, BoardCard topCard)
        {
            var column = UiFactory.Create(title, parent);
            var layout = column.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 4f;
            layout.childAlignment = TextAnchor.UpperCenter;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;
            UiFactory.Size(column.gameObject, MoodWidth);

            if (topCard != null)
            {
                var card = topCard;
                var pileCard = CardView.Create(column, card, MoodWidth, theme, showValue: false,
                    onClick: () => ShowDetail(card, string.IsNullOrEmpty(card.LastOwnerName) ? "In the discard pile" : "Discarded from " + card.LastOwnerName));
                MakeHoverable(pileCard, card);
            }
            else
            {
                var empty = UiFactory.Create("Pile", column);
                empty.gameObject.AddComponent<Image>().color = title == "Deck" ? new Color(0.17f, 0.20f, 0.27f) : new Color(1f, 1f, 1f, 0.07f);
                UiFactory.Size(empty.gameObject, MoodWidth, CardView.HeightFor(MoodWidth));
                var label = UiFactory.Label(empty, title == "Deck" ? "DECK" : "EMPTY", 22, theme.textMuted, TextAnchor.MiddleCenter, FontStyle.Bold);
                UiFactory.Stretch(label.rectTransform);
            }

            // One line, centered on the pile even if it's wider than the card.
            var caption = UiFactory.Label(column, $"{title} {count}", 24, theme.textMuted);
            caption.horizontalOverflow = HorizontalWrapMode.Overflow;
            UiFactory.Size(caption.gameObject, height: 34f);
        }

        private void BuildHand(UiTheme theme, GameState state)
        {
            var hand = Place(_table, "Hand", HandRect);
            if (BoardDisplay.IsSpectator(state))
            {
                var note = UiFactory.Label(hand, "You're watching this game.", 28, theme.textMuted);
                UiFactory.Stretch(note.rectTransform);
                return;
            }

            if (state.You.Hand.Count == 0)
            {
                var none = UiFactory.Label(hand, "Your hand is empty.", 28, theme.textMuted);
                UiFactory.Stretch(none.rectTransform);
                return;
            }

            var count = state.You.Hand.Count;
            // Overlap the cards when they wouldn't otherwise fit across the table.
            var available = HandRect.width * ReferenceWidth;
            var spacing = count > 1 ? Mathf.Min(14f, (available - count * HandCardWidth) / (count - 1)) : 0f;
            var row = UiFactory.Row(hand, "HandRow", spacing, TextAnchor.LowerCenter);
            var rowRect = (RectTransform)row.transform;
            rowRect.anchorMin = Vector2.zero;
            rowRect.anchorMax = Vector2.one;
            rowRect.offsetMin = rowRect.offsetMax = Vector2.zero;

            // On your turn, the cards you can't play right now are dimmed.
            var canAct = BoardDisplay.CanAct(state);
            foreach (var cardInHand in state.You.Hand)
            {
                var card = cardInHand;
                var view = CardView.Create(row.transform, card, HandCardWidth, theme, showValue: true,
                    onClick: () => OnHandCard(card));
                MakeHoverable(view, card);
                var group = view.gameObject.AddComponent<CanvasGroup>();
                if (canAct && !card.IsPlayable)
                {
                    group.alpha = 0.5f;
                }

                if (canAct)
                {
                    var drag = view.gameObject.AddComponent<DraggableCard>();
                    drag.Began = e => BeginDrag(card, group, e);
                    drag.Moved = MoveDrag;
                    drag.Ended = e => EndDrag(card, e);
                }
            }
        }

        private void ShowDetail(BoardCard card, string where)
        {
            foreach (Transform child in _detailCardHolder)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }

            var cardRect = CardView.Create(_detailCardHolder, card, DetailCardWidth, AppServices.Theme, showValue: false);
            UiFactory.Stretch(cardRect);

            var lines = new List<string>
            {
                "<size=46><b>" + card.Name + "</b></size>",
                "<color=#9aa0aa>" + where + "</color>",
                string.Empty,
                ValueLine(card),
                string.IsNullOrWhiteSpace(card.RulesText) ? string.Empty : card.RulesText,
            };
            var colorNote = BoardDisplay.ColorNote(_session?.State, card);
            if (colorNote != null)
            {
                lines.Add(string.Empty);
                lines.Add($"<b><color=#{ColorUtility.ToHtmlStringRGB(CardView.IndicatorColor(card.Color))}>{colorNote}</color></b>");
            }

            if (card.IsSuppressed)
            {
                lines.Add(string.Empty);
                lines.Add($"<b><color=#{ColorUtility.ToHtmlStringRGB(AppServices.Theme.danger)}>{BoardDisplay.SuppressedByText(card)}</color></b>");
            }

            lines.Add(string.Empty);
            lines.Add("<color=#9aa0aa>Click anywhere to close.</color>");

            _detailText.supportRichText = true;
            _detailText.text = string.Join("\n", lines);
            _detail.SetActive(true);
        }

        private static string ValueLine(BoardCard card) =>
            card.ValueIsModified
                ? $"Value right now: {card.Value}  (printed {card.BaseValue})"
                : $"Value: {card.Value}";

        // --- acting ---------------------------------------------------------------------------

        /// <summary>Shows Pass / Advance turn / I'm ready and Resign for what the viewer can do right now.</summary>
        private void UpdateActions(GameState state)
        {
            var viewerSeated = !BoardDisplay.IsSpectator(state);
            var inProgress = state.Game.Status == "in_progress";
            var busy = _session.Busy;

            string primary = null;
            var primaryEnabled = false;
            if (BoardDisplay.NeedsAdvanceTurn(state))
            {
                primary = "Advance turn";
                primaryEnabled = true;
            }
            else if (BoardDisplay.NeedsReady(state))
            {
                primary = "I'm ready";
                primaryEnabled = true;
            }
            else if (viewerSeated && inProgress)
            {
                primary = "Pass";
                primaryEnabled = BoardDisplay.CanAct(state);
            }

            _primaryAction.gameObject.SetActive(primary != null);
            if (primary != null)
            {
                _primaryLabel.text = primary;
                _primaryAction.interactable = primaryEnabled && !busy;
            }

            var showResign = viewerSeated && inProgress;
            _resignAction.gameObject.SetActive(showResign);
            _resignAction.interactable = BoardDisplay.CanResign(state) && !busy;

            _actions.SetActive(primary != null || showResign);
        }

        private void OnPrimaryAction()
        {
            var state = _session?.State;
            if (state == null)
            {
                return;
            }

            if (BoardDisplay.NeedsAdvanceTurn(state))
            {
                Run(() => Act(() => _session.AdvanceTurnAsync()));
            }
            else if (BoardDisplay.NeedsReady(state))
            {
                Run(() => Act(() => _session.MarkReadyAsync()));
            }
            else
            {
                Run(() => Act(() => _session.PassAsync()));
            }
        }

        private async Task Resign()
        {
            if (!await _confirm.AskAsync("Resign this game? This cannot be undone.", "Resign", "Keep playing"))
            {
                return;
            }

            await Act(() => _session.ResignAsync());
        }

        /// <summary>Runs one action: greys the buttons while it's in flight, then shows what came of it.</summary>
        private async Task Act(Func<Task<BoardActionResult>> action)
        {
            SetMessage(string.Empty);
            var pending = action();
            UpdateActions(_session.State);
            var result = await pending;
            if (this == null)
            {
                return;
            }

            ShowResult(result);
            UpdateActions(_session.State);
            SyncOverlays(_session.State);
        }

        private void ShowResult(BoardActionResult result)
        {
            if (result.Ok)
            {
                // A round or game result the board already announced from the new state stays.
                if (!string.IsNullOrEmpty(result.Notice) && _messageExpires == 0f)
                {
                    SetMessage(result.Notice, isError: false);
                }
            }
            else
            {
                SetMessage(result.Message ?? "That didn't work.", isError: true);
            }
        }

        // --- dragging a card to play it ----------------------------------------------------------

        private Camera UiCamera => GetComponentInParent<Canvas>().worldCamera;

        private void BeginDrag(BoardCard card, CanvasGroup original, PointerEventData eventData)
        {
            var state = _session?.State;
            if (state == null || !BoardDisplay.CanAct(state) || _session.Busy)
            {
                return;
            }

            CancelDrag();
            HideHover();
            var ghost = CardView.Create(transform, card, HandCardWidth * 1.2f, AppServices.Theme, showValue: true);
            ghost.name = "Drag ghost";
            ghost.anchorMin = ghost.anchorMax = ghost.pivot = new Vector2(0.5f, 0.5f);
            var ghostGroup = ghost.gameObject.AddComponent<CanvasGroup>();
            ghostGroup.blocksRaycasts = false;
            ghostGroup.interactable = false;
            // Above the table and the buttons, below the pop-ups.
            ghost.SetSiblingIndex(_detail.transform.GetSiblingIndex());
            _dragGhost = ghost;

            _draggedCardGroup = original;
            original.alpha = 0.3f;

            _dropZone.gameObject.SetActive(true);
            MoveDrag(eventData);
        }

        private void MoveDrag(PointerEventData eventData)
        {
            if (_dragGhost == null)
            {
                return;
            }

            if (RectTransformUtility.ScreenPointToWorldPointInRectangle(
                    (RectTransform)transform, eventData.position, UiCamera, out var world))
            {
                _dragGhost.position = world;
            }

            DragIsOverDropZone = RectTransformUtility.RectangleContainsScreenPoint(_dropZone, eventData.position, UiCamera);
            var accent = AppServices.Theme.accent;
            _dropZoneImage.color = new Color(accent.r, accent.g, accent.b, DragIsOverDropZone ? 0.22f : 0.08f);
        }

        private void EndDrag(BoardCard card, PointerEventData eventData)
        {
            if (_dragGhost == null)
            {
                return;
            }

            MoveDrag(eventData);
            var dropped = DragIsOverDropZone;
            CancelDrag();
            if (dropped)
            {
                PlayDropped(card);
            }
        }

        /// <summary>Puts the dragged card back: removes the ghost and the drop zone. Safe to call when nothing is being dragged.</summary>
        private void CancelDrag()
        {
            if (_dragGhost != null)
            {
                _dragGhost.gameObject.SetActive(false);
                Destroy(_dragGhost.gameObject);
                _dragGhost = null;
            }

            if (_draggedCardGroup != null)
            {
                _draggedCardGroup.alpha = 1f;
                _draggedCardGroup = null;
            }

            DragIsOverDropZone = false;
            if (_dropZone != null)
            {
                _dropZone.gameObject.SetActive(false);
            }
        }

        /// <summary>
        /// A card let go over the table. One that asks for nothing is simply played -- dragging it
        /// there was the decision; one with choices opens the same form a click would.
        /// </summary>
        private void PlayDropped(BoardCard card)
        {
            var state = _session?.State;
            if (state == null || !BoardDisplay.CanAct(state) || _session.Busy)
            {
                return;
            }

            var form = ChoiceForm.ForCard(state, card);
            if (form.Fields.Count > 0)
            {
                OpenPlayForm(state, card);
            }
            else if (form.Problem != null)
            {
                SetMessage(form.Problem);
            }
            else
            {
                Run(async () =>
                {
                    SetMessage(string.Empty);
                    var pending = _session.PlayAsync(card, form.BuildChoices());
                    UpdateActions(_session.State);
                    var result = await pending;
                    if (this == null)
                    {
                        return;
                    }

                    ShowResult(result);
                    UpdateActions(_session.State);
                    SyncOverlays(_session.State);
                });
            }
        }

        private void OnHandCard(BoardCard card)
        {
            var state = _session?.State;
            if (state != null && BoardDisplay.CanAct(state) && !_session.Busy)
            {
                OpenPlayForm(state, card);
            }
            else
            {
                ShowDetail(card, "In your hand");
            }
        }

        private void OpenPlayForm(GameState state, BoardCard card)
        {
            var form = ChoiceForm.ForCard(state, card);
            _playingCard = card;
            _choices.Open(
                form,
                new ChoicePrompt
                {
                    Title = card.Name,
                    Context = ValueLine(card) + (string.IsNullOrWhiteSpace(card.RulesText) ? string.Empty : "\n" + card.RulesText),
                    Card = card,
                    SubmitLabel = "Play card",
                    Cancellable = true,
                },
                () => Run(() => SubmitPlay(card, form)),
                () => _playingCard = null);
        }

        private async Task SubmitPlay(BoardCard card, ChoiceForm form)
        {
            // Legal answers that would make the card do nothing get a "did you mean that?" first.
            foreach (var question in form.Confirmations())
            {
                if (!await _confirm.AskAsync(question, "Play anyway", "Cancel"))
                {
                    return;
                }
            }

            _choices.SetBusy(true, "Playing...");
            var result = await _session.PlayAsync(card, form.BuildChoices());
            if (this == null)
            {
                return;
            }

            _choices.SetBusy(false);
            if (result.Ok)
            {
                _choices.Close();
                _playingCard = null;
                ShowResult(result);
            }
            else if (_choices.IsOpen)
            {
                // Most refusals are a fixable mistake in the choices, so the form stays up for another try.
                _choices.ShowError(result.Message ?? "Couldn't play that card.");
            }
            else
            {
                ShowResult(result);
            }

            UpdateActions(_session.State);
            SyncOverlays(_session.State);
        }

        private async Task SubmitDecision(ChoiceForm form)
        {
            _choices.SetBusy(true, "Sending...");
            var result = await _session.RespondAsync(form.BuildChoices());
            if (this == null)
            {
                return;
            }

            _choices.SetBusy(false);

            // Either way the next look at the board decides what's open: a refusal usually means
            // the options moved, so the question is rebuilt fresh from the latest state.
            _choices.Close();
            _decisionKey = null;
            ShowResult(result);
            UpdateActions(_session.State);
            SyncOverlays(_session.State);

            if (!result.Ok && _choices.IsOpen)
            {
                _choices.ShowError(result.Message ?? "Couldn't send your response.");
            }
        }

        private async Task SendChat()
        {
            var result = await _session.SendChatAsync(_chatInput.text);
            if (this == null)
            {
                return;
            }

            _chatError.text = result.Ok ? string.Empty : result.Message ?? "Couldn't send that message.";
            if (result.Ok)
            {
                _chatInput.text = string.Empty;
            }
        }

        /// <summary>
        /// Keeps the pop-ups matching the game: a play form for a card you can no longer play
        /// closes, a question the game has for you opens (once), and a warning about a repeating
        /// board is shown once.
        /// </summary>
        private void SyncOverlays(GameState state)
        {
            if (_playingCard != null)
            {
                var stillPlayable = _choices.IsOpen
                    && BoardDisplay.CanAct(state)
                    && state.You.Hand.Any(c => c.CardId == _playingCard.CardId);
                if (!stillPlayable)
                {
                    if (_choices.IsOpen)
                    {
                        _choices.Close();
                    }

                    _playingCard = null;
                }
            }

            var decision = state.Round.PendingDecision;
            if (decision != null && decision.IsYou && decision.Field != null && !_session.IsSpectating)
            {
                var key = DecisionKey(state, decision);
                if (_decisionKey != key || !_choices.IsOpen)
                {
                    OpenDecision(state, decision, key);
                }
            }
            else if (_decisionKey != null)
            {
                if (_choices.IsOpen && _playingCard == null)
                {
                    _choices.Close();
                }

                _decisionKey = null;
            }

            var warning = BoardDisplay.LoopWarningText(state);
            if (warning == null)
            {
                _loopKey = null;
            }
            else if (_loopKey != state.Game.LoopWarning.OccurrenceCount.ToString() && !_confirm.IsOpen)
            {
                _loopKey = state.Game.LoopWarning.OccurrenceCount.ToString();
                Run(async () => await _confirm.AskAsync(warning, "OK", null));
            }
        }

        private static string DecisionKey(GameState state, PendingDecision decision) =>
            $"{state.Round.RoundNumber}|{decision.DecisionType}|{decision.PlayedCardId}|{decision.TargetGamePlayerId}|{decision.Field.Key}";

        private void OpenDecision(GameState state, PendingDecision decision, string key)
        {
            var form = ChoiceForm.ForDecision(state, decision);
            var played = decision.PlayedCardId.HasValue
                ? state.InPlay.FirstOrDefault(c => c.CardId == decision.PlayedCardId.Value)
                : null;

            var context = new List<string>();
            var initiator = BoardDisplay.PlayerById(state, decision.InitiatingGamePlayerId);
            if (initiator != null && !string.IsNullOrEmpty(decision.PlayedCardName))
            {
                context.Add($"{initiator.Username} played {decision.PlayedCardName}.");
            }

            context.AddRange(BoardDisplay.ScoringPreviewLines(state));

            _decisionKey = key;
            _choices.Open(
                form,
                new ChoicePrompt
                {
                    Title = BoardDisplay.DecisionTitle(decision),
                    Context = string.Join("\n", context),
                    Card = played,
                    SubmitLabel = "Respond",
                    Cancellable = false,
                },
                () => Run(() => SubmitDecision(form)),
                null);
        }
    }
}
