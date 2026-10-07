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
    /// A Booster Draft tournament's pod draft: each round you take one card from each of two boosters (one passed
    /// left, one passed right) until you have thirty, then the tournament builds its bracket. Opened with the
    /// tournament's id; follows the pod while it waits for the others.
    /// </summary>
    public sealed class PodDraftScreen : UiScreen
    {
        private const float CardWidth = 104f;
        private const float Pad = 6f;
        private const float PollSeconds = 4f;

        private int _id;
        private bool _built;
        private bool _busy;
        private string _direction;
        private BoardCard _selected;
        private int _round;
        private Text _info;
        private Text _note;
        private RectTransform _boosters;
        private Button _take;
        private Button _picks;
        private CardListOverlay _pool;
        private CardDetailOverlay _detail;
        private readonly Dictionary<string, List<(BoardCard Card, Image Highlight)>> _cells =
            new Dictionary<string, List<(BoardCard, Image)>>();

        public string InfoText => _info != null ? _info.text : null;

        public string NoteText => _note != null ? _note.text : null;

        public bool CanTake => _take != null && _take.interactable;

        public string SelectedName => _selected?.Name;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _busy = false;
            _id = args is int id ? id : _id;
            _selected = null;
            _direction = null;
            _round = 0;
            _pool.Close();
            _detail.Close();
            AppServices.Tournaments.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
            PollWhileShown(PollSeconds, Refresh);
        }

        public override void OnHidden()
        {
            AppServices.Tournaments.Changed -= Rebuild;
            StopPolling();
            _pool?.Close();
            _detail?.Close();
        }

        public override bool HandleBack() => _built && (_detail.Dismiss() || _pool.Dismiss());

        private void EnsureBuilt()
        {
            if (_built)
            {
                return;
            }

            _built = true;
            var theme = AppServices.Theme;
            UiFactory.Background(transform, theme.background);
            UiFactory.Header(transform, theme, "Booster Draft", () => Router.Back());

            var body = UiFactory.Create("Body", transform);
            UiFactory.Stretch(body);
            body.offsetMax = new Vector2(0f, -120f);
            var layout = body.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(24, 24, 10, 20);
            layout.spacing = 10f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _info = UiFactory.Label(body, string.Empty, 32, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            UiFactory.Size(_info.gameObject, height: 44f);
            _note = UiFactory.Label(body, string.Empty, 24, theme.textMuted, TextAnchor.MiddleCenter);
            UiFactory.Size(_note.gameObject, height: 36f);

            _boosters = UiFactory.Create("Boosters", body);
            var boosters = _boosters.gameObject.AddComponent<VerticalLayoutGroup>();
            boosters.spacing = 6f;
            boosters.childControlWidth = true;
            boosters.childControlHeight = true;
            boosters.childForceExpandWidth = true;
            boosters.childForceExpandHeight = false;
            UiFactory.Flexible(_boosters.gameObject, height: 1f);

            var buttons = UiFactory.Row(body, "Buttons", 20f, TextAnchor.MiddleCenter);
            UiFactory.Size(buttons.gameObject, height: 72f);
            _take = UiFactory.Button(buttons.transform, "Take card", theme, () => Run(TakeAsync));
            _take.gameObject.name = "Take card";
            UiFactory.Size(_take.gameObject, 420f);
            _picks = UiFactory.Button(buttons.transform, "Your picks", theme, ShowPicks, primary: false);
            _picks.gameObject.name = "Your picks";
            UiFactory.Size(_picks.gameObject, 420f);

            _pool = new CardListOverlay(transform, theme);
            _detail = new CardDetailOverlay(transform, theme);
            _pool.Chosen = card => _detail.Show(card);
        }

        private async Task Refresh()
        {
            var result = await AppServices.Tournaments.RefreshPodAsync(_id);
            if (this != null && !result.Ok)
            {
                _note.text = result.Message;
                _note.color = AppServices.Theme.danger;
            }
        }

        private void ShowPicks()
        {
            var cards = DraftDisplay.SortPool(AppServices.Tournaments.Pod?.DraftedCards ?? new List<BoardCard>());
            _pool.Show("Your picks", $"{cards.Count} of 30. Tap a card to read it.", cards);
        }

        private void Rebuild()
        {
            if (!_built)
            {
                return;
            }

            var theme = AppServices.Theme;
            foreach (Transform child in _boosters)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }

            _cells.Clear();
            var pod = AppServices.Tournaments.Pod;
            _take.interactable = false;
            if (pod == null)
            {
                _info.text = "Loading the draft...";
                _note.text = string.Empty;
                return;
            }

            if (pod.CurrentRound != _round)
            {
                _round = pod.CurrentRound;
                _selected = null;
                _direction = null;
            }

            _note.color = theme.textMuted;
            _picks.GetComponentInChildren<Text>().text = $"Your picks ({pod.DraftedCards.Count})";
            if (pod.PodStatus == "completed")
            {
                _info.text = "The draft is finished";
                _note.text = $"You drafted {pod.DraftedCards.Count} cards. The bracket starts once every pod is done.";
                return;
            }

            _info.text = $"Round {pod.CurrentRound} of {pod.TotalRounds}  -  pod of {pod.PodSize}";
            if (pod.Left == null && pod.Right == null)
            {
                _note.text = "You've taken both cards this round. Waiting for the others.";
                return;
            }

            _note.text = "Take one card from each booster. Tap a card to choose it; the eye reads it.";
            AddBooster(theme, "left", "Left booster (passed to your left)", pod.Left);
            AddBooster(theme, "right", "Right booster (passed to your right)", pod.Right);
            ShowSelection();
        }

        private void AddBooster(UiTheme theme, string direction, string title, List<BoardCard> cards)
        {
            var section = UiFactory.Create(direction + " booster", _boosters);
            var layout = section.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 4f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            var label = UiFactory.Label(section, cards == null ? title + ": taken this round" : title, 26, theme.textPrimary, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(label.gameObject, height: 36f);
            _cells[direction] = new List<(BoardCard, Image)>();
            if (cards == null)
            {
                return;
            }

            var area = UiFactory.Create("Cards", section);
            var grid = area.gameObject.AddComponent<GridLayoutGroup>();
            grid.cellSize = new Vector2(CardWidth + 2f * Pad, CardView.HeightFor(CardWidth) + 2f * Pad);
            grid.spacing = new Vector2(4f, 4f);
            grid.constraint = GridLayoutGroup.Constraint.FixedRowCount;
            grid.constraintCount = 1;
            grid.childAlignment = TextAnchor.UpperCenter;
            UiFactory.Size(area.gameObject, height: CardView.HeightFor(CardWidth) + 2f * Pad);

            foreach (var entry in cards)
            {
                var card = entry;
                var cell = UiFactory.Create(direction + " cell " + card.Name, area);
                var highlight = cell.gameObject.AddComponent<Image>();
                highlight.color = Color.clear;
                highlight.raycastTarget = false;
                var view = CardView.Create(cell, card, CardWidth, theme, showValue: false, onClick: () => Select(direction, card));
                view.anchorMin = view.anchorMax = view.pivot = new Vector2(0.5f, 0.5f);
                view.anchoredPosition = Vector2.zero;
                CardView.AddEyeButton(cell, CardWidth, Pad, "Inspect " + card.Name, theme, () => _detail.Show(card));
                _cells[direction].Add((card, highlight));
            }
        }

        private void Select(string direction, BoardCard card)
        {
            // Choosing the chosen card again lets go of it; choosing another in the same booster swaps; one in the other booster is a second pick.
            if (_selected != null && _direction == direction && _selected.CardId == card.CardId)
            {
                _selected = null;
                _direction = null;
            }
            else
            {
                _selected = card;
                _direction = direction;
            }

            ShowSelection();
        }

        private void ShowSelection()
        {
            var theme = AppServices.Theme;
            foreach (var pair in _cells)
            {
                foreach (var (card, highlight) in pair.Value)
                {
                    highlight.color = _selected != null && pair.Key == _direction && card.CardId == _selected.CardId ? theme.accent : Color.clear;
                }
            }

            _take.interactable = _selected != null && !_busy;
            _take.GetComponentInChildren<Text>().text = _selected != null ? "Take " + _selected.Name : "Take card";
        }

        private async Task TakeAsync()
        {
            if (_busy || _selected == null)
            {
                return;
            }

            _busy = true;
            _take.interactable = false;
            var card = _selected;
            var direction = _direction;
            var result = await AppServices.Tournaments.PickAsync(_id, direction, card);
            if (this == null)
            {
                return;
            }

            _busy = false;
            _selected = null;
            _direction = null;
            if (!result.Ok)
            {
                _note.text = result.Message;
                _note.color = AppServices.Theme.danger;
            }

            Rebuild();
        }
    }
}
