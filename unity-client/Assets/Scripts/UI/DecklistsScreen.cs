using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>What <see cref="DeckBuilderScreen"/> opens with: nothing (a new deck), a saved deck to change, or a copy of one.</summary>
    public sealed class DeckBuilderArgs
    {
        public int? DecklistId { get; set; }

        /// <summary>Make a new deck of it (a friend's, say) rather than changing it.</summary>
        public bool Copy { get; set; }
    }

    /// <summary>
    /// Your saved decks, and the ones friends share: open one in the deck builder, delete one of yours, or start a new
    /// one. (A friend's deck can't be changed, so it opens as a copy.)
    /// </summary>
    public sealed class DecklistsScreen : ListScreen
    {
        private ConfirmOverlay _confirm;
        private bool _busy;

        protected override string Title => "Decklists";

        protected override void BuildAbove(RectTransform column, UiTheme theme)
        {
            var actions = UiFactory.Row(column, "Actions", 16f, TextAnchor.MiddleCenter);
            var create = UiFactory.Button(actions.transform, "New deck", theme, () => Router.Show<DeckBuilderScreen>(new DeckBuilderArgs()));
            create.gameObject.name = "New deck";
            UiFactory.Flexible(create.gameObject, width: 1f);
        }

        public override void OnShown(object args)
        {
            EnsureBuilt();
            if (_confirm == null)
            {
                _confirm = new ConfirmOverlay(transform, AppServices.Theme);
            }

            _confirm.Dismiss();
            _busy = false;
            SetStatus(string.Empty);

            AppServices.Decklists.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
        }

        public override void OnHidden()
        {
            AppServices.Decklists.Changed -= Rebuild;
        }

        public override bool HandleBack() => _confirm != null && _confirm.Dismiss();

        private async Task Refresh()
        {
            var result = await AppServices.Decklists.RefreshAsync();
            if (this != null && !result.Ok)
            {
                SetStatus(result.Message, isError: true);
            }
        }

        private void Rebuild()
        {
            if (List == null)
            {
                return;
            }

            ClearList();
            var theme = AppServices.Theme;
            var decks = AppServices.Decklists;

            if (!decks.Loaded)
            {
                var loading = UiFactory.Label(List, "Loading your decks...", 26, theme.textMuted);
                UiFactory.Size(loading.gameObject, height: 60f);
                return;
            }

            UiFactory.SectionTitle(List, theme, "Your decks");
            if (decks.Own.Count == 0)
            {
                var none = UiFactory.Label(List, "You haven't saved any decks yet. Build one, or save a deck from a draft.", 26, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 70f);
            }

            foreach (var deck in decks.Own)
            {
                AddRow(theme, deck, mine: true);
            }

            foreach (var friend in decks.Friends.Where(f => f.Decklists.Count > 0))
            {
                UiFactory.SectionTitle(List, theme, $"{friend.FriendUsername}'s decks");
                foreach (var deck in friend.Decklists)
                {
                    AddRow(theme, deck, mine: false);
                }
            }
        }

        private void AddRow(UiTheme theme, DecklistSummary deck, bool mine)
        {
            var row = UiFactory.RowPanel(List, theme, 96f);
            row.gameObject.name = "Deck " + deck.Name;

            var text = UiFactory.TwoLineText(row.transform, theme, deck.Name, DecklistDisplay.Describe(deck));
            UiFactory.Flexible(text.gameObject, width: 1f);

            if (mine)
            {
                var edit = UiFactory.Button(row.transform, "Edit", theme, () => Router.Show<DeckBuilderScreen>(new DeckBuilderArgs { DecklistId = deck.Id }), primary: false);
                edit.gameObject.name = "Edit " + deck.Name;
                UiFactory.Size(edit.gameObject, 160f);

                var delete = UiFactory.Button(row.transform, "Delete", theme, () => Run(() => Delete(deck)), primary: false);
                delete.gameObject.name = "Delete " + deck.Name;
                UiFactory.Size(delete.gameObject, 180f);
            }
            else
            {
                var copy = UiFactory.Button(row.transform, "Copy", theme, () => Router.Show<DeckBuilderScreen>(new DeckBuilderArgs { DecklistId = deck.Id, Copy = true }), primary: false);
                copy.gameObject.name = "Copy " + deck.Name;
                UiFactory.Size(copy.gameObject, 160f);
            }
        }

        private async Task Delete(DecklistSummary deck)
        {
            if (_busy || !await _confirm.AskAsync($"Delete \"{deck.Name}\"? This cannot be undone.", "Delete", "Keep it"))
            {
                return;
            }

            _busy = true;
            var result = await AppServices.Decklists.DeleteAsync(deck.Id);
            if (this == null)
            {
                return;
            }

            _busy = false;
            SetStatus(result.Ok ? $"Deleted \"{deck.Name}\"." : result.Message, isError: !result.Ok);
        }
    }
}
