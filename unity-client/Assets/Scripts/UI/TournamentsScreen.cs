using System;
using System.Collections.Generic;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Tournaments: invitations to answer, the ones you're in or run, and the open ones to join. A Power Duel
    /// tournament asks for one of your saved decks when you join or accept, and lets you change it until it starts.
    /// </summary>
    public sealed class TournamentsScreen : ListScreen
    {
        private const float PollSeconds = 10f;

        private DeckChoiceOverlay _deckChoice;
        private bool _busy;

        protected override string Title => "Tournaments";

        public DeckChoiceOverlay DeckChoice => _deckChoice;

        protected override void BuildAbove(RectTransform column, UiTheme theme)
        {
            var create = UiFactory.Button(column, "New tournament", theme, () => Router.Show<NewTournamentScreen>());
            create.gameObject.name = "New tournament";
        }

        public override void OnShown(object args)
        {
            EnsureBuilt();
            if (_deckChoice == null)
            {
                _deckChoice = new DeckChoiceOverlay(transform, AppServices.Theme);
            }

            _deckChoice.Dismiss();
            _busy = false;
            SetStatus(string.Empty);

            AppServices.Tournaments.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
            PollWhileShown(PollSeconds, Refresh);
        }

        public override void OnHidden()
        {
            AppServices.Tournaments.Changed -= Rebuild;
            StopPolling();
            _deckChoice?.Dismiss();
        }

        public override bool HandleBack() => _deckChoice != null && _deckChoice.Dismiss();

        private async Task Refresh()
        {
            var result = await AppServices.Tournaments.RefreshListsAsync();
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
            var tournaments = AppServices.Tournaments;
            var me = AppServices.Auth.CurrentUser?.Id ?? 0;
            if (!tournaments.Loaded)
            {
                var loading = UiFactory.Label(List, "Loading the tournaments...", 26, theme.textMuted);
                UiFactory.Size(loading.gameObject, height: 60f);
                return;
            }

            var invitations = TournamentDisplay.Invitations(tournaments.Mine);
            if (invitations.Count > 0)
            {
                UiFactory.SectionTitle(List, theme, "Invitations");
                foreach (var tournament in invitations)
                {
                    AddRow(theme, tournament, tournament.Name, TournamentDisplay.MatchSummary(tournament),
                        ("Accept", true, () => Run(() => AcceptAsync(tournament))),
                        ("Decline", false, () => Run(() => DeclineAsync(tournament))));
                }
            }

            var yours = TournamentDisplay.Yours(tournaments.Mine);
            UiFactory.SectionTitle(List, theme, "Your tournaments");
            if (yours.Count == 0)
            {
                var none = UiFactory.Label(List, "You aren't in any tournaments.", 24, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 50f);
            }

            foreach (var tournament in yours)
            {
                var buttons = new List<(string, bool, UnityEngine.Events.UnityAction)>
                {
                    ("View", true, () => Router.Show<TournamentScreen>(tournament.Id)),
                };
                if (TournamentDisplay.CanEditDeck(tournament))
                {
                    buttons.Add(("Edit deck", false, () => Run(() => ChangeDeckAsync(tournament))));
                }

                if (TournamentDisplay.CanWithdraw(tournament, me))
                {
                    buttons.Add(("Withdraw", false, () => Run(() => WithdrawAsync(tournament))));
                }

                AddRow(theme, tournament, TournamentDisplay.ListLine(tournament, me), TournamentDisplay.MatchSummary(tournament), buttons.ToArray());
            }

            UiFactory.SectionTitle(List, theme, "Open to join");
            if (tournaments.Open.Count == 0)
            {
                var none = UiFactory.Label(List, "No open tournaments right now.", 24, theme.textMuted, TextAnchor.MiddleLeft);
                UiFactory.Size(none.gameObject, height: 50f);
            }

            foreach (var tournament in tournaments.Open)
            {
                AddRow(theme, tournament, TournamentDisplay.OpenLine(tournament), TournamentDisplay.OpenDetail(tournament),
                    ("Join", true, () => Run(() => JoinAsync(tournament))));
            }

            var cancelled = TournamentDisplay.Cancelled(tournaments.Mine);
            if (cancelled.Count > 0)
            {
                UiFactory.SectionTitle(List, theme, "Cancelled");
                foreach (var tournament in cancelled)
                {
                    AddRow(theme, tournament, tournament.Name, TournamentDisplay.MatchSummary(tournament),
                        ("View", false, () => Router.Show<TournamentScreen>(tournament.Id)));
                }
            }
        }

        private void AddRow(UiTheme theme, TournamentSummary tournament, string title, string subtitle,
            params (string Label, bool Primary, UnityEngine.Events.UnityAction Click)[] buttons)
        {
            var row = UiFactory.Create("Tournament " + tournament.Name, List);
            row.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = row.gameObject.AddComponent<HorizontalLayoutGroup>();
            layout.padding = new RectOffset(22, 18, 14, 14);
            layout.spacing = 14f;
            layout.childAlignment = TextAnchor.MiddleLeft;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;

            var text = UiFactory.TwoLineText(row, theme, title, subtitle);
            UiFactory.Flexible(text.gameObject, width: 1f);

            foreach (var (label, primary, click) in buttons)
            {
                var button = UiFactory.Button(row, label, theme, click, primary: primary);
                button.gameObject.name = label + " " + tournament.Name;
                button.GetComponentInChildren<Text>().fontSize = 26;
                var size = UiFactory.Size(button.gameObject, label.Length > 8 ? 190f : 160f);
                size.minWidth = size.preferredWidth;
                size.flexibleWidth = 0f;
            }
        }

        private async Task JoinAsync(TournamentSummary tournament) =>
            await SeatAsync(tournament, "Joining...", (t, deck) => AppServices.Tournaments.JoinAsync(t, deck));

        private async Task AcceptAsync(TournamentSummary tournament) =>
            await SeatAsync(tournament, "Accepting...", (t, deck) => AppServices.Tournaments.AcceptAsync(t, deck));

        private async Task ChangeDeckAsync(TournamentSummary tournament) =>
            await SeatAsync(tournament, "Changing the deck...", (t, deck) => AppServices.Tournaments.SubmitDeckAsync(t, deck.Value));

        /// <summary>Asks for a deck first when the tournament wants one, then makes the call and refreshes the lists.</summary>
        private async Task SeatAsync(TournamentSummary tournament, string working, Func<TournamentSummary, int?, Task<LobbyResult>> call)
        {
            if (_busy)
            {
                return;
            }

            int? deckId = null;
            if (TournamentFlow.NeedsDeck(tournament))
            {
                var deck = await _deckChoice.AskAsync("Choose your deck", "You play this deck all tournament long, and can change it until it starts.");
                if (deck == null || this == null)
                {
                    return;
                }

                deckId = deck.Id;
            }

            _busy = true;
            SetStatus(working);
            var result = await call(tournament, deckId);
            if (this == null)
            {
                return;
            }

            _busy = false;
            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
                return;
            }

            SetStatus(string.Empty);
            await Refresh();
        }

        private async Task DeclineAsync(TournamentSummary tournament) =>
            await SimpleAsync("Declining...", () => AppServices.Tournaments.DeclineAsync(tournament));

        private async Task WithdrawAsync(TournamentSummary tournament) =>
            await SimpleAsync("Withdrawing...", () => AppServices.Tournaments.WithdrawAsync(tournament));

        private async Task SimpleAsync(string working, Func<Task<LobbyResult>> call)
        {
            if (_busy)
            {
                return;
            }

            _busy = true;
            SetStatus(working);
            var result = await call();
            if (this == null)
            {
                return;
            }

            _busy = false;
            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
                return;
            }

            SetStatus(string.Empty);
            await Refresh();
        }
    }
}
