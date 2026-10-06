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
        private RectTransform _teamPanel;
        private Text _teamTitle;
        private Text _teamStatus;
        private RectTransform _teamButtons;
        private string _teamKey;
        private GameObject _teammateOverlay;
        private Text _teammateTitle;
        private RectTransform _teammateCards;
        private GameObject _teammateButton;
        private string _chatChannel = "table";
        private Button _chatToTable;
        private Button _chatToTeam;
        private GameObject _chatChannelRow;
        private RectTransform _goalPanel;
        private Text _goalText;
        private Button _hintButton;
        private GameObject _firstPlayerOverlay;
        private Text _firstPlayerText;
        private Text _letOtherGoLabel;
        private RectTransform _dropZone;
        private Image _dropZoneImage;
        private RectTransform _dragGhost;
        private CanvasGroup _draggedCardGroup;
        private DraftView _draft;
        private DiscardPileOverlay _discardOverlay;
        private CardListOverlay _deckList;
        private List<BoardCard> _deckCards;
        private ChaosOfferOverlay _chaosOverlay;
        private LoopShortcutOverlay _shortcutOverlay;
        private string _shortcutKey;
        private bool _decksRequested;
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

        /// <summary>A puzzle's goal, as shown on the board; null outside puzzles.</summary>
        public string GoalText => _goalPanel != null && _goalPanel.gameObject.activeSelf ? _goalText.text : null;

        /// <summary>The Hint button is offered (a puzzle that has a hint).</summary>
        public bool HintAvailable => _hintButton != null && _hintButton.gameObject.activeSelf;

        /// <summary>The box asking you to name (or agree to) who on your team acts is up.</summary>
        public bool TeamDecisionOpen => _teamPanel != null && _teamPanel.gameObject.activeSelf;

        /// <summary>Your partner's hand (Open Team Play) is showing.</summary>
        public bool TeammateHandOpen => _teammateOverlay != null && _teammateOverlay.activeSelf;

        /// <summary>Which channel the chat box sends to: "table", or "team" in Open Team Play.</summary>
        public string ChatChannel => _chatChannel;

        /// <summary>The box asking you, as the previous game's loser, who goes first is up.</summary>
        public bool FirstPlayerChoiceOpen => _firstPlayerOverlay != null && _firstPlayerOverlay.activeSelf;

        /// <summary>A larger card is showing beside the pointer, because the mouse has rested on a card.</summary>
        public bool HoverPreviewShown => _hoverPreview != null && _hoverPreview.gameObject.activeSelf;

        /// <summary>A card is being dragged from the hand.</summary>
        public bool IsDragging => _dragGhost != null;

        /// <summary>The dragged card is over the part of the table where letting go plays it.</summary>
        public bool DragIsOverDropZone { get; private set; }

        /// <summary>How many seat zones, piles and hand areas are currently drawn; tests use it to see what's on the table.</summary>
        public int TableChildCount => _table != null ? _table.childCount : 0;

        /// <summary>The whole list of the shared deck; tests read it.</summary>
        public CardListOverlay DeckList => _deckList;

        /// <summary>The whole discard pile, where an effect can make a card playable; tests read it.</summary>
        public DiscardPileOverlay DiscardOverlay => _discardOverlay;

        /// <summary>Chaos Draft's start-of-round choice, and the loop shortcut; tests read what they show.</summary>
        public ChaosOfferOverlay ChaosOverlay => _chaosOverlay;

        public LoopShortcutOverlay LoopShortcut => _shortcutOverlay;

        /// <summary>The draft and deck-building stage; tests read what it shows.</summary>
        public DraftView Draft => _draft;

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
            _shortcutKey = null;
            _decksRequested = false;
            _discardOverlay.Close();
            _deckList.Close();
            _deckCards = null;
            _chaosOverlay.Hide();
            _shortcutOverlay.Close();
            _firstPlayerOverlay.SetActive(false);
            _teamPanel.gameObject.SetActive(false);
            _teamKey = null;
            _chatChannel = "table";
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
                _draft.Hide();
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
            if (_confirm.Dismiss() || _shortcutOverlay.Dismiss() || _chaosOverlay.Back() || _discardOverlay.Dismiss() || _deckList.Dismiss())
            {
                return true;
            }

            if (_detail.activeSelf || _logOverlay.activeSelf || _chatOverlay.activeSelf || _teammateOverlay.activeSelf)
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

            _draft = new DraftView(
                transform, theme,
                new DraftActions
                {
                    Inspect = ShowDetail,
                    PickQuickDraft = (round, stage, ids) => Act(() => _session.PickQuickDraftAsync(round, stage, ids)),
                    PickWinston = take => Act(() => _session.PickWinstonDraftAsync(take)),
                    PickGrid = (axis, index) => Act(() => _session.PickGridDraftAsync(axis, index)),
                    PickRotisserie = (cardId, tiered) => Act(() => _session.PickRotisserieDraftAsync(cardId, tiered)),
                    Confirm = (message, yes, no) => _confirm.AskAsync(message, yes, no),
                    SubmitDeck = ids => Act(() => _session.SubmitDraftDeckAsync(ids)),
                    SubmitDuelDeck = (savedId, text) => Act(() => _session.SubmitDuelDeckAsync(savedId, text)),
                },
                HeaderHeight + 12f);

            BuildDropZone(theme);
            BuildGoalPanel(theme);
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

            _discardOverlay = new DiscardPileOverlay(transform, theme) { Chosen = OnDiscardCard };
            _deckList = new CardListOverlay(transform, theme) { Chosen = card => ShowDetail(card, "In the deck") };
            BuildHoverPreview(theme);
            BuildTeammateOverlay(theme);
            BuildDetailOverlay(theme);
            _logOverlay = BuildTextOverlay(theme, "Recent events", out _logBody, out _);
            _chatOverlay = BuildTextOverlay(theme, "Chat", out _chatBody, out var chatPanel);
            BuildChatEntry(theme, chatPanel);

            BuildFirstPlayerOverlay(theme);
            BuildTeamPanel(theme);

            _chaosOverlay = new ChaosOfferOverlay(transform, theme)
            {
                Attach = (effectId, cardId, partner) => Run(async () =>
                {
                    if (partner != null
                        && !await _confirm.AskAsync($"Attach this effect to {partner}'s card? They'll need to confirm before it's final.", "Attach", "Cancel"))
                    {
                        return;
                    }

                    var team = _session.State?.ChaosOffer?.Offer?.IsTeamOffer == true;
                    await Act(() => _session.ChooseChaosEffectAsync(effectId, cardId, team));
                }),
                Confirm = approve => Run(() => Act(() => _session.ConfirmChaosEffectAsync(approve))),
            };
            _shortcutOverlay = new LoopShortcutOverlay(transform, theme)
            {
                Apply = count => Run(() => Act(() => _session.ApplyChaosLoopShortcutAsync(count))),
            };

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

            // Open Team Play: your partner's hand, which you're allowed to see.
            var partner = UiFactory.Button(bar, "Partner's hand", theme, () => OpenTeammateHand(), primary: false);
            partner.gameObject.name = "Partner hand button";
            PlaceHeaderButton((RectTransform)partner.transform, 440f);
            ((RectTransform)partner.transform).sizeDelta = new Vector2(260f, UiFactory.ControlHeight);
            _teammateButton = partner.gameObject;
            _teammateButton.SetActive(false);
        }

        private static void PlaceHeaderButton(RectTransform rect, float fromRight)
        {
            rect.anchorMin = rect.anchorMax = rect.pivot = new Vector2(1f, 0.5f);
            rect.sizeDelta = new Vector2(180f, UiFactory.ControlHeight);
            rect.anchoredPosition = new Vector2(-fromRight, 0f);
        }

        // A puzzle's goal, kept in view in the empty top-left of the table, and a button for its hint (not every
        // puzzle has one). The hint is only shown when asked for, since it can give the game away.
        private void BuildGoalPanel(UiTheme theme)
        {
            _goalPanel = UiFactory.Create("Puzzle goal", transform);
            _goalPanel.anchorMin = _goalPanel.anchorMax = _goalPanel.pivot = new Vector2(0f, 1f);
            _goalPanel.sizeDelta = new Vector2(520f, 0f);
            _goalPanel.anchoredPosition = new Vector2(24f, -HeaderHeight - 14f);
            _goalPanel.gameObject.AddComponent<Image>().color = new Color(theme.panel.r, theme.panel.g, theme.panel.b, 0.92f);
            var layout = _goalPanel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(22, 22, 16, 16);
            layout.spacing = 10f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            _goalPanel.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;

            UiFactory.Label(_goalPanel, "Goal", 26, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            _goalText = UiFactory.Label(_goalPanel, string.Empty, 26, theme.textPrimary, TextAnchor.UpperLeft);
            _hintButton = UiFactory.Button(_goalPanel, "Hint", theme, () => Run(ShowHint), primary: false);
            _hintButton.gameObject.name = "Hint";
            UiFactory.Size(_hintButton.gameObject, height: 56f);

            _goalPanel.gameObject.SetActive(false);
        }

        private async Task ShowHint()
        {
            var hint = _session?.State?.Game.PuzzleHint;
            if (string.IsNullOrEmpty(hint))
            {
                return;
            }

            // Reading it costs the "Puzzle Solver" achievement for this solve, so the server hears first.
            await _session.MarkHintViewedAsync();
            if (this != null)
            {
                await _confirm.AskAsync(hint, "OK", null);
            }
        }

        private void UpdateGoal(GameState state)
        {
            var puzzle = BoardDisplay.IsPuzzle(state);
            _goalPanel.gameObject.SetActive(puzzle);
            if (!puzzle)
            {
                return;
            }

            _goalText.text = string.IsNullOrEmpty(state.Game.PuzzleDescription) ? "Reach the puzzle's goal." : state.Game.PuzzleDescription;
            _hintButton.gameObject.SetActive(!string.IsNullOrEmpty(state.Game.PuzzleHint));
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

        // Open Team Play: the partner's hand, laid out where it can be read; the board behind it is dimmed.
        private void BuildTeammateOverlay(UiTheme theme)
        {
            var root = UiFactory.Create("Partner hand overlay", transform);
            _teammateOverlay = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.82f);
            var close = root.gameObject.AddComponent<Button>();
            close.targetGraphic = root.GetComponent<Image>();
            close.transition = Selectable.Transition.None;
            close.onClick.AddListener(() => _teammateOverlay.SetActive(false));

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = panel.anchorMax = panel.pivot = new Vector2(0.5f, 0.5f);
            panel.sizeDelta = new Vector2(1780f, 760f);
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 20f;
            layout.padding = new RectOffset(20, 20, 10, 10);
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _teammateTitle = UiFactory.Label(panel, string.Empty, 44, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            UiFactory.Size(_teammateTitle.gameObject, height: 64f);

            _teammateCards = UiFactory.Create("Cards", panel);
            var grid = _teammateCards.gameObject.AddComponent<GridLayoutGroup>();
            grid.cellSize = new Vector2(200f, CardView.HeightFor(200f));
            grid.spacing = new Vector2(16f, 16f);
            grid.childAlignment = TextAnchor.UpperCenter;
            grid.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
            grid.constraintCount = 8;

            var hint = UiFactory.Label(panel, "Click a card to read it. Click anywhere else to close.", 24, theme.textMuted);
            UiFactory.Size(hint.gameObject, height: 36f);

            _teammateOverlay.SetActive(false);
        }

        private void OpenTeammateHand()
        {
            RefreshTeammateHand();
            _teammateOverlay.SetActive(true);
        }

        private void RefreshTeammateHand()
        {
            var state = _session?.State;
            foreach (Transform child in _teammateCards)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }

            if (state?.You.TeammateHand == null)
            {
                return;
            }

            var name = BoardDisplay.PlayerById(state, state.You.TeammateGamePlayerId)?.Username ?? "Your partner";
            _teammateTitle.text = $"{name}'s hand";
            foreach (var cardInHand in state.You.TeammateHand)
            {
                var card = cardInHand;
                CardView.Create(_teammateCards, card, 200f, AppServices.Theme, showValue: false,
                    onClick: () => ShowDetail(card, $"In {name}'s hand"));
            }
        }

        // Where a team says who on it acts: either partner names one, the other agrees or sends it back. Only the
        // box itself takes clicks, so the board stays usable around it.
        private void BuildTeamPanel(UiTheme theme)
        {
            _teamPanel = UiFactory.Create("Team decision", transform);
            _teamPanel.anchorMin = _teamPanel.anchorMax = _teamPanel.pivot = new Vector2(0.5f, 0.6f);
            _teamPanel.sizeDelta = new Vector2(1100f, 0f);
            _teamPanel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = _teamPanel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(40, 40, 28, 28);
            layout.spacing = 22f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            _teamPanel.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;

            _teamTitle = UiFactory.Label(_teamPanel, string.Empty, 40, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            _teamStatus = UiFactory.Label(_teamPanel, string.Empty, 30, theme.textPrimary, TextAnchor.MiddleCenter);
            _teamButtons = UiFactory.Row(_teamPanel, "Buttons", 24f, TextAnchor.MiddleCenter).GetComponent<RectTransform>();

            _teamPanel.gameObject.SetActive(false);
        }

        private void ShowTeamDecision(GameState state)
        {
            var decision = state.TeamDecision;
            var key = $"{state.Round.RoundNumber}|{decision.DecisionType}|{decision.Phase}|{decision.ProposedGamePlayerId}|{decision.TeamId}";
            if (_teamKey == key && _teamPanel.gameObject.activeSelf)
            {
                return;
            }

            _teamKey = key;
            _teamTitle.text = BoardDisplay.TeamDecisionTitle(state);
            _teamStatus.text = BoardDisplay.TeamDecisionStatus(state);
            foreach (Transform child in _teamButtons)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }

            var theme = AppServices.Theme;
            if (BoardDisplay.NeedsTeamProposal(state))
            {
                foreach (var id in decision.CandidateGamePlayerIds)
                {
                    var player = BoardDisplay.PlayerById(state, id);
                    var seat = id;
                    var label = player != null && player.GamePlayerId == state.You.GamePlayerId ? "Me" : player?.Username ?? "?";
                    var button = UiFactory.Button(_teamButtons, label, theme, () => Run(() => Act(() => _session.ProposeTeamDecisionAsync(seat))));
                    button.gameObject.name = "Propose " + label;
                    UiFactory.Size(button.gameObject, 360f);
                }
            }
            else
            {
                var agree = UiFactory.Button(_teamButtons, "Agree", theme, () => Run(() => Act(() => _session.ConfirmTeamDecisionAsync(true))));
                agree.gameObject.name = "Agree";
                UiFactory.Size(agree.gameObject, 320f);
                var disagree = UiFactory.Button(_teamButtons, "Disagree", theme, () => Run(() => Act(() => _session.ConfirmTeamDecisionAsync(false))), primary: false);
                disagree.gameObject.name = "Disagree";
                UiFactory.Size(disagree.gameObject, 320f);
            }

            _teamPanel.gameObject.SetActive(true);
        }

        // Game 2 or 3 of a match: the previous loser, who can see their opening hand behind this, says who
        // goes first. There's no way to dismiss it without answering, since nobody can play until then.
        private void BuildFirstPlayerOverlay(UiTheme theme)
        {
            var root = UiFactory.Create("First player overlay", transform);
            _firstPlayerOverlay = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.55f);

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = panel.anchorMax = panel.pivot = new Vector2(0.5f, 0.62f);
            panel.sizeDelta = new Vector2(1100f, 0f);
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(40, 40, 32, 32);
            layout.spacing = 26f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            panel.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;

            UiFactory.Label(panel, "Who goes first?", 44, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            _firstPlayerText = UiFactory.Label(panel, string.Empty, 30, theme.textPrimary, TextAnchor.MiddleCenter);

            var buttons = UiFactory.Row(panel, "Buttons", 24f, TextAnchor.MiddleCenter);
            var me = UiFactory.Button(buttons.transform, "I'll go first", theme, () => Run(() => ChoosePlayFirst(true)));
            me.gameObject.name = "Go first";
            UiFactory.Size(me.gameObject, 420f);
            var other = UiFactory.Button(buttons.transform, "Let them go first", theme, () => Run(() => ChoosePlayFirst(false)), primary: false);
            other.gameObject.name = "Let them go first";
            _letOtherGoLabel = other.GetComponentInChildren<Text>();
            UiFactory.Size(other.gameObject, 520f);

            _firstPlayerOverlay.SetActive(false);
        }

        private async Task ChoosePlayFirst(bool playFirst)
        {
            await Act(() => _session.ChoosePlayFirstAsync(playFirst));
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

            // Open Team Play can also talk to just your partner.
            var channels = UiFactory.Row(panel, "ChatChannels", 12f, TextAnchor.MiddleLeft);
            _chatChannelRow = channels.gameObject;
            channels.transform.SetSiblingIndex(panel.childCount - 2);
            UiFactory.Size(_chatChannelRow, height: 56f);
            _chatToTable = UiFactory.Button(channels.transform, "To the table", theme, () => SetChatChannel("table"), primary: false);
            _chatToTable.gameObject.name = "Chat to table";
            UiFactory.Size(_chatToTable.gameObject, 280f, 56f);
            _chatToTeam = UiFactory.Button(channels.transform, "To my partner", theme, () => SetChatChannel("team"), primary: false);
            _chatToTeam.gameObject.name = "Chat to partner";
            UiFactory.Size(_chatToTeam.gameObject, 280f, 56f);
            _chatChannelRow.SetActive(false);

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
            IsDragging || _detail.activeSelf || _discardOverlay.IsOpen || _deckList.IsOpen || _logOverlay.activeSelf || _chatOverlay.activeSelf || _teammateOverlay.activeSelf
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

            if (card.ChaosEffect != null)
            {
                notes.Add($"<b><color=#{ColorUtility.ToHtmlStringRGB(CardView.ChaosColor(card.ChaosEffect.Rarity))}>{ChaosDisplay.EffectOnCard(card.ChaosEffect)}</color></b>");
            }

            var chaosValue = ChaosDisplay.ValueNote(card);
            if (chaosValue != null)
            {
                notes.Add(chaosValue);
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

        private void SetChatChannel(string channel)
        {
            _chatChannel = channel;
            RefreshChatChannelButtons();
        }

        private void RefreshChatChannelButtons()
        {
            var theme = AppServices.Theme;
            foreach (var (button, channel) in new[] { (_chatToTable, "table"), (_chatToTeam, "team") })
            {
                var chosen = _chatChannel == channel;
                button.GetComponent<Image>().color = chosen ? theme.accent : Color.Lerp(theme.panel, Color.white, 0.10f);
                button.GetComponentInChildren<Text>().color = chosen ? theme.background : theme.textPrimary;
            }
        }

        private void CloseOverlays()
        {
            _teammateOverlay.SetActive(false);
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
                : string.Join("\n\n", state.ChatMessages.Select(m => (m.Channel == "team" ? "[team] " : string.Empty) + $"{m.SenderUsername}: {m.MessageText}"));
            _chatChannelRow.SetActive(state.Game.Format == GameSetup.OpenTeamFormat && !BoardDisplay.IsSpectator(state));
            if (!_chatChannelRow.activeSelf)
            {
                _chatChannel = "table";
            }

            RefreshChatChannelButtons();
            _teammateButton.SetActive(state.You.TeammateHand != null);
            if (_teammateOverlay.activeSelf)
            {
                RefreshTeammateHand();
            }

            // Drafting and deck building take the place of the table.
            if (DuelDeckDisplay.InStage(state) && !AppServices.Decklists.Loaded && !_decksRequested)
            {
                // The decks to choose from: asked for once, and the stage redraws when they arrive.
                _decksRequested = true;
                Run(async () =>
                {
                    await AppServices.Decklists.RefreshAsync();
                    if (this != null && _session?.State != null)
                    {
                        Render();
                    }
                });
            }

            if (DraftDisplay.InDraftStage(state) || DuelDeckDisplay.InStage(state))
            {
                ClearTable();
                CancelDrag();
                HideHover();
                _draft.Render(state);
                UpdateActions(state);
                SyncOverlays(state);
                return;
            }

            _draft.Hide();
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
            UpdateGoal(state);
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

            var teamTag = BoardDisplay.TeamTag(state, player);
            var name = player.Username
                + (player.IsBot ? "  (bot)" : string.Empty)
                + (player.GamePlayerId == state.You.GamePlayerId ? "  (you)" : string.Empty)
                + (teamTag != null ? $"  ({teamTag})" : string.Empty)
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

            // Tapping the deck lists every card it started with, if the table shares one deck.
            BuildPile(theme, row.transform, "Deck", BoardDisplay.DeckCaption(state), null, onClick: OpenDeckList,
                hint: BoardDisplay.HasSharedDeck(state) ? "see the list" : null, dim: state.DeckCount == 0);

            // An effect (Harmony, Grief...) can make a card in the pile playable: say so, since the pile shows only its top card.
            var top = state.DiscardPile.LastOrDefault();
            var playable = BoardDisplay.CanAct(state) && state.DiscardPile.Any(c => c.IsPlayable);
            BuildPile(theme, row.transform, "Discard",
                $"Discard {state.DiscardPile.Count}", top,
                onClick: OpenDiscardPile, highlight: playable, hint: playable ? "play from it" : null);
        }

        private void OpenDeckList()
        {
            var state = _session?.State;
            if (state == null)
            {
                return;
            }

            if (!BoardDisplay.HasSharedDeck(state))
            {
                SetMessage(
                    state.Game.Status == "waiting"
                        ? "The deck hasn't been dealt yet."
                        : "Each player has their own deck in this game, so there's no single deck list to show.",
                    isError: false, forSeconds: 6f);
                return;
            }

            Run(async () =>
            {
                if (_deckCards == null)
                {
                    SetMessage("Loading the deck list...", isError: false);
                    var result = await _session.GetDeckListAsync();
                    if (this == null)
                    {
                        return;
                    }

                    if (!result.Ok)
                    {
                        SetMessage(result.Message);
                        return;
                    }

                    SetMessage(string.Empty);
                    _deckCards = result.Cards;
                }

                _deckList.Show(
                    $"Deck list ({_deckCards.Count} cards)",
                    "Every card the deck started with, by color, rarity and name -- not just what's left in it.",
                    _deckCards);
            });
        }

        private void OpenDiscardPile()
        {
            var state = _session?.State;
            if (state != null)
            {
                _discardOverlay.Show(state.DiscardPile, BoardDisplay.CanAct(state));
            }
        }

        private void OnDiscardCard(BoardCard card, bool canPlay)
        {
            var state = _session?.State;
            if (state != null && canPlay && !_session.Busy)
            {
                _discardOverlay.Close();
                OpenPlayForm(state, card);
            }
            else
            {
                ShowDetail(card, string.IsNullOrEmpty(card.LastOwnerName) ? "In the discard pile" : "Discarded from " + card.LastOwnerName);
            }
        }

        private void BuildPile(
            UiTheme theme, Transform parent, string title, string captionText, BoardCard topCard,
            UnityEngine.Events.UnityAction onClick = null, bool highlight = false, string hint = null, bool dim = false)
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
                    onClick: onClick ?? (() => ShowDetail(card, string.IsNullOrEmpty(card.LastOwnerName) ? "In the discard pile" : "Discarded from " + card.LastOwnerName)));
                MakeHoverable(pileCard, card);
                if (highlight)
                {
                    // A frame in the accent color around the card, so it reads as "something here is playable".
                    var outline = pileCard.gameObject.AddComponent<Outline>();
                    outline.effectColor = theme.accent;
                    outline.effectDistance = new Vector2(5f, -5f);
                }
            }
            else if (title == "Deck")
            {
                // The deck is the back of a card; faded once it has run out.
                var back = CardView.CreateCardBack(column, MoodWidth, theme);
                if (dim)
                {
                    back.gameObject.AddComponent<CanvasGroup>().alpha = 0.35f;
                }

                if (onClick != null)
                {
                    back.gameObject.AddComponent<Button>().onClick.AddListener(onClick);
                }
            }
            else
            {
                var empty = UiFactory.Create("Pile", column);
                var emptyImage = empty.gameObject.AddComponent<Image>();
                emptyImage.color = title == "Deck" ? new Color(0.17f, 0.20f, 0.27f) : new Color(1f, 1f, 1f, 0.07f);
                if (onClick != null)
                {
                    empty.gameObject.AddComponent<Button>().onClick.AddListener(onClick);
                }

                UiFactory.Size(empty.gameObject, MoodWidth, CardView.HeightFor(MoodWidth));
                var label = UiFactory.Label(empty, title == "Deck" ? "DECK" : "EMPTY", 22, theme.textMuted, TextAnchor.MiddleCenter, FontStyle.Bold);
                UiFactory.Stretch(label.rectTransform);
            }

            // One line, centered on the pile even if it's wider than the card.
            var caption = UiFactory.Label(column, captionText, 24, highlight ? theme.accent : theme.textMuted);
            caption.horizontalOverflow = HorizontalWrapMode.Overflow;
            UiFactory.Size(caption.gameObject, height: 34f);

            // The pile shows only its top card, so say when there's something to play in it.
            if (hint != null)
            {
                var hintLabel = UiFactory.Label(column, hint, 22, highlight ? theme.accent : theme.textMuted, TextAnchor.MiddleCenter, highlight ? FontStyle.Bold : FontStyle.Normal);
                hintLabel.horizontalOverflow = HorizontalWrapMode.Overflow;
                UiFactory.Size(hintLabel.gameObject, height: 30f);
            }
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
            if (card.ChaosEffect != null)
            {
                lines.Add(string.Empty);
                lines.Add($"<b><color=#{ColorUtility.ToHtmlStringRGB(CardView.ChaosColor(card.ChaosEffect.Rarity))}>{ChaosDisplay.EffectOnCard(card.ChaosEffect)}</color></b>");
            }

            var chaosValue = ChaosDisplay.ValueNote(card);
            if (chaosValue != null)
            {
                lines.Add(chaosValue);
            }

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
            if (viewerSeated && BoardDisplay.NextGameId(state) != null)
            {
                primary = "Next game";
                primaryEnabled = true;
            }
            else if (BoardDisplay.NeedsAdvanceTurn(state))
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

            var showResign = viewerSeated && inProgress && !BoardDisplay.IsPuzzle(state);
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

            var nextGame = BoardDisplay.NextGameId(state);
            if (nextGame != null)
            {
                // The next game of the match replaces this board, so Back still returns to the list.
                Router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, nextGame.Value), addToHistory: false);
            }
            else if (BoardDisplay.NeedsAdvanceTurn(state))
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

        // Closed Team Play opens with two cards passed, face down, to your partner. Asked through the same
        // form as any other choice: pick exactly two from your hand.
        private void OpenCardPass(GameState state, string key)
        {
            var field = new ChoiceField
            {
                Key = "card_ids",
                Type = "hand_card",
                Multi = true,
                Required = true,
                Label = "Cards to pass to your partner (face down)",
                Count = new ChoiceCount { Min = 2, Max = 2 },
            };
            var form = ChoiceForm.ForDecision(state, new PendingDecision { DecisionType = "initial_card_pass", IsYou = true, Field = field });

            _decisionKey = key;
            _choices.Open(
                form,
                new ChoicePrompt
                {
                    Title = "Pass 2 cards to your partner",
                    Context = "You won't see what they pass you until you've passed yours.",
                    SubmitLabel = "Pass these cards",
                    Cancellable = false,
                },
                () => Run(() => SubmitDecision(
                    form, () => _session.SubmitInitialPassAsync(form.Selected("card_ids").Select(int.Parse)))),
                null);
        }

        private Task SubmitDecision(ChoiceForm form) => SubmitDecision(form, () => _session.RespondAsync(form.BuildChoices()));

        private async Task SubmitDecision(ChoiceForm form, System.Func<Task<BoardActionResult>> send)
        {
            _choices.SetBusy(true, "Sending...");
            var result = await send();
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
            var result = await _session.SendChatAsync(_chatInput.text, _chatChannel);
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
            if (_discardOverlay.IsOpen)
            {
                _discardOverlay.Show(state.DiscardPile, BoardDisplay.CanAct(state));
            }

            if (_playingCard != null)
            {
                var stillPlayable = _choices.IsOpen
                    && BoardDisplay.CanAct(state)
                    && (state.You.Hand.Any(c => c.CardId == _playingCard.CardId)
                        || state.DiscardPile.Any(c => c.CardId == _playingCard.CardId && c.IsPlayable));
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
            string wantedKey = null;
            System.Action open = null;
            if (decision != null && decision.IsYou && decision.Field != null && !_session.IsSpectating)
            {
                wantedKey = DecisionKey(state, decision);
                open = () => OpenDecision(state, decision, wantedKey);
            }
            else if (BoardDisplay.NeedsCardPass(state) && !_session.IsSpectating)
            {
                wantedKey = "pass|" + state.Game.Id;
                open = () => OpenCardPass(state, wantedKey);
            }

            if (wantedKey != null)
            {
                if (_decisionKey != wantedKey || !_choices.IsOpen)
                {
                    open();
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

            if ((BoardDisplay.NeedsTeamProposal(state) || BoardDisplay.NeedsTeamConfirmation(state)) && !_session.IsSpectating)
            {
                ShowTeamDecision(state);
            }
            else if (_teamPanel.gameObject.activeSelf)
            {
                _teamPanel.gameObject.SetActive(false);
                _teamKey = null;
            }

            var choosing = BoardDisplay.NeedsFirstPlayerChoice(state) && !_session.IsSpectating;
            if (choosing && !_firstPlayerOverlay.activeSelf)
            {
                var winner = BoardDisplay.DefaultFirstPlayerName(state);
                _firstPlayerText.text = $"You lost the last game, so you decide: now that you've seen your opening hand, "
                    + $"do you go first, or does {winner}?";
                _letOtherGoLabel.text = $"Let {winner} go first";
            }

            if (_firstPlayerOverlay.activeSelf != choosing)
            {
                _firstPlayerOverlay.SetActive(choosing);
            }

            if (BoardDisplay.NeedsChaosChoice(state) && !_session.IsSpectating)
            {
                _chaosOverlay.Show(state);
            }
            else if (_chaosOverlay.IsOpen)
            {
                _chaosOverlay.Hide();
            }

            var shortcut = state.Game.ChaosLoopShortcut;
            if (shortcut == null)
            {
                _shortcutKey = null;
            }
            else if (shortcut.GamePlayerId == state.You.GamePlayerId && !_session.IsSpectating)
            {
                var key = $"{shortcut.GamePlayerId}:{shortcut.Kind}:{shortcut.Cap}";
                if (key != _shortcutKey && !_shortcutOverlay.IsOpen)
                {
                    _shortcutKey = key;
                    _shortcutOverlay.Open(shortcut);
                }
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
