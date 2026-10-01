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
    /// A game's board, read-only: every seat with its moods in front of it,
    /// the deck and discard in the middle, and your hand along the bottom.
    /// Opened with a <see cref="BoardSession"/> (playing or spectating). Seats
    /// are placed as the web client places them -- the next player in turn
    /// order at your left -- and the board polls every few seconds, which is
    /// also what makes the server advance bot turns. Click any card for a
    /// readable close-up; the Log and Chat buttons open the recent events and
    /// the game's chat. Playing cards comes in the next phase.
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
        // Starts a little above the screen edge so the cards aren't flush against it.
        private static readonly Rect HandRect = Fraction(0.03f, 0.012f, 0.97f, 0.2143f);

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

        public string BannerText => _banner != null ? _banner.text : null;

        public string RoundText => _roundLine != null ? _roundLine.text : null;

        public string MessageText => _message != null ? _message.text : null;

        public bool DetailOpen => _detail != null && _detail.activeSelf;

        /// <summary>How many seat zones, piles and hand areas are currently drawn; tests use it to see what's on the table.</summary>
        public int TableChildCount => _table != null ? _table.childCount : 0;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _session = args as BoardSession;
            CloseOverlays();
            SetMessage(string.Empty);

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
            if (_session != null)
            {
                _session.Changed -= Render;
            }

            StopPolling();
        }

        public override bool HandleBack()
        {
            if (!_detail.activeSelf && !_logOverlay.activeSelf && !_chatOverlay.activeSelf)
            {
                return false;
            }

            CloseOverlays();
            return true;
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
                SetMessage(string.Empty);
            }
            else if (_session.State == null)
            {
                ShowLoading(result.Message);
            }
            else
            {
                SetMessage(result.Message);
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

            BuildHeader(theme);

            _message = UiFactory.Label(transform, string.Empty, 24, theme.danger);
            _message.rectTransform.anchorMin = new Vector2(0.2f, 1f);
            _message.rectTransform.anchorMax = new Vector2(0.8f, 1f);
            _message.rectTransform.pivot = new Vector2(0.5f, 1f);
            _message.rectTransform.sizeDelta = new Vector2(0f, 34f);
            _message.rectTransform.anchoredPosition = new Vector2(0f, -HeaderHeight);

            _loading = UiFactory.Label(transform, "Loading the game...", 36, theme.textMuted);
            UiFactory.Stretch(_loading.rectTransform);

            BuildDetailOverlay(theme);
            _logOverlay = BuildTextOverlay(theme, "Recent events", out _logBody);
            _chatOverlay = BuildTextOverlay(theme, "Chat", out _chatBody);
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

        private GameObject BuildTextOverlay(UiTheme theme, string title, out Text body)
        {
            var root = UiFactory.Create(title + " overlay", transform);
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.72f);

            var panel = UiFactory.Create("Panel", root);
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

        private void CloseOverlays()
        {
            _detail.SetActive(false);
            _logOverlay.SetActive(false);
            _chatOverlay.SetActive(false);
        }

        private void SetMessage(string text)
        {
            _message.text = text ?? string.Empty;
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

        private void Render()
        {
            var state = _session?.State;
            if (state == null)
            {
                return;
            }

            var theme = AppServices.Theme;
            _loading.gameObject.SetActive(false);

            _banner.text = BoardDisplay.TurnBanner(state);
            _banner.color = BoardDisplay.BannerNeedsViewer(state) ? theme.accent : theme.textPrimary;
            _roundLine.text = BoardDisplay.RoundLine(state) + (_session.IsSpectating ? "  -  watching" : string.Empty);
            _chatButtonLabel.text = state.ChatMessages.Count > 0 ? $"Chat ({state.ChatMessages.Count})" : "Chat";
            _logBody.text = state.RecentEvents.Count == 0
                ? "Nothing has happened yet."
                : string.Join("\n\n", state.RecentEvents.Select(e => e.Description));
            _chatBody.text = state.ChatMessages.Count == 0
                ? "No messages yet."
                : string.Join("\n\n", state.ChatMessages.Select(m => $"{m.SenderUsername}: {m.MessageText}"));

            ClearTable();
            var zones = BoardLayout.Assign(state.Players, state.You.GamePlayerId);
            foreach (var player in state.Players)
            {
                BuildSeat(theme, state, player, zones[player.GamePlayerId]);
            }

            BuildPiles(theme, state);
            BuildHand(theme, state);
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

            var spacing = 10f;
            var availableWidth = area.width * ReferenceWidth - 16f;
            var width = Mathf.Min(MoodWidth, (availableWidth - spacing * (moods.Count - 1)) / moods.Count);
            var row = UiFactory.Row(seat, "Moods", spacing, TextAnchor.UpperCenter);
            foreach (var mood in moods)
            {
                var card = mood;
                CardView.Create(row.transform, card, width, theme, showValue: true,
                    onClick: () => ShowDetail(card, "In play for " + player.Username));
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
                CardView.Create(column, card, MoodWidth, theme, showValue: false,
                    onClick: () => ShowDetail(card, string.IsNullOrEmpty(card.LastOwnerName) ? "In the discard pile" : "Discarded from " + card.LastOwnerName));
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
            foreach (var cardInHand in state.You.Hand)
            {
                var card = cardInHand;
                CardView.Create(row.transform, card, HandCardWidth, theme, showValue: true,
                    onClick: () => ShowDetail(card, "In your hand"));
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

            var valueLine = card.ValueIsModified
                ? $"Value right now: {card.Value}  (printed {card.BaseValue})"
                : $"Value: {card.Value}";
            var lines = new List<string>
            {
                "<size=46><b>" + card.Name + "</b></size>",
                "<color=#9aa0aa>" + where + "</color>",
                string.Empty,
                valueLine,
                string.IsNullOrWhiteSpace(card.RulesText) ? string.Empty : card.RulesText,
            };
            if (card.IsSuppressed)
            {
                lines.Add(string.Empty);
                lines.Add("Its ability is switched off by another effect.");
            }

            lines.Add(string.Empty);
            lines.Add("<color=#9aa0aa>Click anywhere to close.</color>");

            _detailText.supportRichText = true;
            _detailText.text = string.Join("\n", lines);
            _detail.SetActive(true);
        }
    }
}
