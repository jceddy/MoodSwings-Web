using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>What <see cref="DraftView"/> needs from the screen it lives on.</summary>
    public sealed class DraftActions
    {
        /// <summary>Show a card large, with where it is.</summary>
        public Action<BoardCard, string> Inspect;

        public Func<int, int, IEnumerable<int>, Task> PickQuickDraft;

        public Func<bool, Task> PickWinston;

        public Func<string, int, Task> PickGrid;

        public Func<int, bool, Task> PickRotisserie;

        public Func<IEnumerable<int>, Task> SubmitDeck;

        /// <summary>Custom duel: the saved deck chosen, or the deck as decklist text (sideboarding).</summary>
        public Func<int?, string, Task> SubmitDuelDeck;

        /// <summary>Asks a yes/no question; true for yes.</summary>
        public Func<string, string, string, Task<bool>> Confirm;
    }

    /// <summary>
    /// The draft and deck-building stages of a draft or sealed game, drawn where the table would be: cards to choose
    /// from -- a pack, a pile, a grid, a shared pool, or your own pool for the deck -- tapped to select, then a button
    /// to commit. What is selected lives in the view, and is kept across the board's refreshing until the stage
    /// changes, so a poll doesn't undo a half-made choice (or scroll a long pool back to the top).
    /// </summary>
    public sealed class DraftView
    {
        private const float CardWidth = 190f;
        private const float Pad = 6f;
        private const float SmallCardWidth = 112f;
        private const int Columns = 8;
        private const float LineButtonWidth = 112f;

        private sealed class Cell
        {
            public int Key;
            public Image Highlight;
            public CanvasGroup Group;
        }

        private readonly UiTheme _theme;
        private readonly DraftActions _actions;
        private readonly RectTransform _root;
        private readonly Text _title;
        private readonly Text _status;
        private readonly RectTransform _content;
        private readonly Text _problem;
        private readonly RectTransform _buttons;
        private readonly HashSet<int> _selected = new HashSet<int>();
        private readonly List<Cell> _cells = new List<Cell>();
        private readonly List<Image> _lineHighlights = new List<Image>();
        private string _selectionKey;
        private string _line;
        private int? _savedDeckId;
        private string _signature;
        private bool _busy;
        private bool _singlePick;
        private int _pickLimit;
        private Action _refresh;
        private GameState _state;

        public DraftView(Transform parent, UiTheme theme, DraftActions actions, float topInset)
        {
            _theme = theme;
            _actions = actions;

            _root = UiFactory.Create("Draft view", parent);
            _root.anchorMin = Vector2.zero;
            _root.anchorMax = Vector2.one;
            _root.offsetMin = new Vector2(24f, 16f);
            _root.offsetMax = new Vector2(-24f, -topInset);
            var layout = _root.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 10f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _title = UiFactory.Label(_root, string.Empty, 38, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(_title.gameObject, height: 52f);
            _status = UiFactory.Label(_root, string.Empty, 26, theme.textPrimary, TextAnchor.UpperLeft);

            var scroll = UiFactory.ScrollList(_root, out _content, spacing: 16f);
            UiFactory.Flexible(scroll.gameObject, height: 1f);

            _problem = UiFactory.Label(_root, string.Empty, 26, theme.danger, TextAnchor.MiddleLeft);
            UiFactory.Size(_problem.gameObject, height: 36f);
            _buttons = (RectTransform)UiFactory.Row(_root, "Buttons", 16f, TextAnchor.MiddleRight).transform;
            UiFactory.Size(_buttons.gameObject, height: UiFactory.ControlHeight);

            _root.gameObject.SetActive(false);
        }

        public bool IsShown => _root.gameObject.activeSelf;

        public string TitleText => _title.text;

        public string StatusText => _status.text;

        public string ProblemText => _problem.text;

        /// <summary>How many cards are selected right now (picks to keep, or cards in the deck).</summary>
        public int SelectedCount => _selected.Count;

        /// <summary>The Grid Draft row or column chosen ("row 2"), or null.</summary>
        public string SelectedLine => _line;

        public void Hide()
        {
            _root.gameObject.SetActive(false);
            _signature = null;
        }

        /// <summary>Draws the stage the game is in. Redrawing is skipped when nothing about it has changed.</summary>
        public void Render(GameState state)
        {
            _state = state;
            var block = DraftDisplay.BlockOf(state);
            var signature = block != null ? SignatureOf(state, block) : DuelSignature(state);
            if (IsShown && signature == _signature)
            {
                return;
            }

            _signature = signature;
            Build(state, block);
        }

        // The waiting room of a custom duel changes with who has submitted, the pool, and the decks there are to choose from.
        private static string DuelSignature(GameState state) =>
            "duel|" + string.Join(",", state.Players.Select(p => p.DeckSubmitted ? "1" : "0"))
            + "|" + string.Join(",", (state.PowerDuelSideboardPool ?? new List<BoardCard>()).Select(c => c.CardId))
            + "|" + AppServices.Decklists.Loaded + AppServices.Decklists.Own.Count + "|" + AppServices.Decklists.Friends.Sum(f => f.Decklists.Count);

        private static string SignatureOf(GameState state, DraftMatchState block) =>
            state.Game.DeckType + "|" + block.Status + "|"
            + (block.IsBuildingDeck ? JsonConvert.SerializeObject(block.DeckBuilding) : block.Drafting?.ToString(Formatting.None));

        private void Build(GameState state, DraftMatchState block)
        {
            _root.gameObject.SetActive(true);
            Clear(_content);
            Clear(_buttons);
            _cells.Clear();
            _lineHighlights.Clear();
            _problem.text = string.Empty;
            _problem.color = _theme.textMuted;
            _refresh = null;
            _singlePick = false;
            _pickLimit = int.MaxValue;

            if (block == null)
            {
                BuildDuelDeck(state);
            }
            else if (block.IsBuildingDeck)
            {
                BuildDeckBuilding(block.DeckBuilding);
            }
            else if (block.IsDrafting)
            {
                switch (DraftDisplay.Kind(state.Game.DeckType))
                {
                    case "quick_draft":
                        BuildQuick(block.AsQuickDrafting());
                        break;
                    case "winston_draft":
                        BuildWinston(block.AsWinstonDrafting());
                        break;
                    case "grid_draft":
                        BuildGrid(block.AsGridDrafting());
                        break;
                    case "rotisserie_draft":
                    case "tiered_rotisserie_draft":
                        BuildRotisserie(block.AsRotisserieDrafting());
                        break;
                }
            }

            ApplySelection();
        }

        // --- Quick Draft -------------------------------------------------------------------------

        private void BuildQuick(QuickDrafting drafting)
        {
            _title.text = DraftDisplay.QuickTitle(drafting);
            _status.text = DraftDisplay.QuickStatus(drafting);
            SelectionFor($"quick:{drafting.Round}:{drafting.Stage}", null);

            var picking = drafting.Status == "picking";
            _pickLimit = DraftDisplay.KeepPerStage;
            AddCards(drafting.Pack, picking, dimWhenUnselected: false, "In this pile");

            if (drafting.TeamDraftedCards != null)
            {
                AddTeamStrip(drafting.TeamDraftedCards);
            }
            else if (drafting.KeptSoFar.Count > 0)
            {
                AddStrip($"Kept so far ({drafting.KeptSoFar.Count})", DraftDisplay.SortPool(drafting.KeptSoFar), "You kept this");
            }

            if (!picking)
            {
                return;
            }

            var keep = AddButton("Keep these cards", 420f, () => RunAction(async () =>
            {
                var ids = drafting.Pack.Where((c, i) => _selected.Contains(i)).Select(c => c.CardId).ToList();
                await _actions.PickQuickDraft(drafting.Round, drafting.Stage, ids);
            }));
            _refresh = () =>
            {
                var ready = _selected.Count == DraftDisplay.KeepPerStage;
                _problem.text = ready ? string.Empty : $"Choose {DraftDisplay.KeepPerStage} ({_selected.Count} chosen).";
                keep.interactable = ready && !_busy;
            };
        }

        // --- Winston Draft -----------------------------------------------------------------------

        private void BuildWinston(WinstonDrafting winston)
        {
            _title.text = "Winston Draft";
            _status.text = winston.IsYourTurn
                ? $"Your turn -- looking at pile {winston.CurrentPileNumber}. Take it, or pass to see the next."
                : $"Waiting for {winston.CurrentTurnUsername ?? "the others"}'s turn.";

            var piles = UiFactory.Row(_content, "Piles", 16f, TextAnchor.UpperCenter);
            piles.childForceExpandWidth = true;
            for (var i = 0; i < winston.PileSizes.Count; i++)
            {
                var number = i + 1;
                var current = winston.IsYourTurn && number == winston.CurrentPileNumber;
                var panel = UiFactory.Panel(piles.transform, _theme);
                panel.gameObject.name = "Pile " + number;
                UiFactory.Flexible(panel.gameObject, width: 1f);
                panel.GetComponent<Image>().color = current ? Color.Lerp(_theme.panel, _theme.accent, 0.25f) : _theme.panel;
                var heading = UiFactory.Label(panel.transform, $"Pile {number} ({DraftDisplay.CardCount(winston.PileSizes[i])})", 28,
                    current ? _theme.accent : _theme.textPrimary, TextAnchor.MiddleLeft, FontStyle.Bold);
                UiFactory.Size(heading.gameObject, height: 40f);

                if (!current)
                {
                    continue;
                }

                var cards = UiFactory.Create("Cards", panel.transform);
                var grid = cards.gameObject.AddComponent<GridLayoutGroup>();
                grid.cellSize = new Vector2(SmallCardWidth + 8f, CardView.HeightFor(SmallCardWidth) + 8f);
                grid.spacing = new Vector2(8f, 8f);
                grid.childAlignment = TextAnchor.UpperLeft;
                grid.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
                grid.constraintCount = 3;
                foreach (var card in winston.CurrentPileCards)
                {
                    var shown = card;
                    var cell = UiFactory.Create("Cell", cards);
                    CardView.Create(cell, shown, SmallCardWidth, _theme, showValue: false, onClick: () => _actions.Inspect(shown, $"In pile {number}"));
                }

                var rows = Mathf.Max(1, Mathf.CeilToInt(winston.CurrentPileCards.Count / 3f));
                UiFactory.Size(cards.gameObject, height: rows * (grid.cellSize.y + 8f));
            }

            var deck = UiFactory.Label(_content, $"{DraftDisplay.CardCount(winston.RemainingDeckCount)} left in the deck.", 26, _theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Size(deck.gameObject, height: 36f);

            foreach (var other in winston.OtherPlayers)
            {
                var last = other.LastDrewFromDeck
                    ? ", last passing all 3 piles and drawing from the deck instead."
                    : other.LastTakePileNumber.HasValue ? $", last taking pile {other.LastTakePileNumber}." : ".";
                var line = UiFactory.Label(_content, $"{other.Username} has drafted {DraftDisplay.CardCount(other.DraftedCardCount)} so far{last}", 26, _theme.textPrimary, TextAnchor.MiddleLeft);
                UiFactory.Size(line.gameObject, height: 36f);
            }

            if (winston.TeamDraftedCards != null)
            {
                AddTeamStrip(winston.TeamDraftedCards);
            }
            else if (winston.DraftedSoFar.Count > 0)
            {
                AddStrip($"Drafted so far ({winston.DraftedSoFar.Count})", DraftDisplay.SortPool(winston.DraftedSoFar), "You drafted this");
            }

            if (!winston.IsYourTurn)
            {
                return;
            }

            var pass = AddButton(winston.CurrentPileNumber == 3 ? "Pass (draw from deck)" : "Pass", 420f, () => RunAction(async () =>
            {
                if (DraftDisplay.PassingGivesNothing(winston)
                    && !await _actions.Confirm("Passing now won't draw a card -- you'll get nothing this round. Are you sure?", "Pass", "Go back"))
                {
                    return;
                }

                await _actions.PickWinston(false);
            }), primary: false);
            var take = AddButton($"Take pile {winston.CurrentPileNumber}", 360f, () => RunAction(() => _actions.PickWinston(true)));
            _refresh = () =>
            {
                pass.interactable = !_busy;
                take.interactable = !_busy;
            };
        }

        // --- Grid Draft --------------------------------------------------------------------------

        private void BuildGrid(GridDrafting grid)
        {
            _title.text = DraftDisplay.Title(_state);
            _status.text = grid.IsYourTurn
                ? grid.PicksThisRound == 0 ? "Your turn -- choose a row or column to take." : "Your turn -- choose a row or column of what's left."
                : $"Waiting for {grid.CurrentTurnUsername ?? "the others"}'s turn.";
            SelectionFor($"grid:{grid.CurrentRound}:{grid.PicksThisRound}", null);

            var width = grid.GridSize > 3 ? 108f : 136f;
            var cellWidth = width + 2f * Pad;
            var cellHeight = CardView.HeightFor(width) + 2f * Pad;
            var table = UiFactory.Create("Grid", _content);
            var tableLayout = table.gameObject.AddComponent<VerticalLayoutGroup>();
            tableLayout.spacing = 6f;
            tableLayout.childAlignment = TextAnchor.UpperCenter;
            tableLayout.childControlWidth = true;
            tableLayout.childControlHeight = true;
            tableLayout.childForceExpandWidth = false;
            tableLayout.childForceExpandHeight = false;

            for (var row = 0; row < grid.GridSize; row++)
            {
                var rowLayout = UiFactory.Row(table, "Row " + (row + 1), 6f, TextAnchor.MiddleCenter);
                AddLineButton(grid, rowLayout.transform, "row", row, LineButtonWidth, cellHeight);
                for (var col = 0; col < grid.GridSize; col++)
                {
                    var index = row * grid.GridSize + col;
                    var card = index < grid.GridCards.Count ? grid.GridCards[index] : null;
                    var cell = UiFactory.Create(card != null ? "Cell " + card.Name : "Empty cell", rowLayout.transform);
                    UiFactory.Size(cell.gameObject, cellWidth, cellHeight);
                    var highlight = cell.gameObject.AddComponent<Image>();
                    highlight.color = Color.clear;
                    highlight.raycastTarget = false;
                    _lineHighlights.Add(highlight);
                    if (card != null)
                    {
                        PlaceCard(cell, card, width, selectable: false, "In the grid");
                    }
                }
            }

            var columns = UiFactory.Row(table, "Columns", 6f, TextAnchor.MiddleCenter);
            var spacer = UiFactory.Create("Spacer", columns.transform);
            UiFactory.Size(spacer.gameObject, LineButtonWidth, UiFactory.ControlHeight);
            for (var col = 0; col < grid.GridSize; col++)
            {
                AddLineButton(grid, columns.transform, "column", col, cellWidth, UiFactory.ControlHeight);
            }

            var deck = UiFactory.Label(_content, $"{DraftDisplay.CardCount(grid.RemainingDeckCount)} left in the pool.", 26, _theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Size(deck.gameObject, height: 36f);

            AddDraftedStrips(grid.TeamsDraftedSoFar, grid.OtherPlayersDraftedSoFar, grid.DraftedSoFar);

            if (!grid.IsYourTurn)
            {
                return;
            }

            var take = AddButton("Take", 460f, () => RunAction(async () =>
            {
                var parts = _line.Split(' ');
                await _actions.PickGrid(parts[0], int.Parse(parts[1]) - 1);
            }));
            _refresh = () =>
            {
                var chosen = !string.IsNullOrEmpty(_line);
                _problem.text = chosen ? string.Empty : "Choose a row or column.";
                take.interactable = chosen && !_busy;
                take.GetComponentInChildren<Text>().text = chosen
                    ? $"Take {_line} ({DraftDisplay.CardCount(LineCardCount(grid, _line))})"
                    : "Take";
                HighlightLine(grid);
            };
        }

        private static int LineCardCount(GridDrafting grid, string line)
        {
            var parts = line.Split(' ');
            return DraftDisplay.CardsInLine(grid, parts[0], int.Parse(parts[1]) - 1);
        }

        private void AddLineButton(GridDrafting grid, Transform parent, string axis, int index, float width, float height)
        {
            var left = DraftDisplay.CardsInLine(grid, axis, index);
            var label = $"{(axis == "row" ? "Row" : "Col")} {index + 1} ({left})";
            var name = $"{axis} {index + 1}";
            var button = UiFactory.Button(parent, label, _theme, () =>
            {
                if (_busy)
                {
                    return;
                }

                _line = _line == name ? null : name;
                ApplySelection();
            }, primary: false);
            button.gameObject.name = "Pick " + name;
            UiFactory.Size(button.gameObject, width, height);
            button.GetComponentInChildren<Text>().fontSize = 24;
            button.interactable = grid.IsYourTurn && left > 0;
        }

        private void HighlightLine(GridDrafting grid)
        {
            var cells = string.IsNullOrEmpty(_line)
                ? new List<int>()
                : DraftDisplay.LineCells(_line.Split(' ')[0], int.Parse(_line.Split(' ')[1]) - 1, grid.GridSize);
            for (var i = 0; i < _lineHighlights.Count; i++)
            {
                _lineHighlights[i].color = cells.Contains(i) ? _theme.accent : Color.clear;
            }
        }

        // --- Rotisserie and Tiered Rotisserie ----------------------------------------------------

        private void BuildRotisserie(RotisserieDrafting roti)
        {
            _title.text = DraftDisplay.Title(_state);
            _status.text = roti.IsYourTurn
                ? roti.IsTiered ? "Your turn -- choose a card from the current tier's pool." : "Your turn -- choose a card from the shared pool."
                : $"Waiting for {roti.CurrentTurnUsername ?? "the others"}'s turn.";
            if (roti.IsTiered)
            {
                _status.text = TierStepper(roti) + "\n" + _status.text;
            }

            var pool = DraftDisplay.SortPool(roti.PoolCards);
            SelectionFor($"roti:{pool.Count}:{(roti.IsTiered ? roti.TotalPicksMade : roti.PicksMade)}", null);
            _singlePick = true;
            AddCards(pool, roti.IsYourTurn, dimWhenUnselected: false, "In the pool");

            AddDraftedStrips(roti.TeamsDraftedSoFar, roti.OtherPlayersDraftedSoFar, roti.DraftedSoFar);

            if (!roti.IsYourTurn)
            {
                return;
            }

            var draft = AddButton("Draft", 520f, () => RunAction(async () =>
            {
                var card = pool[_selected.Single()];
                await _actions.PickRotisserie(card.CardId, roti.IsTiered);
            }));
            _refresh = () =>
            {
                var chosen = _selected.Count == 1;
                _problem.text = chosen ? string.Empty : "Choose a card.";
                draft.interactable = chosen && !_busy;
                draft.GetComponentInChildren<Text>().text = chosen ? "Draft " + pool[_selected.Single()].Name : "Draft";
            };
        }

        private static string TierStepper(RotisserieDrafting roti) =>
            "Tiers:  " + string.Join("  >  ", roti.Tiers.Select((tier, index) =>
            {
                var name = DraftDisplay.TierName(tier, index);
                switch (tier.Status)
                {
                    case "completed":
                        return $"{name} (done)";
                    case "current":
                        return $"[{name}, {tier.CutoffCount} picks each]";
                    default:
                        return $"{name} ({tier.CutoffCount} picks each)";
                }
            }));

        // --- a custom duel's deck -----------------------------------------------------------------

        private void BuildDuelDeck(GameState state)
        {
            var rules = state.Game.DuelDeckRules;
            _title.text = "Choose your deck";
            var rulesText = DuelDeckDisplay.RulesSummary(rules);
            var viewer = BoardDisplay.Viewer(state);

            if (viewer.DeckSubmitted)
            {
                var waiting = state.Players.Where(p => !p.DeckSubmitted).Select(p => p.Username).ToList();
                _status.text = $"You chose {(string.IsNullOrEmpty(viewer.CustomDeckName) ? "your deck" : viewer.CustomDeckName)}."
                    + (waiting.Count > 0 ? $" Waiting for {string.Join(", ", waiting)}'s deck." : " Every deck is in -- starting the game...");
                return;
            }

            var pool = state.PowerDuelSideboardPool;
            if (pool != null && pool.Count > 0)
            {
                BuildSideboarding(state, rules, rulesText, DraftDisplay.SortPool(pool));
                return;
            }

            _status.text = rulesText + "\nChoose one of your saved decks (or a friend's shared deck) to play.";
            var decks = AppServices.Decklists;
            if (!decks.Loaded)
            {
                AddLine("Loading your decks...", _theme.textMuted);
                return;
            }

            var all = decks.Own.Select(d => (deck: d, owner: (string)null))
                .Concat(decks.Friends.SelectMany(f => f.Decklists.Select(d => (deck: d, owner: f.FriendUsername))))
                .ToList();
            if (all.Count == 0)
            {
                AddLine("You have no saved decks yet. Build one under Decklists on the home screen, then come back.", _theme.textMuted);
                return;
            }

            SelectionFor("duel-deck", null);
            var panel = UiFactory.Panel(_content, _theme);
            var group = panel.gameObject.AddComponent<ToggleGroup>();
            group.allowSwitchOff = true;
            foreach (var (deck, owner) in all)
            {
                var id = deck.Id;
                var label = $"{deck.Name}  ({deck.CardCount} cards{(owner != null ? ", " + owner + "'s" : string.Empty)})";
                var toggle = UiFactory.Toggle(panel.transform, label, _theme, _savedDeckId == id);
                toggle.gameObject.name = "Deck " + deck.Name;
                toggle.group = group;
                toggle.onValueChanged.AddListener(on =>
                {
                    if (on)
                    {
                        _savedDeckId = id;
                        ApplySelection();
                    }
                });
            }

            var submit = AddButton("Submit deck", 360f, () => RunAction(() => _actions.SubmitDuelDeck(_savedDeckId, null)));
            _refresh = () =>
            {
                _problem.text = _savedDeckId.HasValue ? string.Empty : "Choose a deck.";
                submit.interactable = _savedDeckId.HasValue && !_busy;
            };
        }

        // Games 2 and 3 of a sideboarded Power Duel: the new deck is built from the last one plus its sideboard.
        private void BuildSideboarding(GameState state, DuelDeckRules rules, string rulesText, List<BoardCard> pool)
        {
            _status.text = "Sideboarding is on for this match. " + rulesText + "\nTap a card to put it in or take it out of your new deck.";
            var previous = state.PowerDuelPreviousDeckCardIds;
            SelectionFor("sideboard:" + string.Join(",", pool.Select((c, i) => c.CardId + ":" + i)), () =>
                previous != null && previous.Count > 0 ? IndicesFor(pool, previous) : Enumerable.Range(0, pool.Count));

            AddCards(pool, true, dimWhenUnselected: true, "In your deck or sideboard");
            AddButton("Select all", 280f, () => SetSelection(Enumerable.Range(0, pool.Count)), primary: false);
            AddButton("Clear", 280f, () => SetSelection(Enumerable.Empty<int>()), primary: false);
            if (previous != null && previous.Count > 0)
            {
                AddButton("Last game's deck", 280f, () => SetSelection(IndicesFor(pool, previous)), primary: false);
            }

            var submit = AddButton("Submit deck", 360f, () => RunAction(() =>
                _actions.SubmitDuelDeck(null, DuelDeckDisplay.DeckText(_selected.OrderBy(i => i).Select(i => pool[i])))));
            _refresh = () =>
            {
                var chosen = _selected.Select(i => pool[i]).ToList();
                var problem = DuelDeckDisplay.Problem(rules, chosen);
                _problem.text = problem ?? $"{chosen.Count} cards in your deck.";
                _problem.color = problem != null ? _theme.danger : _theme.textMuted;
                submit.interactable = problem == null && !_busy;
            };
        }

        private void AddLine(string text, Color color)
        {
            var label = UiFactory.Label(_content, text, 28, color, TextAnchor.MiddleLeft);
            UiFactory.Size(label.gameObject, height: 60f);
        }

        // --- deck building -----------------------------------------------------------------------

        private void BuildDeckBuilding(DraftDeckBuilding building)
        {
            _title.text = $"Build your deck ({DraftDisplay.DeckSizeText(building)})";

            if (building.YouSubmitted)
            {
                _status.text = DraftDisplay.OthersHaveSubmitted(building)
                    ? "Every deck is in -- starting the game..."
                    : "Your deck is in. Waiting for " + string.Join(", ", building.OtherPlayers.Where(p => !p.Submitted).Select(p => p.Username)) + "'s deck.";
                return;
            }

            var pool = DraftDisplay.SortPool(building.DraftedCards);
            var poolLabel = building.TeamDraftedCards != null
                ? $"your team's {pool.Count} available drafted cards"
                : $"your {pool.Count} cards";
            var caps = building.RarityCaps != null && building.RarityCaps.Count > 0
                ? " At most " + string.Join(" and ", building.RarityCaps.Select(c => $"{c.Value} {c.Key}")) + "."
                : string.Empty;
            _status.text = $"Choose {DraftDisplay.DeckSizeText(building)} from {poolLabel}. Tap a card to put it in or take it out of your deck." + caps;

            // Start from the deck you last played (game 2 or 3), or with every card in.
            SelectionFor("deck:" + string.Join(",", pool.Select((c, i) => c.CardId + ":" + i)), () =>
            {
                var start = building.DeckCardIds ?? building.PreviousDeckCardIds;
                return start != null ? IndicesFor(pool, start) : Enumerable.Range(0, pool.Count);
            });

            AddCards(pool, true, dimWhenUnselected: true, "In your pool");

            AddButton("Select all", 280f, () => SetSelection(Enumerable.Range(0, pool.Count)), primary: false);
            AddButton("Clear", 280f, () => SetSelection(Enumerable.Empty<int>()), primary: false);
            if (building.PreviousDeckCardIds != null)
            {
                AddButton("Last game's deck", 280f, () => SetSelection(IndicesFor(pool, building.PreviousDeckCardIds)), primary: false);
            }

            var submit = AddButton("Submit deck", 360f, () => RunAction(() =>
                _actions.SubmitDeck(_selected.OrderBy(i => i).Select(i => pool[i].CardId).ToList())));
            _refresh = () =>
            {
                var chosen = _selected.Select(i => pool[i]).ToList();
                var problem = DraftDisplay.DeckProblem(building, chosen);
                _problem.text = problem ?? $"{chosen.Count} cards in your deck.";
                _problem.color = problem != null ? _theme.danger : _theme.textMuted;
                submit.interactable = problem == null && !_busy;
            };
        }

        // Maps card ids (one per copy) back to positions in the pool, so duplicates are matched one for one.
        private static IEnumerable<int> IndicesFor(IReadOnlyList<BoardCard> pool, IEnumerable<int> cardIds)
        {
            var used = new HashSet<int>();
            foreach (var id in cardIds)
            {
                for (var i = 0; i < pool.Count; i++)
                {
                    if (pool[i].CardId == id && used.Add(i))
                    {
                        yield return i;
                        break;
                    }
                }
            }
        }

        private void SetSelection(IEnumerable<int> indices)
        {
            _selected.Clear();
            foreach (var index in indices)
            {
                _selected.Add(index);
            }

            ApplySelection();
        }

        // Selections belong to one stage; a new stage starts empty (or from a default).
        private void SelectionFor(string key, Func<IEnumerable<int>> initial)
        {
            if (_selectionKey == key)
            {
                return;
            }

            _selectionKey = key;
            _selected.Clear();
            _line = null;
            _savedDeckId = null;
            if (initial != null)
            {
                foreach (var index in initial())
                {
                    _selected.Add(index);
                }
            }
        }

        // --- cells and strips --------------------------------------------------------------------

        private Button AddButton(string label, float width, Action onClick, bool primary = true)
        {
            var button = UiFactory.Button(_buttons, label, _theme, () => onClick(), primary);
            button.gameObject.name = label;
            UiFactory.Size(button.gameObject, width);
            return button;
        }

        /// <summary>A grid of tappable cards; each cell's key is its position in the list.</summary>
        private void AddCards(IList<BoardCard> cards, bool selectable, bool dimWhenUnselected, string where)
        {
            var area = UiFactory.Create("Cards", _content);
            var grid = area.gameObject.AddComponent<GridLayoutGroup>();
            grid.cellSize = new Vector2(CardWidth + 2f * Pad, CardView.HeightFor(CardWidth) + 2f * Pad);
            grid.spacing = new Vector2(8f, 8f);
            grid.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
            grid.constraintCount = Columns;
            grid.childAlignment = TextAnchor.UpperCenter;

            for (var i = 0; i < cards.Count; i++)
            {
                var key = i;
                var card = cards[i];
                var cell = UiFactory.Create("Cell " + card.Name, area);
                var highlight = cell.gameObject.AddComponent<Image>();
                highlight.color = Color.clear;
                highlight.raycastTarget = false;
                var view = PlaceCard(cell, card, CardWidth, selectable, where, () => Toggle(key));
                var group = dimWhenUnselected ? view.gameObject.AddComponent<CanvasGroup>() : null;
                _cells.Add(new Cell { Key = key, Highlight = highlight, Group = group });
            }
        }

        // A card centred in its cell, with the small "i" that shows it large without choosing it.
        private RectTransform PlaceCard(RectTransform cell, BoardCard card, float width, bool selectable, string where, UnityEngine.Events.UnityAction onClick = null)
        {
            var view = CardView.Create(cell, card, width, _theme, showValue: false, onClick: selectable ? onClick : null);
            view.anchorMin = view.anchorMax = view.pivot = new Vector2(0.5f, 0.5f);
            view.anchoredPosition = Vector2.zero;

            var info = UiFactory.Button(cell, "i", _theme, () => _actions.Inspect(card, where), primary: false);
            info.gameObject.name = "Inspect " + card.Name;
            var rect = (RectTransform)info.transform;
            rect.anchorMin = rect.anchorMax = rect.pivot = new Vector2(0f, 1f);
            rect.sizeDelta = new Vector2(46f, 46f);
            rect.anchoredPosition = new Vector2(Pad + 4f, -Pad - 4f);
            var layoutElement = info.GetComponent<LayoutElement>();
            if (layoutElement != null)
            {
                layoutElement.ignoreLayout = true;
            }

            return view;
        }

        private void AddStrip(string heading, IEnumerable<BoardCard> cards, string where)
        {
            var label = UiFactory.Label(_content, heading, 28, _theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(label.gameObject, height: 44f);
            var strip = UiFactory.Create("Strip", _content);
            var grid = strip.gameObject.AddComponent<GridLayoutGroup>();
            grid.cellSize = new Vector2(SmallCardWidth, CardView.HeightFor(SmallCardWidth));
            grid.spacing = new Vector2(8f, 8f);
            grid.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
            grid.constraintCount = 14;
            grid.childAlignment = TextAnchor.UpperLeft;
            foreach (var card in cards)
            {
                var shown = card;
                CardView.Create(strip, shown, SmallCardWidth, _theme, showValue: false, onClick: () => _actions.Inspect(shown, where));
            }
        }

        private void AddTeamStrip(TeamDraftedCards team)
        {
            AddStrip($"You and {team.TeammateUsername} have drafted ({team.Cards.Count})", DraftDisplay.SortPool(team.Cards), "Drafted by your team");
        }

        // Grid and Rotisserie are open information: what everyone else (or each team) has taken is shown too.
        private void AddDraftedStrips(List<TeamDrafted> teams, List<PlayerDrafted> others, List<BoardCard> yours)
        {
            if (teams != null && teams.Count > 0)
            {
                foreach (var team in teams)
                {
                    var name = (team.IsYourTeam ? "Your team" : "Opposing team") + $"'s drafted so far ({string.Join(" & ", team.MemberUsernames)})";
                    AddStrip(name, DraftDisplay.SortPool(team.DraftedSoFar), team.IsYourTeam ? "Drafted by your team" : "Drafted by the other team");
                }

                return;
            }

            AddStrip($"Drafted so far ({yours.Count})", DraftDisplay.SortPool(yours), "You drafted this");
            foreach (var other in others)
            {
                AddStrip($"{other.Username}'s drafted so far ({other.DraftedSoFar.Count})", DraftDisplay.SortPool(other.DraftedSoFar), $"Drafted by {other.Username}");
            }
        }

        // --- selecting ---------------------------------------------------------------------------

        private void Toggle(int key)
        {
            if (_busy)
            {
                return;
            }

            if (!_selected.Remove(key))
            {
                if (_singlePick)
                {
                    _selected.Clear();
                }
                else if (_selected.Count >= _pickLimit)
                {
                    // A pick holds exactly so many; choosing another swaps nothing in, so refuse.
                    return;
                }

                _selected.Add(key);
            }

            ApplySelection();
        }

        // Updates what shows the selection in place, without redrawing (which would scroll a long pool back to the top).
        private void ApplySelection()
        {
            foreach (var cell in _cells)
            {
                var on = _selected.Contains(cell.Key);
                cell.Highlight.color = on ? _theme.accent : Color.clear;
                if (cell.Group != null)
                {
                    cell.Group.alpha = on ? 1f : 0.45f;
                }
            }

            _refresh?.Invoke();
        }

        private async void RunAction(Func<Task> action)
        {
            if (_busy)
            {
                return;
            }

            _busy = true;
            ApplySelection();
            try
            {
                await action();
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
            finally
            {
                _busy = false;
                if (_root != null && _root.gameObject.activeSelf)
                {
                    ApplySelection();
                }
            }
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
