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
    /// A game's board: every seat with its moods in front of it and a scoreboard to its left, the tops of the cards
    /// in each hand along its player's edge of the screen (the faces of yours, the backs of everyone else's), the deck
    /// and discard peeking up beside your hand, and what to read and press -- the turn banner, messages, Pass -- in
    /// the middle. Opened with a
    /// <see cref="BoardSession"/> (playing or spectating). Seats are placed as
    /// the web client places them -- the next player in turn order at your
    /// left -- and the board polls every few seconds, which is also what makes
    /// the server advance bot turns. Click any card for a readable close-up; on
    /// your turn, clicking a card in your hand opens its play form. Pass,
    /// Advance turn and I'm ready sit in the middle; a question some
    /// card effect has for you opens on its own and has to be answered. The menu
    /// (the three bars, upper left) slides in the way back to the lobby, Resign,
    /// the recent events and the game's chat.
    /// </summary>
    public sealed class BoardScreen : UiScreen
    {
        private const float PollSeconds = 3f;
        private const float HeaderHeight = 100f;
        private const float TableHeight = 1080f;
        private const float ReferenceWidth = 1920f;
        private const float MoodWidth = 180f;
        private const float HandCardWidth = 140f;
        private const float DetailCardWidth = 460f;

        // How much of a card shows above its edge of the screen (the rest is off it): enough of your own hand for the
        // name and value, of the deck and discard for their top, of an opponent's backs for the pattern.
        private const float HandPeek = 88f;
        private const float PilePeek = 96f;
        private const float PileWidth = 124f;
        private const float OpponentBackWidth = 92f;
        private const float OpponentPeek = 66f;

        /// <summary>The edge of the screen an opponent's hand peeks from.</summary>
        private enum HandEdge
        {
            None,
            Top,
            Left,
            Right,
        }

        /// <summary>Where a seat's pieces go, as fractions of the screen (x, y from the bottom).</summary>
        private sealed class SeatPlace
        {
            /// <summary>Their moods in play.</summary>
            public Rect Moods;

            /// <summary>Their name, score, round wins and badges: to the left of the moods (above them for the side seats).</summary>
            public Rect Info;

            public HandEdge Edge;

            /// <summary>How far along that edge the backs of their hand run.</summary>
            public Rect HandSpan;
        }

        // Opponents along the top (and, in a four-player game, the sides); you at the bottom. Each player's scoreboard
        // sits at the end of their hand that is at THEIR left (so for the player across from you, your right). The middle
        // is left clear for the turn banner, messages and the Pass button.
        private static readonly Dictionary<SeatZone, SeatPlace> Places = new Dictionary<SeatZone, SeatPlace>
        {
            [SeatZone.South] = new SeatPlace { Moods = Fraction(0.20f, 0.11f, 0.80f, 0.37f), Info = Fraction(0.103f, 0.008f, 0.268f, 0.113f), Edge = HandEdge.None },
            [SeatZone.North] = new SeatPlace { Moods = Fraction(0.20f, 0.63f, 0.80f, 0.88f), Info = Fraction(0.705f, 0.888f, 0.87f, 0.995f), Edge = HandEdge.Top, HandSpan = Fraction(0.30f, 0f, 0.70f, 0f) },
            [SeatZone.Northwest] = new SeatPlace { Moods = Fraction(0.08f, 0.64f, 0.49f, 0.88f), Info = Fraction(0.345f, 0.888f, 0.51f, 0.995f), Edge = HandEdge.Top, HandSpan = Fraction(0.08f, 0f, 0.34f, 0f) },
            [SeatZone.Northeast] = new SeatPlace { Moods = Fraction(0.55f, 0.64f, 0.985f, 0.88f), Info = Fraction(0.805f, 0.888f, 0.97f, 0.995f), Edge = HandEdge.Top, HandSpan = Fraction(0.54f, 0f, 0.80f, 0f) },
            [SeatZone.West] = new SeatPlace { Moods = Fraction(0.05f, 0.34f, 0.30f, 0.50f), Info = Fraction(0.0f, 0.715f, 0.165f, 0.82f), Edge = HandEdge.Left, HandSpan = Fraction(0f, 0.30f, 0f, 0.70f) },
            [SeatZone.East] = new SeatPlace { Moods = Fraction(0.70f, 0.34f, 0.95f, 0.50f), Info = Fraction(0.835f, 0.18f, 1.0f, 0.285f), Edge = HandEdge.Right, HandSpan = Fraction(0f, 0.30f, 0f, 0.70f) },
        };

        private BoardSession _session;
        private bool _built;
        private RectTransform _table;
        private bool _centerAtTable = true;
        private Text _banner;
        private Text _roundLine;
        private Text _topBanner;
        private Text _topRound;
        private Text _message;
        private Text _loading;
        private RectTransform _center;
        private Text _chatTabLabel;
        private GameObject _menuOverlay;
        private GameObject _logPane;
        private GameObject _chatPane;
        private Button _logTab;
        private Button _chatTab;
        private bool _chatTabShown;
        private bool _chatEntryAllowed;
        private bool _chatChannelAllowed;
        private int _chatSeen;
        private GameObject _menuBadge;
        private Text _menuBadgeLabel;
        private RectTransform _detailCardHolder;
        private Text _detailText;
        private GameObject _detail;
        private Text _logBody;
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

        // The moods on the table by card id (for the lines between them), and the lines' layer.
        private readonly Dictionary<int, RectTransform> _moodViews = new Dictionary<int, RectTransform>();
        private List<EffectLink> _links = new List<EffectLink>();
        private EffectLinksLayer _linksLayer;
        private readonly List<Flight> _flights = new List<Flight>();
        private float _messageExpires;

        private BoardCard _playingCard;
        private string _decisionKey;
        private string _loopKey;
        private bool _messageFromRefresh;
        private float _nextHeaderUpdate;

        public string BannerText => _banner != null ? _banner.text : null;

        /// <summary>Whose turn it is, in words (the board itself shows that on the scoreboards, so this is not on screen).</summary>
        public string TurnText => _topBanner != null ? _topBanner.text : null;

        public string RoundText => _roundLine != null ? _roundLine.text : null;

        public string MessageText => _message != null ? _message.text : null;

        public bool DetailOpen => _detail != null && _detail.activeSelf;

        public string DetailText => _detailText != null ? _detailText.text : null;

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
            _primaryAction.gameObject.SetActive(false);
            _moodViews.Clear();
            _linksLayer.Clear();
            _chatSeen = _session == null ? 0 : AppServices.Device.ChatSeen(_session.GameId);
            _chatTabShown = false;

            if (_session == null)
            {
                _draft.Hide();
                ClearTable();
                // Nothing of a game that was shown before stays up (the banner, the round line...).
                _banner.text = _topBanner.text = _roundLine.text = _topRound.text = string.Empty;
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

            if (_detail.activeSelf || _menuOverlay.activeSelf || _teammateOverlay.activeSelf)
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
            _table.offsetMax = Vector2.zero;

            // Over the table, under everything built after it (the header, the center, the menu).
            _linksLayer = EffectLinksLayer.Create(transform);

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
            BuildHeader(theme);
            BuildCenter(theme);

            _loading = UiFactory.Label(transform, "Loading the game...", 36, theme.textMuted);
            UiFactory.Stretch(_loading.rectTransform);

            _discardOverlay = new DiscardPileOverlay(transform, theme) { Chosen = OnDiscardCard };
            _deckList = new CardListOverlay(transform, theme) { Chosen = card => ShowDetail(card, "In the deck") };
            BuildHoverPreview(theme);
            BuildTeammateOverlay(theme);
            BuildDetailOverlay(theme);
            BuildMenu(theme);

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
            _choices = new ChoiceOverlay(transform, theme) { Inspect = (card, where) => ShowDetail(card, where) };
            _confirm = new ConfirmOverlay(transform, theme);
        }

        private void BuildHeader(UiTheme theme)
        {
            var bar = UiFactory.Create("Header", transform);
            bar.anchorMin = new Vector2(0f, 1f);
            bar.anchorMax = new Vector2(1f, 1f);
            bar.pivot = new Vector2(0.5f, 1f);
            bar.sizeDelta = new Vector2(0f, HeaderHeight);

            // The menu: the way back to the lobby, Resign, the recent events and the chat.
            var menu = UiFactory.Button(bar, string.Empty, theme, OpenMenu, primary: false);
            menu.gameObject.name = "Menu";
            var menuRect = (RectTransform)menu.transform;
            menuRect.anchorMin = menuRect.anchorMax = menuRect.pivot = new Vector2(0f, 1f);
            menuRect.sizeDelta = new Vector2(76f, 76f);
            menuRect.anchoredPosition = new Vector2(28f, -22f);
            var bars = UiFactory.Create("Bars", menuRect);
            UiFactory.Stretch(bars);
            bars.offsetMin = new Vector2(14f, 14f);
            bars.offsetMax = new Vector2(-14f, -14f);
            var barsImage = bars.gameObject.AddComponent<Image>();
            barsImage.sprite = UiIcons.Menu();
            barsImage.color = theme.textPrimary;
            barsImage.raycastTarget = false;

            // Unread chat, on the icon.
            var badge = UiFactory.Create("Unread chat", menuRect);
            badge.anchorMin = badge.anchorMax = badge.pivot = new Vector2(1f, 1f);
            badge.sizeDelta = new Vector2(34f, 34f);
            badge.anchoredPosition = new Vector2(10f, 10f);
            var badgeImage = badge.gameObject.AddComponent<Image>();
            badgeImage.color = theme.accent;
            badgeImage.raycastTarget = false;
            _menuBadgeLabel = UiFactory.Label(badge, string.Empty, 22, theme.onAccent, TextAnchor.MiddleCenter, FontStyle.Bold);
            _menuBadgeLabel.raycastTarget = false;
            UiFactory.Stretch(_menuBadgeLabel.rectTransform);
            _menuBadge = badge.gameObject;
            _menuBadge.SetActive(false);

            // Drafting and deck building keep the turn banner along the top; at the table it is in the middle.
            _topBanner = UiFactory.Label(bar, string.Empty, 38, theme.textPrimary, TextAnchor.MiddleCenter, FontStyle.Bold);
            _topBanner.raycastTarget = false;
            _topBanner.rectTransform.anchorMin = new Vector2(0.18f, 0.42f);
            _topBanner.rectTransform.anchorMax = new Vector2(0.82f, 1f);
            _topBanner.rectTransform.offsetMin = _topBanner.rectTransform.offsetMax = Vector2.zero;

            _topRound = UiFactory.Label(bar, string.Empty, 24, theme.textMuted);
            _topRound.raycastTarget = false;
            _topRound.horizontalOverflow = HorizontalWrapMode.Overflow;
            _topRound.rectTransform.anchorMin = new Vector2(0.18f, 0f);
            _topRound.rectTransform.anchorMax = new Vector2(0.82f, 0.42f);
            _topRound.rectTransform.offsetMin = _topRound.rectTransform.offsetMax = Vector2.zero;

            // Open Team Play: your partner's hand, which you're allowed to see.
            var partner = UiFactory.Button(bar, "Partner's hand", theme, () => OpenTeammateHand(), primary: false);
            partner.gameObject.name = "Partner hand button";
            PlaceHeaderButton((RectTransform)partner.transform, 40f);
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

        // A puzzle's goal, kept in view at the left edge of the table, between the seats, and a button for its hint (not every
        // puzzle has one). The hint is only shown when asked for, since it can give the game away.
        private void BuildGoalPanel(UiTheme theme)
        {
            _goalPanel = UiFactory.Create("Puzzle goal", transform);
            _goalPanel.anchorMin = _goalPanel.anchorMax = _goalPanel.pivot = new Vector2(0f, 0.5f);
            _goalPanel.sizeDelta = new Vector2(440f, 0f);
            _goalPanel.anchoredPosition = new Vector2(24f, 0f);
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
            _dropZone.offsetMin = new Vector2(0f, HandPeek + 6f);
            _dropZone.offsetMax = Vector2.zero;
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

        // The middle of the screen: the turn banner and round line, a message when there is one, and the main button
        // (Pass / Advance turn / I'm ready / Next game). Which button shows, and whether it can be pressed, is decided in
        // UpdateActions. While a draft is on, only the message and button remain, in the corner where the draft leaves room.
        private void BuildCenter(UiTheme theme)
        {
            _center = UiFactory.Create("Center", transform);
            _center.sizeDelta = Vector2.zero; // its width is the span between its anchors; the height is its content's
            _center.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;
            var layout = _center.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 8f;
            layout.childAlignment = TextAnchor.MiddleCenter;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _banner = UiFactory.Label(_center, string.Empty, 38, theme.textPrimary, TextAnchor.MiddleCenter, FontStyle.Bold);
            _banner.name = "Banner";
            _banner.raycastTarget = false;
            _roundLine = UiFactory.Label(_center, string.Empty, 26, theme.textMuted);
            _roundLine.name = "Round line";
            _roundLine.raycastTarget = false;
            _message = UiFactory.Label(_center, string.Empty, 26, theme.danger);
            _message.name = "Message";
            _message.raycastTarget = false;

            // The button doesn't stretch across the middle: a row keeps it a button's width, centered.
            var primaryRow = UiFactory.Row(_center, "Primary row", 0f, TextAnchor.MiddleCenter);
            primaryRow.childForceExpandWidth = false;
            _primaryAction = UiFactory.Button(primaryRow.transform, "Pass", theme, () => OnPrimaryAction());
            _primaryAction.gameObject.name = "Primary action";
            _primaryLabel = _primaryAction.GetComponentInChildren<Text>();
            UiFactory.Size(_primaryAction.gameObject, 260f, 56f);

            DockCenter(atTable: true);
        }

        private void DockCenter(bool atTable)
        {
            _centerAtTable = atTable;
            _banner.gameObject.SetActive(atTable && !string.IsNullOrEmpty(_banner.text));
            _roundLine.gameObject.SetActive(atTable);
            _topBanner.gameObject.SetActive(!atTable);
            _topRound.gameObject.SetActive(!atTable);
            if (atTable)
            {
                _center.anchorMin = new Vector2(0.30f, 0.5f);
                _center.anchorMax = new Vector2(0.70f, 0.5f);
                _center.pivot = new Vector2(0.5f, 0.5f);
                _center.anchoredPosition = Vector2.zero;
            }
            else
            {
                _center.anchorMin = new Vector2(0.80f, 0f);
                _center.anchorMax = new Vector2(0.985f, 0f);
                _center.pivot = new Vector2(0.5f, 0f);
                _center.anchoredPosition = new Vector2(0f, 24f);
            }
        }

        // The menu slides in from the left: back to the lobby, Resign, and two tabs -- the recent events and the chat.
        private void BuildMenu(UiTheme theme)
        {
            var root = UiFactory.Create("Menu overlay", transform);
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.6f);
            root.gameObject.AddComponent<Button>().onClick.AddListener(CloseOverlays);
            _menuOverlay = root.gameObject;

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = new Vector2(0f, 0f);
            panel.anchorMax = new Vector2(0f, 1f);
            panel.pivot = new Vector2(0f, 0.5f);
            panel.sizeDelta = new Vector2(660f, 0f);
            panel.anchoredPosition = Vector2.zero;
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(24, 24, 24, 24);
            layout.spacing = 12f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            var actions = UiFactory.Row(panel, "Menu actions", 12f, TextAnchor.MiddleCenter);
            actions.childForceExpandWidth = true;
            UiFactory.Size(actions.gameObject, height: UiFactory.ControlHeight);
            var lobby = UiFactory.Button(actions.transform, "< Lobby", theme, () => Router.Back(), primary: false);
            lobby.gameObject.name = "Lobby";
            UiFactory.Flexible(lobby.gameObject, width: 1f);
            _resignAction = UiFactory.Button(actions.transform, "Resign", theme, () =>
            {
                CloseOverlays();
                Run(Resign);
            }, primary: false);
            _resignAction.gameObject.name = "Resign";
            _resignAction.GetComponent<Image>().color = theme.danger;
            _resignAction.GetComponentInChildren<Text>().color = Color.white;
            UiFactory.Flexible(_resignAction.gameObject, width: 1f);

            var tabs = UiFactory.Row(panel, "Menu tabs", 12f, TextAnchor.MiddleCenter);
            tabs.childForceExpandWidth = true;
            UiFactory.Size(tabs.gameObject, height: 56f);
            _logTab = UiFactory.Button(tabs.transform, "Log", theme, () => ShowMenuTab(chat: false), primary: false);
            _logTab.gameObject.name = "Log tab";
            UiFactory.Flexible(_logTab.gameObject, width: 1f);
            _chatTab = UiFactory.Button(tabs.transform, "Chat", theme, () => ShowMenuTab(chat: true), primary: false);
            _chatTab.gameObject.name = "Chat tab";
            _chatTabLabel = _chatTab.GetComponentInChildren<Text>();
            UiFactory.Flexible(_chatTab.gameObject, width: 1f);

            var logScroll = UiFactory.ScrollList(panel, out var logContent);
            logScroll.gameObject.name = "Log pane";
            UiFactory.Flexible(logScroll.gameObject, height: 1f);
            _logPane = logScroll.gameObject;
            _logBody = UiFactory.Label(logContent, string.Empty, 26, theme.textPrimary, TextAnchor.UpperLeft);

            var chatScroll = UiFactory.ScrollList(panel, out var chatContent);
            chatScroll.gameObject.name = "Chat pane";
            UiFactory.Flexible(chatScroll.gameObject, height: 1f);
            _chatPane = chatScroll.gameObject;
            _chatBody = UiFactory.Label(chatContent, string.Empty, 26, theme.textPrimary, TextAnchor.UpperLeft);

            var close = UiFactory.Button(panel, "Close", theme, CloseOverlays, primary: false);
            close.gameObject.name = "Close menu";
            BuildChatEntry(theme, panel);

            var version = UiFactory.Label(panel, $"Client {Application.version}", 20, theme.textMuted, TextAnchor.LowerLeft);
            version.name = "Client version";
            version.raycastTarget = false;
            UiFactory.Size(version.gameObject, height: 26f);

            _menuOverlay.SetActive(false);
        }

        private void OpenMenu()
        {
            var state = _session?.State;
            // Unread chat is what you probably came for.
            var unread = state != null && state.ChatMessages.Count > _chatSeen;
            _menuOverlay.SetActive(true);
            ShowMenuTab(unread || _chatTabShown);
        }

        private void ShowMenuTab(bool chat)
        {
            _chatTabShown = chat;
            var theme = AppServices.Theme;
            _logPane.SetActive(!chat);
            _chatPane.SetActive(chat);
            _chatEntry.SetActive(chat && _chatEntryAllowed);
            _chatChannelRow.SetActive(chat && _chatChannelAllowed);
            _chatError.gameObject.SetActive(chat);
            foreach (var tab in new[] { _logTab, _chatTab })
            {
                var on = tab == (chat ? _chatTab : _logTab);
                tab.GetComponent<Image>().color = on ? theme.Selected : theme.raised;
                tab.GetComponentInChildren<Text>().color = on ? theme.accent : theme.textMuted;
            }

            if (chat && _menuOverlay.activeSelf && _session?.State != null)
            {
                MarkChatSeen(_session.State.ChatMessages.Count);
                UpdateMenuBadge();
            }
        }

        // Remembered on the device, so it survives leaving the game or the app.
        private void MarkChatSeen(int count)
        {
            if (count == _chatSeen)
            {
                return;
            }

            _chatSeen = count;
            if (_session != null)
            {
                AppServices.Device.SetChatSeen(_session.GameId, count);
            }
        }

        private void UpdateMenuBadge()
        {
            var state = _session?.State;
            var unread = state == null ? 0 : Mathf.Max(0, state.ChatMessages.Count - _chatSeen);
            // Chat that is being read as it arrives isn't unread.
            if (_menuOverlay.activeSelf && _chatTabShown && state != null)
            {
                MarkChatSeen(state.ChatMessages.Count);
                unread = 0;
            }

            _chatTabLabel.text = unread > 0 ? $"Chat ({unread})" : "Chat";
            _menuBadge.SetActive(unread > 0 && !_menuOverlay.activeSelf);
            _menuBadgeLabel.text = unread > 9 ? "9+" : unread.ToString();
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
            panel.gameObject.AddComponent<Image>().color = theme.panel;
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
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.94f);
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

            // Drawn straight onto the dark shade, so light in either palette.
            _detailText = UiFactory.Label(content.transform, string.Empty, 30, new Color(0.96f, 0.96f, 0.94f), TextAnchor.MiddleLeft);
            UiFactory.Size(_detailText.gameObject, 800f);

            _detail.SetActive(false);
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
            IsDragging || _detail.activeSelf || _discardOverlay.IsOpen || _deckList.IsOpen || _menuOverlay.activeSelf || _teammateOverlay.activeSelf
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
                notes.Add($"<b><color=#{ColorUtility.ToHtmlStringRGB(CardView.ReadableText(CardView.ChaosColor(card.ChaosEffect.Rarity)))}>{ChaosDisplay.EffectOnCard(card.ChaosEffect)}</color></b>");
            }

            var chaosValue = ChaosDisplay.ValueNote(card);
            if (chaosValue != null)
            {
                notes.Add(chaosValue);
            }

            var bliss = BoardDisplay.BlissNote(card, word => $"<b><color=#{ColorUtility.ToHtmlStringRGB(CardView.ReadableText(CardView.IndicatorColor(word)))}>{word}</color></b>");
            if (bliss != null)
            {
                notes.Add(bliss);
            }

            var colorNote = BoardDisplay.ColorNote(_session?.State, card);
            if (colorNote != null)
            {
                notes.Add($"<b><color=#{ColorUtility.ToHtmlStringRGB(CardView.ReadableText(CardView.IndicatorColor(card.Color)))}>{colorNote}</color></b>");
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
                button.GetComponent<Image>().color = chosen ? theme.accent : theme.raised;
                button.GetComponentInChildren<Text>().color = chosen ? theme.onAccent : theme.textPrimary;
            }
        }

        private void CloseOverlays()
        {
            _teammateOverlay.SetActive(false);
            _detail.SetActive(false);
            _menuOverlay.SetActive(false);
            UpdateMenuBadge();
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
            // At the table the trophies on the scoreboards already say how many wins it takes; in a draft there are none.
            var line = BoardDisplay.HeaderLine(state, _session.IsSpectating, DateTime.UtcNow);
            var tableLine = BoardDisplay.HeaderLine(state, _session.IsSpectating, DateTime.UtcNow, includeWinsNeeded: false);
            var color = BoardDisplay.ClockIsUrgent(state, DateTime.UtcNow) ? AppServices.Theme.danger : AppServices.Theme.textMuted;
            _roundLine.text = tableLine;
            _topRound.text = line;
            _roundLine.color = _topRound.color = color;
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
            MusicPlayer.Instance.SetTheme(MusicThemes.For(state, AppServices.Decklists?.Catalog));

            _topBanner.text = BoardDisplay.TurnBanner(state);
            _banner.text = BoardDisplay.TurnBanner(state, includeTurn: false);
            _banner.gameObject.SetActive(_centerAtTable && !string.IsNullOrEmpty(_banner.text));
            _banner.color = _topBanner.color = BoardDisplay.BannerNeedsViewer(state) ? theme.accent : theme.textPrimary;
            UpdateHeader(state);
            _chatEntryAllowed = !BoardDisplay.IsSpectator(state);
            _logBody.text = LogText(state);
            _chatBody.text = state.ChatMessages.Count == 0
                ? "No messages yet."
                : string.Join("\n\n", state.ChatMessages.Select(m => (m.Channel == "team" ? "[team] " : string.Empty) + $"{m.SenderUsername}: {m.MessageText}"));
            _chatChannelAllowed = state.Game.Format == GameSetup.OpenTeamFormat && !BoardDisplay.IsSpectator(state);
            if (!_chatChannelAllowed)
            {
                _chatChannel = "table";
            }

            RefreshChatChannelButtons();
            ShowMenuTab(_chatTabShown);
            UpdateMenuBadge();
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
                _moodViews.Clear();
                _linksLayer.Clear();
                ClearTable();
                CancelDrag();
                HideHover();
                DockCenter(atTable: false);
                _draft.Render(state);
                UpdateActions(state);
                SyncOverlays(state);
                return;
            }

            _draft.Hide();
            DockCenter(atTable: true);
            ClearTable();
            var zones = BoardLayout.Assign(state.Players, state.You.GamePlayerId);
            _moodViews.Clear();
            _links = BoardEffects.Links(state);
            foreach (var player in state.Players)
            {
                BuildSeat(theme, state, player, zones[player.GamePlayerId]);
            }

            _linksLayer.SetLinks(_links, _moodViews);

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
            var place = Places[zone];
            BuildInfo(theme, state, player, Place(_table, "Info " + player.Username, place.Info));
            if (place.Edge != HandEdge.None)
            {
                BuildOpponentHand(theme, player, place);
            }

            var area = place.Moods;
            var seat = Place(_table, "Seat " + player.Username, area);
            var layout = seat.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 6f;
            layout.padding = new RectOffset(8, 8, 4, 4);
            // Beside their scoreboard where the seat is off to one side, centered where it is across from you.
            // Your own moods settle toward your hand, theirs toward their edge.
            layout.childAlignment = zone == SeatZone.South ? TextAnchor.LowerCenter
                : zone == SeatZone.North ? TextAnchor.UpperCenter : TextAnchor.UpperLeft;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;

            var moods = BoardDisplay.MoodsOf(state, player.GamePlayerId);
            if (moods.Count == 0)
            {
                return;
            }

            // A suppressed mood is turned on its side, by convention, so it takes a card's height in width.
            var spacing = 10f;
            var availableWidth = area.width * ReferenceWidth - 16f;
            var slots = moods.Sum(m => m.IsSuppressed ? CardView.HeightOverWidth : 1f);
            var heightFit = (area.height * TableHeight - 8f) / CardView.HeightOverWidth;
            var width = Mathf.Min(MoodWidth, heightFit, (availableWidth - spacing * (moods.Count - 1)) / slots);
            var row = UiFactory.Row(seat, "Moods", spacing, TextAnchor.UpperCenter);
            foreach (var mood in moods)
            {
                var card = mood;
                UnityEngine.Events.UnityAction open = () => ShowDetail(card, "In play for " + player.Username);
                if (!card.IsSuppressed)
                {
                    var upright = CardView.Create(row.transform, card, width, theme, showValue: true, onClick: open);
                    MakeHoverable(upright, card);
                    _moodViews[card.CardId] = upright;
                    if (BoardEffects.IsAffectingTheGame(state, card, _links))
                    {
                        CardAura.Attach(upright, card.Color, card.CardId * 0.37f % 1f);
                    }

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
                _moodViews[card.CardId] = tapped;
                NoteFlight(tapped, card, player.GamePlayerId, width);
            }
        }

        // A player's scoreboard, at the end of their hand: the points in a box with a trophy for each round won stacked
        // beside it (and a 1ST tag on the box for whoever went first), then their name, and the Hurt Feelings card for
        // whoever has it.
        private void BuildInfo(UiTheme theme, GameState state, BoardPlayer player, RectTransform area)
        {
            var isTurn = state.Round.CurrentTurnGamePlayerId == player.GamePlayerId && state.Game.Status != "completed";
            var background = area.gameObject.AddComponent<Image>();
            background.color = isTurn ? Color.Lerp(theme.panel, theme.accent, 0.35f) : theme.panel;
            background.raycastTarget = false;
            var layout = area.gameObject.AddComponent<HorizontalLayoutGroup>();
            layout.padding = new RectOffset(10, 10, 8, 8);
            layout.spacing = 10f;
            layout.childAlignment = TextAnchor.MiddleLeft;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;

            var scores = UiFactory.Row(area, "Scores", 6f, TextAnchor.MiddleLeft);
            scores.childForceExpandWidth = false;
            scores.childForceExpandHeight = false;

            // The score, big, in a box.
            var box = UiFactory.Create("Score", scores.transform);
            var outer = box.gameObject.AddComponent<Image>();
            outer.color = theme.textMuted;
            outer.raycastTarget = false;
            UiFactory.Size(box.gameObject, 92f, 76f);
            var inner = UiFactory.Create("Inside", box);
            UiFactory.Stretch(inner);
            inner.offsetMin = new Vector2(3f, 3f);
            inner.offsetMax = new Vector2(-3f, -3f);
            var innerImage = inner.gameObject.AddComponent<Image>();
            innerImage.color = theme.background;
            innerImage.raycastTarget = false;
            var points = UiFactory.Label(inner, player.TotalScore.ToString(), 52, theme.textPrimary, TextAnchor.MiddleCenter, FontStyle.Bold);
            points.name = "Points";
            points.raycastTarget = false;
            UiFactory.Stretch(points.rectTransform);

            // Whoever went first wears a tag on the corner of their box.
            if (state.Round.WentFirstGamePlayerId == player.GamePlayerId)
            {
                var pill = UiFactory.Create("First player", box);
                pill.anchorMin = pill.anchorMax = pill.pivot = new Vector2(0f, 0f);
                pill.sizeDelta = new Vector2(52f, 24f);
                pill.anchoredPosition = new Vector2(-4f, -6f);
                var pillImage = pill.gameObject.AddComponent<Image>();
                pillImage.color = theme.accent;
                pillImage.raycastTarget = false;
                var pillLabel = UiFactory.Label(pill, "1ST", 18, theme.onAccent, TextAnchor.MiddleCenter, FontStyle.Bold);
                pillLabel.raycastTarget = false;
                UiFactory.Stretch(pillLabel.rectTransform);
            }

            // A trophy for each round won (and a dim one for each still to win), stacked beside the box.
            var trophyCount = state.Game.WinsNeeded > 0 ? Mathf.Max(state.Game.WinsNeeded, player.TotalWins) : player.TotalWins;
            if (trophyCount > 0)
            {
                var column = UiFactory.Create("Trophies", scores.transform);
                var stack = column.gameObject.AddComponent<VerticalLayoutGroup>();
                stack.spacing = 2f;
                stack.childAlignment = TextAnchor.MiddleCenter;
                stack.childControlWidth = true;
                stack.childControlHeight = true;
                stack.childForceExpandWidth = false;
                stack.childForceExpandHeight = false;
                var size = Mathf.Min(26f, 76f / trophyCount);
                UiFactory.Size(column.gameObject, size + 2f);
                for (var i = 0; i < trophyCount; i++)
                {
                    var won = i < player.TotalWins;
                    var trophy = UiFactory.Create(won ? "Trophy won" : "Trophy to win", column);
                    var image = trophy.gameObject.AddComponent<Image>();
                    image.sprite = UiIcons.Trophy();
                    image.color = won ? theme.gold : new Color(theme.textMuted.r, theme.textMuted.g, theme.textMuted.b, 0.35f);
                    image.raycastTarget = false;
                    UiFactory.Size(trophy.gameObject, size, size);
                }
            }

            // The name, beside the score.
            var details = UiFactory.Create("Details", area);
            var column2 = details.gameObject.AddComponent<VerticalLayoutGroup>();
            column2.spacing = 2f;
            column2.childAlignment = TextAnchor.MiddleLeft;
            column2.childControlWidth = true;
            column2.childControlHeight = true;
            column2.childForceExpandWidth = true;
            column2.childForceExpandHeight = false;
            UiFactory.Flexible(details.gameObject, width: 1f);

            var heading = UiFactory.Row(details, "Who", 6f, TextAnchor.MiddleLeft);
            heading.childForceExpandWidth = false;
            var marker = UiFactory.Create("Turn", heading.transform);
            marker.gameObject.AddComponent<Image>().color = isTurn ? theme.accent : theme.faint;
            UiFactory.Size(marker.gameObject, 14f, 14f);

            var teamTag = BoardDisplay.TeamTag(state, player);
            var name = player.Username
                + (player.IsBot ? "  (bot)" : string.Empty)
                + (player.GamePlayerId == state.You.GamePlayerId ? "  (you)" : string.Empty)
                + (teamTag != null ? $"  ({teamTag})" : string.Empty)
                + (player.Resigned ? "  (resigned)" : string.Empty);
            var nameLabel = UiFactory.Label(heading.transform, name, 22, theme.textPrimary, TextAnchor.MiddleLeft, FontStyle.Bold);
            nameLabel.name = "Name";
            nameLabel.raycastTarget = false;
            UiFactory.Flexible(nameLabel.gameObject, width: 1f);

            // Each player has their own deck in a duel, so its size is theirs to show.
            if (BoardDisplay.HasSeparateDecks(state))
            {
                var deck = UiFactory.Label(details, $"Deck {player.DeckCount}", 20, theme.textMuted, TextAnchor.MiddleLeft);
                deck.raycastTarget = false;
                UiFactory.Size(deck.gameObject, height: 24f);
            }

            if (state.Round.HurtFeelingsGamePlayerId == player.GamePlayerId)
            {
                CardView.CreateHurtFeelings(details, 34f, theme);
            }
        }

        // An opponent's hand: the backs of their cards, peeking in from their edge of the screen -- only the part
        // nearest the table shows, the rest is off it.
        private void BuildOpponentHand(UiTheme theme, BoardPlayer player, SeatPlace place)
        {
            var count = player.HandCount;
            if (count <= 0)
            {
                return;
            }

            var span = place.HandSpan;
            Rect strip;
            switch (place.Edge)
            {
                case HandEdge.Top:
                    strip = new Rect(span.xMin, 1f - OpponentPeek / TableHeight, span.width, OpponentPeek / TableHeight);
                    break;
                case HandEdge.Left:
                    strip = new Rect(0f, span.yMin, OpponentPeek / ReferenceWidth, span.height);
                    break;
                default:
                    strip = new Rect(1f - OpponentPeek / ReferenceWidth, span.yMin, OpponentPeek / ReferenceWidth, span.height);
                    break;
            }

            var holder = Place(_table, "Opponent hand " + player.Username, strip);
            holder.gameObject.AddComponent<RectMask2D>();
            var cardHeight = CardView.HeightFor(OpponentBackWidth);

            if (place.Edge == HandEdge.Top)
            {
                var available = strip.width * ReferenceWidth;
                var gap = count > 1 ? Mathf.Min(8f, (available - count * OpponentBackWidth) / (count - 1)) : 0f;
                var row = holder.gameObject.AddComponent<HorizontalLayoutGroup>();
                row.spacing = gap;
                row.childAlignment = TextAnchor.LowerCenter;
                row.childControlWidth = false;
                row.childControlHeight = false;
                row.childForceExpandWidth = false;
                row.childForceExpandHeight = false;
                for (var i = 0; i < count; i++)
                {
                    // Turned around, so it's the tops of the cards that peek out from the top of the screen.
                    var back = CardView.CreateCardBack(holder, OpponentBackWidth, theme);
                    back.name = "Hand card back";
                    back.localRotation = Quaternion.Euler(0f, 0f, 180f);
                }

                return;
            }

            var length = strip.height * TableHeight;
            var step = count > 1 ? Mathf.Min(8f, (length - count * OpponentBackWidth) / (count - 1)) : 0f;
            var column = holder.gameObject.AddComponent<VerticalLayoutGroup>();
            column.spacing = step;
            column.childAlignment = place.Edge == HandEdge.Left ? TextAnchor.MiddleRight : TextAnchor.MiddleLeft;
            column.childControlWidth = false;
            column.childControlHeight = false;
            column.childForceExpandWidth = false;
            column.childForceExpandHeight = false;
            for (var i = 0; i < count; i++)
            {
                // Turned on its side, so the cards run along the edge.
                var slot = UiFactory.Create("Hand card slot", holder);
                slot.sizeDelta = new Vector2(cardHeight, OpponentBackWidth);
                var back = CardView.CreateCardBack(slot, OpponentBackWidth, theme);
                back.name = "Hand card back";
                back.anchorMin = back.anchorMax = back.pivot = new Vector2(0.5f, 0.5f);
                back.anchoredPosition = Vector2.zero;
                back.localRotation = Quaternion.Euler(0f, 0f, place.Edge == HandEdge.Left ? -90f : 90f);
            }
        }

        // The deck and discard peek up from the bottom edge, either side of your hand; a line above each says
        // what it holds.
        private void BuildPiles(UiTheme theme, GameState state)
        {
            var piles = Place(_table, "Piles", Fraction(0f, 0f, 1f, PilePeek / TableHeight));

            // Tapping the deck lists every card it started with, if the table shares one deck.
            BuildPile(theme, piles, "Deck", 0.90f, BoardDisplay.DeckCaption(state), null, onClick: OpenDeckList, dim: state.DeckCount == 0);

            // An effect (Harmony, Grief...) can make a card in the pile playable: say so, since the pile shows only its top card.
            var top = state.DiscardPile.LastOrDefault();
            var playable = BoardDisplay.CanAct(state) && state.DiscardPile.Any(c => c.IsPlayable);
            BuildPile(theme, piles, "Discard", 0.065f,
                $"Discard {state.DiscardPile.Count}" + (playable ? "  -  play from it" : string.Empty), top,
                onClick: OpenDiscardPile, highlight: playable);
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
            UiTheme theme, RectTransform parent, string title, float centerX, string captionText, BoardCard topCard,
            UnityEngine.Events.UnityAction onClick = null, bool highlight = false, bool dim = false)
        {
            // The pile: a window PilePeek tall at the bottom edge, with the card hanging down out of it.
            var column = UiFactory.Create(title, parent);
            column.anchorMin = column.anchorMax = new Vector2(centerX, 0f);
            column.pivot = new Vector2(0.5f, 0f);
            column.sizeDelta = new Vector2(PileWidth + 120f, PilePeek);
            column.anchoredPosition = Vector2.zero;

            var window = UiFactory.Create("Window", column);
            window.anchorMin = window.anchorMax = new Vector2(0.5f, 0f);
            window.pivot = new Vector2(0.5f, 0f);
            window.sizeDelta = new Vector2(PileWidth, PilePeek);
            window.anchoredPosition = Vector2.zero;
            window.gameObject.AddComponent<RectMask2D>();

            RectTransform face;
            if (topCard != null)
            {
                var card = topCard;
                face = CardView.Create(window, card, PileWidth, theme, showValue: false,
                    onClick: onClick ?? (() => ShowDetail(card, string.IsNullOrEmpty(card.LastOwnerName) ? "In the discard pile" : "Discarded from " + card.LastOwnerName)));
                MakeHoverable(face, card);
                if (highlight)
                {
                    // A frame in the accent color around the card, so it reads as "something here is playable".
                    var outline = face.gameObject.AddComponent<Outline>();
                    outline.effectColor = theme.accent;
                    outline.effectDistance = new Vector2(5f, -5f);
                }
            }
            else if (title == "Deck")
            {
                // The deck is the back of a card; faded once it has run out.
                face = CardView.CreateCardBack(window, PileWidth, theme);
                if (dim)
                {
                    face.gameObject.AddComponent<CanvasGroup>().alpha = 0.35f;
                }

                if (onClick != null)
                {
                    face.gameObject.AddComponent<Button>().onClick.AddListener(onClick);
                }
            }
            else
            {
                face = UiFactory.Create("Pile", window);
                var emptyImage = face.gameObject.AddComponent<Image>();
                emptyImage.color = theme.faint;
                if (onClick != null)
                {
                    face.gameObject.AddComponent<Button>().onClick.AddListener(onClick);
                }

                face.sizeDelta = new Vector2(PileWidth, CardView.HeightFor(PileWidth));
                var label = UiFactory.Label(face, "EMPTY", 22, theme.textMuted, TextAnchor.UpperCenter, FontStyle.Bold);
                UiFactory.Stretch(label.rectTransform);
                label.rectTransform.offsetMin = new Vector2(0f, 0f);
                label.rectTransform.offsetMax = new Vector2(0f, -30f);
            }

            // Only the top of the card shows: it hangs from the window's top edge.
            face.anchorMin = face.anchorMax = new Vector2(0.5f, 1f);
            face.pivot = new Vector2(0.5f, 1f);
            face.anchoredPosition = Vector2.zero;

            // One line above it, centered on the pile even if it's wider than the card.
            var caption = UiFactory.Label(column, captionText, 24, highlight ? theme.accent : theme.textMuted);
            caption.name = "Caption";
            caption.raycastTarget = false;
            caption.horizontalOverflow = HorizontalWrapMode.Overflow;
            caption.rectTransform.anchorMin = caption.rectTransform.anchorMax = new Vector2(0.5f, 0f);
            caption.rectTransform.pivot = new Vector2(0.5f, 0f);
            caption.rectTransform.sizeDelta = new Vector2(PileWidth + 120f, 32f);
            caption.rectTransform.anchoredPosition = new Vector2(0f, PilePeek + 2f);
        }

        // Your hand, along the bottom edge: just the top of each card shows (its name and value), the rest is off the
        // screen, which leaves the room above it for the table.
        private void BuildHand(UiTheme theme, GameState state)
        {
            var hand = Place(_table, "Hand", Fraction(0.27f, 0f, 0.79f, HandPeek / TableHeight));
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
            var available = hand.anchorMax.x * ReferenceWidth - hand.anchorMin.x * ReferenceWidth;
            var spacing = count > 1 ? Mathf.Min(14f, (available - count * HandCardWidth) / (count - 1)) : 0f;
            var row = UiFactory.Row(hand, "HandRow", spacing, TextAnchor.UpperCenter);
            var rowRect = (RectTransform)row.transform;
            rowRect.anchorMin = new Vector2(0f, 1f);
            rowRect.anchorMax = new Vector2(1f, 1f);
            rowRect.pivot = new Vector2(0.5f, 1f);
            rowRect.sizeDelta = new Vector2(0f, CardView.HeightFor(HandCardWidth));
            rowRect.anchoredPosition = Vector2.zero;

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

        /// <summary>The card's detail is drawn over whatever else is open (a choice form, say), not under it.</summary>
        public bool DetailIsOnTop => _detail.activeSelf && _detail.transform.GetSiblingIndex() == transform.childCount - 1;

        private void ShowDetail(BoardCard card, string where)
        {
            _detail.transform.SetAsLastSibling();
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

            var bliss = BoardDisplay.BlissNote(card, word => $"<b><color=#{ColorUtility.ToHtmlStringRGB(CardView.IndicatorColor(word))}>{word}</color></b>");
            if (bliss != null)
            {
                lines.Add(string.Empty);
                lines.Add(bliss);
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
                lines.Add($"<b><color=#EB5252>{BoardDisplay.SuppressedByText(card)}</color></b>"); // red enough for the dark shade in either palette
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

            // Resign lives in the menu.
            var showResign = viewerSeated && inProgress && !BoardDisplay.IsPuzzle(state);
            _resignAction.gameObject.SetActive(showResign);
            _resignAction.interactable = BoardDisplay.CanResign(state) && !busy;
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
