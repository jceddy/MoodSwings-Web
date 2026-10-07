using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    public sealed class DecklistResult
    {
        public bool Ok { get; set; }

        public string Message { get; set; }

        public DecklistDetail Decklist { get; set; }

        public int DecklistId { get; set; }
    }

    /// <summary>Your saved decklists, your friends' shared ones, and the card catalog the builder works from. UI-free.</summary>
    public sealed class DecklistFlow
    {
        private readonly ApiClient _api;

        public DecklistFlow(ApiClient api)
        {
            _api = api;
        }

        public IReadOnlyList<DecklistSummary> Own { get; private set; } = new List<DecklistSummary>();

        public IReadOnlyList<FriendDecklists> Friends { get; private set; } = new List<FriendDecklists>();

        /// <summary>Every printed card; fetched once, since it doesn't change while the app is open. Empty until loaded.</summary>
        public IReadOnlyList<BoardCard> Catalog { get; private set; } = new List<BoardCard>();

        /// <summary>True once the first read of the list has finished, so a screen can tell "none yet" from "not loaded".</summary>
        public bool Loaded { get; private set; }

        public event Action Changed;

        public async Task<DecklistResult> RefreshAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.ListDecklistsAsync(cancellationToken);
            if (!result.Ok)
            {
                return new DecklistResult { Message = result.UserMessage("Couldn't load your decklists.") };
            }

            Own = result.Value.Own;
            Friends = result.Value.Friends;
            Loaded = true;
            Changed?.Invoke();
            return new DecklistResult { Ok = true };
        }

        public async Task<DecklistResult> LoadCatalogAsync(CancellationToken cancellationToken = default)
        {
            if (Catalog.Count > 0)
            {
                return new DecklistResult { Ok = true };
            }

            var result = await _api.GetCardCatalogAsync(cancellationToken);
            if (!result.Ok)
            {
                return new DecklistResult { Message = result.UserMessage("Couldn't load the card list.") };
            }

            Catalog = result.Value.Cards;
            return new DecklistResult { Ok = true };
        }

        public async Task<DecklistResult> ViewAsync(int id, CancellationToken cancellationToken = default)
        {
            var result = await _api.ViewDecklistAsync(id, cancellationToken);
            return result.Ok
                ? new DecklistResult { Ok = true, Decklist = result.Value.Decklist }
                : new DecklistResult { Message = result.UserMessage("Couldn't open that deck.") };
        }

        /// <summary>Saves the deck as a new one, or over the saved one it came from.</summary>
        public async Task<DecklistResult> SaveAsync(DeckEditor deck, CancellationToken cancellationToken = default)
        {
            var problem = deck.Problem();
            if (problem != null)
            {
                return new DecklistResult { Message = problem };
            }

            int id;
            if (deck.DecklistId.HasValue)
            {
                var update = await _api.UpdateDecklistAsync(
                    deck.DecklistId.Value, deck.Name.Trim(), deck.Visibility, deck.ToCardIds(), deck.SideboardCardIds(), cancellationToken);
                if (!update.Ok)
                {
                    return new DecklistResult { Message = update.UserMessage("Couldn't save the deck.") };
                }

                id = deck.DecklistId.Value;
            }
            else
            {
                var create = await _api.CreateDecklistAsync(
                    deck.Name.Trim(), deck.Visibility, deck.ToCardIds(), deck.SideboardCardIds(), cancellationToken);
                if (!create.Ok)
                {
                    return new DecklistResult { Message = create.UserMessage("Couldn't save the deck.") };
                }

                id = create.Value.DecklistId;
            }

            deck.DecklistId = id;
            deck.MarkSaved();
            await RefreshAsync(cancellationToken);
            return new DecklistResult { Ok = true, DecklistId = id };
        }

        public async Task<DecklistResult> DeleteAsync(int id, CancellationToken cancellationToken = default)
        {
            var result = await _api.DeleteDecklistAsync(id, cancellationToken);
            if (!result.Ok)
            {
                return new DecklistResult { Message = result.UserMessage("Couldn't delete that deck.") };
            }

            await RefreshAsync(cancellationToken);
            return new DecklistResult { Ok = true };
        }

        public void Clear()
        {
            Own = new List<DecklistSummary>();
            Friends = new List<FriendDecklists>();
            Loaded = false;
            Changed?.Invoke();
        }
    }

    /// <summary>How decks read in lists.</summary>
    public static class DecklistDisplay
    {
        /// <summary>"22 cards  -  shared with friends" (and the sideboard, when it has one).</summary>
        public static string Describe(DecklistSummary deck)
        {
            var cards = deck.CardCount == 1 ? "1 card" : deck.CardCount + " cards";
            var sideboard = deck.SideboardCardCount > 0 ? $" + {deck.SideboardCardCount} sideboard" : string.Empty;
            return $"{cards}{sideboard}  -  {(deck.Visibility == DeckEditor.Friends ? "shared with friends" : "private")}";
        }
    }
}
