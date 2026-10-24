using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Draws one step of the tour's sample game: the two seats, each with a scoreboard and the moods in front of it, and the
    /// shared discard pile. It is a picture, not the live board (nothing here is tapped), but the cards are drawn the way the
    /// board draws them -- real art, a mood on its alternate value upside down, a suppressed one on its side.
    /// </summary>
    public sealed class SampleTable
    {
        public const float Width = 1300f;
        public const float Height = 960f;

        private const float CardWidth = 100f;
        private const float DiscardWidth = 150f;

        private readonly UiTheme _theme;
        private readonly RectTransform _root;
        private readonly Dictionary<string, RectTransform> _targets = new Dictionary<string, RectTransform>();
        private int _nextCardId;

        public SampleTable(Transform parent, UiTheme theme)
        {
            _theme = theme;
            _root = UiFactory.Create("Sample table", parent);
            _root.anchorMin = _root.anchorMax = _root.pivot = new Vector2(0.5f, 0.5f);
            _root.sizeDelta = new Vector2(Width, Height);
            var background = _root.gameObject.AddComponent<Image>();
            background.color = theme.background;
            background.raycastTarget = false;
            _root.gameObject.SetActive(false);
        }

        public RectTransform Root => _root;

        public bool IsShown => _root.gameObject.activeSelf;

        /// <summary>The parts of the picture a step can point at, by the keys its focus uses ("score:MarkBot", "mood:Laziness", "discard").</summary>
        public IReadOnlyDictionary<string, RectTransform> Targets => _targets;

        public void Hide() => _root.gameObject.SetActive(false);

        /// <summary>Reads the cards the game uses (Assets/Resources/Tour/sample-cards.json) the first time they are needed.</summary>
        public static void EnsureCards()
        {
            if (SampleGame.CardsLoaded)
            {
                return;
            }

            var json = Resources.Load<TextAsset>("Tour/sample-cards");
            if (json != null)
            {
                SampleGame.LoadCards(json.text);
            }
        }

        public void Show(SampleStep step)
        {
            EnsureCards();
            foreach (Transform child in _root)
            {
                child.gameObject.SetActive(false);
                Object.Destroy(child.gameObject);
            }

            _targets.Clear();
            _nextCardId = 1;
            _root.gameObject.SetActive(true);

            var state = step.State;
            var layout = _root.gameObject.GetComponent<VerticalLayoutGroup>();
            if (layout == null)
            {
                layout = _root.gameObject.AddComponent<VerticalLayoutGroup>();
            }

            layout.padding = new RectOffset(24, 24, 20, 20);
            layout.spacing = 10f;
            layout.childAlignment = TextAnchor.UpperCenter;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            var turn = state.Turn != null ? $"{state.Turn}'s turn" : "Round over";
            var header = UiFactory.Label(_root, $"Round {state.Round}  -  {turn}", 32, _theme.textMuted, TextAnchor.MiddleCenter, FontStyle.Bold);
            header.name = "Sample header";
            UiFactory.Size(header.gameObject, height: 44f);

            BuildSeat(state, state.Them);
            BuildSeat(state, state.You);
            BuildDiscard(state);
        }

        private void BuildSeat(SampleState state, SampleSeat seat)
        {
            var row = UiFactory.Row(_root, "Seat " + seat.Name, 24f, TextAnchor.MiddleLeft);
            // The discard pile sits in the gap between the two rows of cards, so the moods have the whole width.
            row.padding = new RectOffset(0, 0, 0, 0);
            row.childForceExpandWidth = false;
            row.childForceExpandHeight = false;
            UiFactory.Size(row.gameObject, height: 400f);

            BuildScoreboard(row.transform, state, seat);

            var moods = UiFactory.Row(row.transform, "Moods", 10f, TextAnchor.MiddleLeft);
            moods.childForceExpandWidth = false;
            moods.childForceExpandHeight = false;
            UiFactory.Flexible(moods.gameObject, width: 1f);
            foreach (var mood in seat.Moods)
            {
                BuildMood(moods.transform, mood);
            }
        }

        // A player's scoreboard, like the board's: a score in a box with a trophy for each round won beside it, then the name.
        private void BuildScoreboard(Transform parent, SampleState state, SampleSeat seat)
        {
            var isTurn = state.Turn == seat.Name;
            var panel = UiFactory.Create("Scoreboard " + seat.Name, parent);
            UiFactory.Size(panel.gameObject, 300f, 210f);
            var image = panel.gameObject.AddComponent<Image>();
            image.color = isTurn ? Color.Lerp(_theme.panel, _theme.accent, 0.35f) : _theme.panel;
            image.raycastTarget = false;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(16, 16, 12, 12);
            layout.spacing = 6f;
            layout.childAlignment = TextAnchor.UpperLeft;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            _targets["score:" + seat.Name] = panel;

            var name = UiFactory.Label(panel, seat.Name + (seat.IsYou ? "  (you)" : string.Empty), 30, _theme.textPrimary,
                TextAnchor.MiddleLeft, FontStyle.Bold);
            name.raycastTarget = false;
            UiFactory.Size(name.gameObject, height: 40f);

            var scores = UiFactory.Row(panel, "Scores", 12f, TextAnchor.MiddleLeft);
            scores.childForceExpandWidth = false;
            scores.childForceExpandHeight = false;
            UiFactory.Size(scores.gameObject, height: 100f);

            var box = UiFactory.Create("Score", scores.transform);
            UiFactory.Size(box.gameObject, 130f, 100f);
            var outer = box.gameObject.AddComponent<Image>();
            outer.color = _theme.textMuted;
            outer.raycastTarget = false;
            var inner = UiFactory.Create("Inside", box);
            UiFactory.Stretch(inner);
            inner.offsetMin = new Vector2(3f, 3f);
            inner.offsetMax = new Vector2(-3f, -3f);
            var innerImage = inner.gameObject.AddComponent<Image>();
            innerImage.color = _theme.background;
            innerImage.raycastTarget = false;
            var points = UiFactory.Label(inner, SampleGame.Score(seat).ToString(), 64, _theme.textPrimary, TextAnchor.MiddleCenter, FontStyle.Bold);
            points.name = "Points";
            points.raycastTarget = false;
            UiFactory.Stretch(points.rectTransform);

            // A trophy for each round won, and a dim one for each still to win.
            var column = UiFactory.Create("Trophies", scores.transform);
            UiFactory.Size(column.gameObject, 36f, 100f);
            var stack = column.gameObject.AddComponent<VerticalLayoutGroup>();
            stack.spacing = 4f;
            stack.childAlignment = TextAnchor.MiddleCenter;
            stack.childControlWidth = true;
            stack.childControlHeight = true;
            stack.childForceExpandWidth = false;
            stack.childForceExpandHeight = false;
            for (var i = 0; i < SampleGame.WinsNeeded; i++)
            {
                var won = i < seat.Wins;
                var trophy = UiFactory.Create(won ? "Trophy won" : "Trophy to win", column);
                var icon = trophy.gameObject.AddComponent<Image>();
                icon.sprite = UiIcons.Trophy();
                icon.color = won ? _theme.gold : new Color(_theme.textMuted.r, _theme.textMuted.g, _theme.textMuted.b, 0.35f);
                icon.raycastTarget = false;
                UiFactory.Size(trophy.gameObject, 30f, 30f);
            }

            var hand = UiFactory.Label(panel, $"Hand: {seat.Hand} card{(seat.Hand == 1 ? string.Empty : "s")}", 26, _theme.textMuted, TextAnchor.MiddleLeft);
            hand.name = "Hand count";
            hand.raycastTarget = false;
            UiFactory.Size(hand.gameObject, height: 34f);
        }

        // One mood: upright, or in a square slot if it lies on its side. Turned and shaded by the same drawing as the board's.
        private void BuildMood(Transform parent, SampleMood mood)
        {
            var card = SampleGame.ToBoardCard(mood, _nextCardId++);
            RectTransform view;
            if (!mood.Suppressed)
            {
                view = CardView.Create(parent, card, CardWidth, _theme, showValue: true, alternateValue: card.IsAltValueActive);
            }
            else
            {
                var side = CardView.HeightFor(CardWidth);
                var slot = UiFactory.Create("Suppressed slot", parent);
                UiFactory.Size(slot.gameObject, side, side);
                view = CardView.Create(slot, card, CardWidth, _theme, showValue: true, onItsSide: true, alternateValue: card.IsAltValueActive);
                view.anchorMin = view.anchorMax = view.pivot = new Vector2(0.5f, 0.5f);
                view.anchoredPosition = Vector2.zero;
            }

            // Not clickable: it is a picture.
            foreach (var graphic in view.GetComponentsInChildren<Graphic>(true))
            {
                graphic.raycastTarget = false;
            }

            _targets["mood:" + mood.Card] = view;
        }

        // The shared discard pile, at the right edge between the two seats: how many, and the one on top.
        private void BuildDiscard(SampleState state)
        {
            var column = UiFactory.Create("Discard", _root);
            column.anchorMin = column.anchorMax = new Vector2(1f, 0.5f);
            column.pivot = new Vector2(1f, 0.5f);
            column.sizeDelta = new Vector2(DiscardWidth, 260f);
            column.anchoredPosition = new Vector2(-24f, -20f);
            column.gameObject.AddComponent<LayoutElement>().ignoreLayout = true;
            var layout = column.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 8f;
            layout.childAlignment = TextAnchor.UpperCenter;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            _targets["discard"] = column;

            var label = UiFactory.Label(column, $"Discard {state.Discard.Count}", 28, _theme.textMuted, TextAnchor.MiddleCenter, FontStyle.Bold);
            label.name = "Discard count";
            label.raycastTarget = false;
            UiFactory.Size(label.gameObject, height: 38f);

            if (state.Discard.Count == 0)
            {
                var empty = UiFactory.Create("Empty pile", column);
                UiFactory.Size(empty.gameObject, 120f, CardView.HeightFor(120f));
                var faint = empty.gameObject.AddComponent<Image>();
                faint.color = _theme.faint;
                faint.raycastTarget = false;
                return;
            }

            var top = SampleGame.ToBoardCard(new SampleMood { Card = state.Discard[state.Discard.Count - 1] }, 900);
            var holder = UiFactory.Create("Top of the pile", column);
            UiFactory.Size(holder.gameObject, 120f, CardView.HeightFor(120f));
            var view = CardView.Create(holder, top, 120f, _theme, showValue: false);
            UiFactory.Stretch(view);
            foreach (var graphic in view.GetComponentsInChildren<Graphic>(true))
            {
                graphic.raycastTarget = false;
            }
        }
    }
}
