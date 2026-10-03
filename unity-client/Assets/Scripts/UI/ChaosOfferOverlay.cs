using System;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The start-of-round choice in a Chaos Draft: pick one of two effects, then the card (from your hand, and in
    /// Open Team Play your partner's too) it goes on. In Open Team Play the partner then agrees or sends it back.
    /// Nothing is sent until the card is chosen and Attach is pressed, so a slip on a phone costs nothing.
    /// </summary>
    public sealed class ChaosOfferOverlay
    {
        private const float CardWidth = 150f;

        private readonly UiTheme _theme;
        private readonly GameObject _root;
        private readonly Text _title;
        private readonly Text _status;
        private readonly RectTransform _body;
        private readonly RectTransform _buttons;
        private int? _effectId;
        private int? _cardId;
        private string _teammateOwner;
        private string _offerKey;

        /// <summary>Effect id, card id, and the partner's name when the card is theirs (null for your own).</summary>
        public Action<int, int, string> Attach;

        public Action<bool> Confirm;

        public ChaosOfferOverlay(Transform parent, UiTheme theme)
        {
            _theme = theme;
            var root = UiFactory.Create("Chaos overlay", parent);
            _root = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.82f);

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = new Vector2(0.06f, 0.06f);
            panel.anchorMax = new Vector2(0.94f, 0.94f);
            panel.offsetMin = panel.offsetMax = Vector2.zero;
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(40, 40, 30, 30);
            layout.spacing = 18f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _title = UiFactory.Label(panel, "Chaos Draft", 44, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            UiFactory.Size(_title.gameObject, height: 60f);
            _status = UiFactory.Label(panel, string.Empty, 30, theme.textPrimary, TextAnchor.MiddleCenter);

            var scroll = UiFactory.ScrollList(panel, out _body, spacing: 16f);
            UiFactory.Flexible(scroll.gameObject, height: 1f);

            _buttons = (RectTransform)UiFactory.Row(panel, "Buttons", 24f, TextAnchor.MiddleCenter).transform;
            UiFactory.Size(_buttons.gameObject, height: UiFactory.ControlHeight);

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public string StatusText => _status.text;

        /// <summary>The effect picked so far (stage two), if any.</summary>
        public int? ChosenEffectId => _effectId;

        /// <summary>Draws what the offer asks of the viewer now: the two effects, the cards, or the partner's proposal.</summary>
        public void Show(GameState state)
        {
            var offer = state.ChaosOffer.Offer;
            var key = $"{state.Round.RoundNumber}|{offer.Effect1.Id}|{offer.Effect2.Id}|{offer.Phase}";
            if (key != _offerKey)
            {
                _offerKey = key;
                _effectId = null;
                _cardId = null;
            }

            _root.SetActive(true);
            Clear(_body);
            Clear(_buttons);

            if (offer.IsAwaitingConfirmation)
            {
                ShowProposal(state, offer);
            }
            else if (_effectId == null)
            {
                ShowEffects(state, offer);
            }
            else
            {
                ShowCards(state, offer);
            }
        }

        public void Hide()
        {
            _root.SetActive(false);
            _offerKey = null;
        }

        /// <summary>Back from choosing the card returns to the two effects. False when there's nowhere to go back to.</summary>
        public bool Back()
        {
            if (!IsOpen || _effectId == null)
            {
                return false;
            }

            _effectId = null;
            _cardId = null;
            return true;
        }

        private void ShowEffects(GameState state, ChaosOffer offer)
        {
            _status.text = offer.IsTeamOffer
                ? "Choose one of these two effects for your team's card:"
                : "Choose one of these two effects for one of your cards:";

            foreach (var effect in new[] { offer.Effect1, offer.Effect2 })
            {
                var chosen = effect;
                var tint = CardView.ChaosColor(chosen.Rarity);
                var button = UiFactory.Button(_body, ChaosDisplay.EffectSummary(chosen), _theme, () =>
                {
                    _effectId = chosen.Id;
                    Show(state);
                }, primary: false);
                button.gameObject.name = "Effect " + chosen.Id;
                var label = button.GetComponentInChildren<Text>();
                label.fontSize = 32;
                label.color = tint;
                label.horizontalOverflow = HorizontalWrapMode.Wrap;
                UiFactory.Stretch(label.rectTransform);
                label.rectTransform.offsetMin = new Vector2(24f, 12f);
                label.rectTransform.offsetMax = new Vector2(-24f, -12f);
                UiFactory.Size(button.gameObject, height: 190f);
            }
        }

        private void ShowCards(GameState state, ChaosOffer offer)
        {
            var effect = offer.Effect1.Id == _effectId ? offer.Effect1 : offer.Effect2;
            _status.text = ChaosDisplay.EffectSummary(effect) + "\nAttach it to which card?";

            var cards = ChaosDisplay.AttachableCards(state);
            if (_cardId.HasValue && cards.All(c => c.Key.CardId != _cardId.Value))
            {
                _cardId = null;
            }

            var grid = UiFactory.Create("Cards", _body);
            var layout = grid.gameObject.AddComponent<GridLayoutGroup>();
            layout.cellSize = new Vector2(CardWidth + 16f, CardView.HeightFor(CardWidth) + 16f);
            layout.spacing = new Vector2(10f, 10f);
            layout.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
            layout.constraintCount = 8;
            layout.childAlignment = TextAnchor.UpperCenter;

            foreach (var entry in cards)
            {
                var card = entry.Key;
                var owner = entry.Value;
                var cell = UiFactory.Create("Cell " + card.Name, grid);
                var highlight = cell.gameObject.AddComponent<Image>();
                highlight.color = _cardId == card.CardId ? _theme.accent : Color.clear;
                highlight.raycastTarget = false;
                var view = CardView.Create(cell, card, CardWidth, _theme, showValue: false, onClick: () =>
                {
                    _cardId = card.CardId;
                    _teammateOwner = owner;
                    Show(state);
                });
                view.anchorMin = view.anchorMax = view.pivot = new Vector2(0.5f, 0.5f);
                view.anchoredPosition = Vector2.zero;
            }

            var back = UiFactory.Button(_buttons, "Back", _theme, () =>
            {
                _effectId = null;
                _cardId = null;
                Show(state);
            }, primary: false);
            back.gameObject.name = "Back to effects";
            UiFactory.Size(back.gameObject, 300f);

            var chosen = cards.FirstOrDefault(c => c.Key.CardId == _cardId);
            var attach = UiFactory.Button(_buttons, chosen.Key != null ? "Attach to " + chosen.Key.Name : "Attach", _theme, () =>
            {
                Attach?.Invoke(effect.Id, _cardId.Value, _teammateOwner);
            });
            attach.gameObject.name = "Attach";
            attach.interactable = _cardId.HasValue;
            UiFactory.Size(attach.gameObject, 520f);
        }

        private void ShowProposal(GameState state, ChaosOffer offer)
        {
            var proposer = BoardDisplay.PlayerById(state, offer.ProposerGamePlayerId ?? -1);
            _status.text = $"{(proposer != null ? proposer.Username : "Your partner")} proposed a Chaos effect. Do you agree?";

            var disagree = UiFactory.Button(_buttons, "Disagree", _theme, () => Confirm?.Invoke(false), primary: false);
            disagree.gameObject.name = "Disagree";
            UiFactory.Size(disagree.gameObject, 300f);
            var agree = UiFactory.Button(_buttons, "Agree", _theme, () => Confirm?.Invoke(true));
            agree.gameObject.name = "Agree";
            UiFactory.Size(agree.gameObject, 300f);
        }

        private static void Clear(RectTransform parent)
        {
            foreach (Transform child in parent)
            {
                child.gameObject.SetActive(false);
                UnityEngine.Object.Destroy(child.gameObject);
            }
        }
    }
}
