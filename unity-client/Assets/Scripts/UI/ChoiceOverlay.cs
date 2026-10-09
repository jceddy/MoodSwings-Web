using System;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>What a <see cref="ChoiceOverlay"/> is asking, and how it can be dismissed.</summary>
    public sealed class ChoicePrompt
    {
        public string Title { get; set; }

        /// <summary>A few lines under the title: the card's rules, or what happened to need an answer.</summary>
        public string Context { get; set; }

        /// <summary>Shown large on the left, when there's a card the question is about.</summary>
        public BoardCard Card { get; set; }

        public string SubmitLabel { get; set; } = "OK";

        /// <summary>Playing a card can be called off; a pending decision can't be.</summary>
        public bool Cancellable { get; set; } = true;
    }

    /// <summary>
    /// The form for a <see cref="ChoiceForm"/>: one section per thing the card (or
    /// decision) asks for, each a list of tappable options, with a button to
    /// send the answers. It only draws and forwards taps; what's legal and what
    /// gets sent is all in the form. Built to be used by touch: nothing here needs a
    /// drop-down or a modifier key.
    /// </summary>
    public sealed class ChoiceOverlay
    {
        private const float OptionHeight = 56f;

        private sealed class OptionButton
        {
            public string Path;
            public string Id;
            public Image Background;
            public Text Label;
        }

        private readonly UiTheme _theme;
        private readonly GameObject _root;
        private readonly Text _title;
        private readonly Text _context;
        private readonly Text _problem;
        private readonly RectTransform _cardColumn;
        private readonly RectTransform _cardHolder;
        private readonly RectTransform _fields;
        private readonly Button _submit;
        private readonly Text _submitLabel;
        private readonly Button _cancel;
        private readonly List<OptionButton> _options = new List<OptionButton>();
        /// <summary>Called with a card and where it is when the player taps the eye on an option that is a card.</summary>
        public Action<BoardCard, string> Inspect;

        private ChoiceForm _form;
        private ChoicePrompt _prompt;
        private Action _onSubmit;
        private Action _onCancel;
        private bool _busy;
        private string _error;

        public ChoiceOverlay(Transform parent, UiTheme theme)
        {
            _theme = theme;

            var root = UiFactory.Create("Choices overlay", parent);
            _root = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.82f);

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = panel.anchorMax = panel.pivot = new Vector2(0.5f, 0.5f);
            panel.sizeDelta = new Vector2(1640f, 920f);
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var split = panel.gameObject.AddComponent<HorizontalLayoutGroup>();
            split.padding = new RectOffset(36, 36, 30, 30);
            split.spacing = 40f;
            split.childControlWidth = true;
            split.childControlHeight = true;
            split.childForceExpandWidth = false;
            split.childForceExpandHeight = true;

            _cardColumn = UiFactory.Create("CardColumn", panel);
            UiFactory.Size(_cardColumn.gameObject, 400f);
            var cardLayout = _cardColumn.gameObject.AddComponent<VerticalLayoutGroup>();
            cardLayout.childAlignment = TextAnchor.UpperCenter;
            cardLayout.childControlWidth = true;
            cardLayout.childControlHeight = true;
            cardLayout.childForceExpandWidth = false;
            cardLayout.childForceExpandHeight = false;
            _cardHolder = UiFactory.Create("Card", _cardColumn);

            var right = UiFactory.Create("Form", panel);
            UiFactory.Flexible(right.gameObject, width: 1f);
            var rightLayout = right.gameObject.AddComponent<VerticalLayoutGroup>();
            rightLayout.spacing = 12f;
            rightLayout.childControlWidth = true;
            rightLayout.childControlHeight = true;
            rightLayout.childForceExpandWidth = true;
            rightLayout.childForceExpandHeight = false;

            _title = UiFactory.Label(right, string.Empty, 44, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(_title.gameObject, height: 60f);
            _context = UiFactory.Label(right, string.Empty, 26, theme.textMuted, TextAnchor.UpperLeft);

            UiFactory.ScrollList(right, out _fields, spacing: 22f);
            UiFactory.Flexible(_fields.parent.parent.gameObject, height: 1f);

            _problem = UiFactory.Label(right, string.Empty, 26, theme.danger, TextAnchor.MiddleLeft);
            UiFactory.Size(_problem.gameObject, height: 38f);

            var buttons = UiFactory.Row(right, "Buttons", 24f, TextAnchor.MiddleRight);
            UiFactory.Size(buttons.gameObject, height: UiFactory.ControlHeight);
            _cancel = UiFactory.Button(buttons.transform, "Cancel", theme, () => Cancel(), primary: false);
            _cancel.gameObject.name = "Cancel";
            UiFactory.Size(_cancel.gameObject, 260f);
            _submit = UiFactory.Button(buttons.transform, "OK", theme, () => _onSubmit?.Invoke());
            _submit.gameObject.name = "Submit";
            UiFactory.Size(_submit.gameObject, 340f);
            _submitLabel = _submit.GetComponentInChildren<Text>();

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public ChoiceForm Form => _form;

        public string TitleText => _title.text;

        public string ProblemText => _problem.text;

        public bool SubmitEnabled => _submit.interactable;

        public bool Cancellable => IsOpen && _prompt != null && _prompt.Cancellable;

        public void Open(ChoiceForm form, ChoicePrompt prompt, Action onSubmit, Action onCancel)
        {
            Detach();
            _form = form;
            _prompt = prompt;
            _onSubmit = onSubmit;
            _onCancel = onCancel;
            _busy = false;
            _error = null;
            _form.FieldsChanged += Rebuild;

            _title.text = prompt.Title;
            _context.text = prompt.Context ?? string.Empty;
            _context.gameObject.SetActive(!string.IsNullOrEmpty(prompt.Context));
            _submitLabel.text = prompt.SubmitLabel;
            _cancel.gameObject.SetActive(prompt.Cancellable);

            foreach (Transform child in _cardHolder)
            {
                child.gameObject.SetActive(false);
                UnityEngine.Object.Destroy(child.gameObject);
            }

            _cardColumn.gameObject.SetActive(prompt.Card != null);
            if (prompt.Card != null)
            {
                const float width = 380f;
                var card = CardView.Create(_cardHolder, prompt.Card, width, _theme, showValue: false);
                card.name = "Prompt card";
                UiFactory.Stretch(card);
                UiFactory.Size(_cardHolder.gameObject, width, CardView.HeightFor(width));
            }

            _root.SetActive(true);
            Rebuild();
        }

        /// <summary>Hides it without calling anything back.</summary>
        public void Close()
        {
            Detach();
            _root.SetActive(false);
            _form = null;
            _prompt = null;
        }

        /// <summary>Back / Escape: calls it off if that's allowed. True if it was.</summary>
        public bool Cancel()
        {
            if (!Cancellable || _busy)
            {
                return false;
            }

            var callback = _onCancel;
            Close();
            callback?.Invoke();
            return true;
        }

        /// <summary>Greys the buttons while the answer is on its way, so a slow reply can't be sent twice.</summary>
        public void SetBusy(bool busy, string label = null)
        {
            _busy = busy;
            _submitLabel.text = busy && label != null ? label : _prompt?.SubmitLabel ?? "OK";
            _cancel.interactable = !busy;
            Refresh();
        }

        /// <summary>Puts the server's reason for refusing an answer where the player will see it, until they change something.</summary>
        public void ShowError(string message)
        {
            _error = message;
            Refresh();
        }

        private void Detach()
        {
            if (_form != null)
            {
                _form.FieldsChanged -= Rebuild;
            }
        }

        // --- drawing ---------------------------------------------------------------------------

        private void Rebuild()
        {
            foreach (Transform child in _fields)
            {
                child.gameObject.SetActive(false);
                UnityEngine.Object.Destroy(child.gameObject);
            }

            _options.Clear();
            foreach (var field in _form.Fields)
            {
                BuildField(_fields, field, field.Key, 0);
            }

            Refresh();
        }

        private void BuildField(Transform parent, ChoiceField field, string path, int depth)
        {
            var section = UiFactory.Create("Field " + field.Key, parent);
            var layout = section.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 8f;
            layout.padding = new RectOffset(depth * 36, 0, 0, 0);
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            if (field.Type == "bool")
            {
                var toggle = UiFactory.Toggle(section, field.Label, _theme, _form.IsChecked(path));
                toggle.onValueChanged.AddListener(isOn =>
                {
                    _form.SetChecked(path, isOn);
                    Changed();
                });
                return;
            }

            Caption(section, field.Label + (field.Required ? "  (required)" : string.Empty) + CountHint(field));

            switch (field.Type)
            {
                case "nested":
                    foreach (var inner in field.Fields ?? new List<ChoiceField>())
                    {
                        BuildField(section, inner, ChoiceForm.PathOf(path, inner), depth + 1);
                    }

                    break;
                case "card_order":
                    BuildOrder(section, field, path);
                    break;
                case "value":
                    BuildValues(section, field, path);
                    break;
                default:
                    BuildOptions(section, field, path);
                    break;
            }
        }

        private void Caption(Transform parent, string text)
        {
            UiFactory.Label(parent, text, 28, _theme.textPrimary, TextAnchor.UpperLeft, FontStyle.Bold);
        }

        private static string CountHint(ChoiceField field)
        {
            var count = field.Count;
            if (!field.Multi || count == null)
            {
                return string.Empty;
            }

            if (count.Min.HasValue && count.Max.HasValue && count.Min == count.Max)
            {
                return count.ZeroOk ? $"  - none, or exactly {count.Min}" : $"  - choose exactly {count.Min}";
            }

            if (count.Max.HasValue)
            {
                return $"  - up to {count.Max}";
            }

            return count.Min.HasValue ? $"  - at least {count.Min}" : string.Empty;
        }

        private void BuildOptions(Transform parent, ChoiceField field, string path)
        {
            var options = _form.OptionsFor(field);
            if (options.Count == 0)
            {
                var none = UiFactory.Label(parent, field.OptionalIfNoTargets
                    ? "Nothing to choose from - this card will do nothing."
                    : "Nothing to choose from.", 26, _theme.textMuted, TextAnchor.UpperLeft);
                none.fontStyle = FontStyle.Italic;
                return;
            }

            string group = null;
            foreach (var option in options)
            {
                if (!string.IsNullOrEmpty(option.Group) && option.Group != group)
                {
                    group = option.Group;
                    UiFactory.Label(parent, group, 24, _theme.textMuted, TextAnchor.LowerLeft);
                }

                AddOption(parent, path, option, field.Multi);
            }
        }

        private void AddOption(Transform parent, string path, ChoiceOption option, bool multi)
        {
            var button = UiFactory.Button(parent, option.Label, _theme, () =>
            {
                if (multi)
                {
                    _form.Toggle(path, option.Id);
                }
                else
                {
                    _form.Select(path, option.Id);
                }

                Changed();
            }, primary: false);
            button.gameObject.name = "Option " + option.Label;
            UiFactory.Size(button.gameObject, height: OptionHeight);

            var label = button.GetComponentInChildren<Text>();
            label.alignment = TextAnchor.MiddleLeft;
            label.fontSize = 26;
            label.fontStyle = FontStyle.Normal;
            label.rectTransform.offsetMin = new Vector2(20f, 0f);

            // A card can be looked at (its rules, its value) before it is chosen: an eye at the right end of the row.
            if (option.Card != null)
            {
                var card = option.Card;
                var where = option.Where;
                label.rectTransform.offsetMax = new Vector2(-(OptionHeight + 12f), 0f);
                var eye = UiFactory.Button(button.transform, string.Empty, _theme, () => Inspect?.Invoke(card, where), primary: false);
                eye.gameObject.name = "Inspect " + option.Label;
                var eyeRect = (RectTransform)eye.transform;
                eyeRect.anchorMin = eyeRect.anchorMax = eyeRect.pivot = new Vector2(1f, 0.5f);
                eyeRect.sizeDelta = new Vector2(OptionHeight - 12f, OptionHeight - 12f);
                eyeRect.anchoredPosition = new Vector2(-8f, 0f);
                eye.GetComponent<LayoutElement>().ignoreLayout = true;
                var icon = UiFactory.Create("Eye", eye.transform);
                UiFactory.Stretch(icon);
                icon.offsetMin = new Vector2(5f, 5f);
                icon.offsetMax = new Vector2(-5f, -5f);
                var image = icon.gameObject.AddComponent<Image>();
                image.sprite = UiIcons.Eye();
                image.raycastTarget = false;
            }

            _options.Add(new OptionButton
            {
                Path = path,
                Id = option.Id,
                Background = button.GetComponent<Image>(),
                Label = label,
            });
        }

        private void BuildValues(Transform parent, ChoiceField field, string path)
        {
            var grid = UiFactory.Create("Values", parent);
            var layout = grid.gameObject.AddComponent<GridLayoutGroup>();
            layout.cellSize = new Vector2(92f, OptionHeight);
            layout.spacing = new Vector2(10f, 10f);
            layout.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
            layout.constraintCount = 8;

            foreach (var option in _form.OptionsFor(field))
            {
                AddOption(grid, path, option, field.Multi);
            }
        }

        private void BuildOrder(Transform parent, ChoiceField field, string path)
        {
            var cards = (field.Cards ?? new List<OrderedCard>()).ToDictionary(c => c.CardId.ToString());
            var order = _form.Selected(path);
            for (var i = 0; i < order.Count; i++)
            {
                var id = order[i];
                var row = UiFactory.Row(parent, "Order " + id, 12f, TextAnchor.MiddleLeft);
                row.gameObject.AddComponent<Image>().color = Color.Lerp(_theme.panel, Color.white, 0.10f);
                row.padding = new RectOffset(20, 12, 8, 8);
                UiFactory.Size(row.gameObject, height: 80f);

                var text = cards.TryGetValue(id, out var card)
                    ? $"{i + 1}.  {card.Name}" + (string.IsNullOrEmpty(card.Description) ? string.Empty : "\n" + card.Description)
                    : $"{i + 1}.  Card {id}";
                var label = UiFactory.Label(row.transform, text, 24, _theme.textPrimary, TextAnchor.MiddleLeft);
                UiFactory.Flexible(label.gameObject, width: 1f);

                var capturedId = id;
                var up = UiFactory.Button(row.transform, "Up", _theme, () => Reorder(path, capturedId, -1), primary: false);
                up.gameObject.name = "Up " + (card?.Name ?? id);
                UiFactory.Size(up.gameObject, 110f);
                up.interactable = i > 0;
                var down = UiFactory.Button(row.transform, "Down", _theme, () => Reorder(path, capturedId, 1), primary: false);
                down.gameObject.name = "Down " + (card?.Name ?? id);
                UiFactory.Size(down.gameObject, 110f);
                down.interactable = i < order.Count - 1;
            }
        }

        private void Reorder(string path, string id, int delta)
        {
            _form.Move(path, id, delta);
            _error = null;
            Rebuild();
        }

        private void Changed()
        {
            _error = null;
            Refresh();
        }

        /// <summary>Re-colors the options for what's picked and updates the message and the send button.</summary>
        private void Refresh()
        {
            if (_form == null)
            {
                return;
            }

            foreach (var option in _options)
            {
                var picked = _form.IsSelected(option.Path, option.Id);
                option.Background.color = picked ? _theme.accent : Color.Lerp(_theme.panel, Color.white, 0.10f);
                option.Label.color = picked ? _theme.background : _theme.textPrimary;
            }

            var problem = _error ?? _form.Problem;
            if (problem != null)
            {
                _problem.text = problem;
                _problem.color = _theme.danger;
            }
            else if (!_form.RequiredFilled)
            {
                _problem.text = "Make the required choices above.";
                _problem.color = _theme.textMuted;
            }
            else
            {
                _problem.text = string.Empty;
            }

            _submit.interactable = _form.CanSubmit && !_busy;
        }
    }
}
