using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Your League collection: every card you own as a thumbnail with how many copies, in color, rarity, name order.
    /// Tapping one shows it large.
    /// </summary>
    public sealed class LeagueCollectionScreen : UiScreen
    {
        private const float CardWidth = 150f;
        private const int Columns = 10;

        private bool _built;
        private Text _summary;
        private Text _status;
        private RectTransform _content;
        private CardDetailOverlay _detail;

        public string SummaryText => _summary != null ? _summary.text : null;

        public string StatusText => _status != null ? _status.text : null;

        public CardDetailOverlay Detail => _detail;

        /// <summary>How many card thumbnails are showing.</summary>
        public int CardCount => _content != null ? _content.GetComponentsInChildren<Transform>().Count(t => t.name.StartsWith("Card ")) : 0;

        private void EnsureBuilt()
        {
            if (_built)
            {
                return;
            }

            _built = true;
            var theme = AppServices.Theme;
            UiFactory.Background(transform, theme.background);
            UiFactory.Header(transform, theme, "My collection", () => Router.Back());

            var body = UiFactory.Create("Body", transform);
            UiFactory.Stretch(body);
            body.offsetMin = new Vector2(40f, 30f);
            body.offsetMax = new Vector2(-40f, -130f);
            var layout = body.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 10f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _summary = UiFactory.Label(body, string.Empty, 28, theme.textMuted, TextAnchor.MiddleLeft);
            _summary.gameObject.name = "Collection summary";
            UiFactory.Size(_summary.gameObject, height: 44f);
            _status = UiFactory.Label(body, string.Empty, 26, theme.textMuted, TextAnchor.MiddleLeft);
            _status.gameObject.name = "Status";
            UiFactory.Size(_status.gameObject, height: 40f);

            var scroll = UiFactory.ScrollList(body, out _content, spacing: 8f);
            UiFactory.Flexible(scroll.gameObject, height: 1f);

            _detail = new CardDetailOverlay(transform, theme);
        }

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _detail.Close();
            _status.text = string.Empty;
            AppServices.League.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
        }

        public override void OnHidden()
        {
            AppServices.League.Changed -= Rebuild;
            _detail?.Close();
        }

        public override bool HandleBack() => _detail != null && _detail.Dismiss();

        private async Task Refresh()
        {
            var result = await AppServices.League.RefreshCollectionAsync();
            if (this != null && !result.Ok)
            {
                _status.text = result.Message;
                _status.color = AppServices.Theme.danger;
            }
        }

        private void Rebuild()
        {
            if (_content == null)
            {
                return;
            }

            foreach (Transform child in _content)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }

            var theme = AppServices.Theme;
            var cards = AppServices.League.Collection;
            _summary.text = LeagueDisplay.CollectionSummary(cards);
            if (cards == null)
            {
                var loading = UiFactory.Label(_content, "Loading your collection...", 28, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(loading.gameObject, height: 60f);
                return;
            }

            if (cards.Count == 0)
            {
                var none = UiFactory.Label(_content, "Your collection is empty -- join the League to be granted a starter deck.", 28, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 70f);
                return;
            }

            var grid = UiFactory.Create("Cards", _content);
            var layout = grid.gameObject.AddComponent<GridLayoutGroup>();
            layout.cellSize = new Vector2(CardWidth + 12f, CardView.HeightFor(CardWidth) + 12f);
            layout.spacing = new Vector2(6f, 6f);
            layout.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
            layout.constraintCount = Columns;
            layout.childAlignment = TextAnchor.UpperLeft;

            foreach (var card in cards)
            {
                var shown = card;
                var cell = UiFactory.Create("Cell " + card.Name, grid);
                var view = CardView.Create(cell, shown, CardWidth, theme, showValue: false, onClick: () => _detail.Show(shown, shown.Copies + " owned"));
                view.anchorMin = view.anchorMax = view.pivot = new Vector2(0.5f, 0.5f);
                view.anchoredPosition = Vector2.zero;
                CardBadge.Add(cell, "Copies " + card.Name, "x" + card.Copies, CardBadge.Place.BottomRight, theme.accent, theme.onAccent);
            }
        }
    }
}
