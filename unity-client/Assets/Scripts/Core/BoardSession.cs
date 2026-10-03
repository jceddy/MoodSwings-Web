using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;

namespace MoodSwings.Core
{
    public sealed class BoardRefreshResult
    {
        public bool Ok { get; set; }

        public string Message { get; set; }
    }

    /// <summary>What came of an action: whether it worked, the server's reason if not, and a note worth showing if it ended a round or the game.</summary>
    public sealed class BoardActionResult
    {
        public bool Ok { get; set; }

        /// <summary>Why it failed, in words for the player. Null when Ok.</summary>
        public string Message { get; set; }

        /// <summary>"Round scored..." or "Game complete!" after an action that caused one; otherwise null.</summary>
        public string Notice { get; set; }
    }

    /// <summary>
    /// One open game on the board: which game, whether you're playing or
    /// watching, and the last state fetched. The board polls
    /// <see cref="RefreshAsync"/> while it's showing; that isn't just a
    /// refresh, because the server advances bot turns and enforces timers
    /// as part of answering it. A failed refresh keeps the previous state, so
    /// a dropped connection leaves the last known board up instead of a blank.
    /// Playing the game goes through here too: every action reports back and then
    /// re-reads the board, so what's shown is always what the server now says.
    /// </summary>
    public sealed class BoardSession
    {
        private readonly ApiClient _api;
        private readonly string _spectateCode;
        private string _lastSnapshot;
        private bool _starting;

        private BoardSession(ApiClient api, int gameId, bool spectating, string spectateCode)
        {
            _api = api;
            GameId = gameId;
            IsSpectating = spectating;
            _spectateCode = spectateCode;
        }

        public static BoardSession ForPlayer(ApiClient api, int gameId) => new BoardSession(api, gameId, false, null);

        /// <param name="spectateCode">Needed for a game you can't see through a friendship; null for a friend's game.</param>
        public static BoardSession ForSpectator(ApiClient api, int gameId, string spectateCode = null) =>
            new BoardSession(api, gameId, true, spectateCode);

        public int GameId { get; }

        /// <summary>Watching rather than playing: no hand, no actions.</summary>
        public bool IsSpectating { get; }

        /// <summary>Null until the first successful refresh.</summary>
        public GameState State { get; private set; }

        /// <summary>An action is in flight; further ones are refused until it finishes, so a slow answer can't be sent twice.</summary>
        public bool Busy { get; private set; }

        /// <summary>Raised when a refresh brought a state different from the last one.</summary>
        public event Action Changed;

        public async Task<BoardRefreshResult> RefreshAsync(CancellationToken cancellationToken = default)
        {
            var result = IsSpectating
                ? await _api.GetSpectatorStateAsync(GameId, _spectateCode, cancellationToken)
                : await _api.GetGameStateAsync(GameId, cancellationToken);

            if (!result.Ok)
            {
                return new BoardRefreshResult { Ok = false, Message = result.UserMessage("Couldn't load the game.") };
            }

            State = result.Value;
            await AttachChaosOfferAsync(cancellationToken);

            // The board polls every few seconds; most polls find nothing new, and
            // redrawing identical content would only flicker.
            var snapshot = JsonConvert.SerializeObject(State) + "|" + JsonConvert.SerializeObject(State.ChaosOffer);
            if (snapshot != _lastSnapshot)
            {
                _lastSnapshot = snapshot;
                Changed?.Invoke();
            }

            return new BoardRefreshResult { Ok = true };
        }

        private ChaosOfferInfo _chaosOffer;

        // A Chaos Draft round opens with an effect to choose, which the server offers through its own endpoint
        // (and creates on the first ask). Asking is part of every refresh, so the board always knows whether play is held up.
        // A failed ask keeps the last answer rather than unblocking or blocking on a guess.
        private async Task AttachChaosOfferAsync(CancellationToken cancellationToken)
        {
            var eligible = !IsSpectating
                && State.Game.DeckType == "chaos_draft"
                && State.Game.Status == "in_progress"
                && BoardDisplay.Viewer(State) != null;
            if (!eligible)
            {
                _chaosOffer = null;
            }
            else
            {
                var offer = await _api.GetChaosOfferAsync(GameId, cancellationToken);
                if (offer.Ok)
                {
                    _chaosOffer = offer.Value;
                }
            }

            State.ChaosOffer = _chaosOffer;
        }

        // --- acting ------------------------------------------------------------------------------

        /// <summary>Plays <paramref name="card"/> from your hand with the answers to its choices.</summary>
        public Task<BoardActionResult> PlayAsync(BoardCard card, JObject choices) =>
            ActAsync("Couldn't play that card.", () => _api.PlayCardAsync(GameId, card.CardId, choices));

        public Task<BoardActionResult> PassAsync() =>
            ActAsync("Couldn't pass.", () => _api.PassTurnAsync(GameId));

        /// <summary>Acknowledges the start of your turn.</summary>
        public Task<BoardActionResult> AdvanceTurnAsync() =>
            ActAsync("Couldn't advance the turn.", () => _api.AdvanceTurnAsync(GameId));

        public Task<BoardActionResult> ResignAsync() =>
            ActAsync("Couldn't resign.", () => _api.ResignGameAsync(GameId));

        /// <summary>Answers the pending decision a card effect is waiting on you for.</summary>
        public Task<BoardActionResult> RespondAsync(JObject choices) =>
            ActAsync("Couldn't send your response.", () => _api.RespondToDecisionAsync(GameId, choices));

        /// <summary>Game 2 or 3 of a match: the previous loser says whether they go first.</summary>
        public Task<BoardActionResult> ChoosePlayFirstAsync(bool playFirst) =>
            ActAsync("Couldn't record that choice.", () => _api.ChoosePlayFirstAsync(GameId, playFirst));

        /// <summary>Confirms you're ready, in a synchronous game's ready check.</summary>
        public Task<BoardActionResult> MarkReadyAsync() =>
            ActAsync("Couldn't confirm you're ready.", () => _api.MarkReadyAsync(GameId));

        /// <summary>Custom duel: play this saved deck, or the deck written out as <paramref name="decklistText"/> (sideboarding).</summary>
        public Task<BoardActionResult> SubmitDuelDeckAsync(int? savedDecklistId, string decklistText = null) =>
            ActAsync("Couldn't submit that deck.", () => _api.SubmitDuelDeckAsync(GameId, savedDecklistId, decklistText));

        /// <summary>Chaos Draft: attach the chosen effect to this card (a team proposes it for its partner to confirm).</summary>
        public Task<BoardActionResult> ChooseChaosEffectAsync(int effectId, int cardId, bool team) =>
            ActAsync("Couldn't attach that effect.", () => _api.ChooseChaosEffectAsync(GameId, effectId, cardId, team));

        /// <summary>Chaos Draft, Open Team Play: agree with your partner's proposal (true) or turn it down.</summary>
        public Task<BoardActionResult> ConfirmChaosEffectAsync(bool approve) =>
            ActAsync(approve ? "Couldn't confirm that." : "Couldn't send that back.", () => _api.ConfirmChaosEffectAsync(GameId, approve));

        /// <summary>Chaos Draft: apply a repeating effect's loop this many times at once.</summary>
        public Task<BoardActionResult> ApplyChaosLoopShortcutAsync(int count) =>
            ActAsync("Couldn't apply that.", () => _api.ApplyChaosLoopShortcutAsync(GameId, count));

        /// <summary>Quick Draft: keep these cards from the pack in front of you.</summary>
        public Task<BoardActionResult> PickQuickDraftAsync(int round, int stage, IEnumerable<int> cardIds) =>
            ActAsync("Couldn't submit that pick.", () => _api.PickQuickDraftAsync(GameId, round, stage, cardIds));

        /// <summary>Winston Draft: take the pile you're looking at (true), or pass it on (false).</summary>
        public Task<BoardActionResult> PickWinstonDraftAsync(bool take) =>
            ActAsync("Couldn't submit that pick.", () => _api.PickWinstonDraftAsync(GameId, take ? "take" : "pass"));

        /// <summary>Grid Draft: take a whole "row" or "column".</summary>
        public Task<BoardActionResult> PickGridDraftAsync(string axis, int index) =>
            ActAsync("Couldn't submit that pick.", () => _api.PickGridDraftAsync(GameId, axis, index));

        /// <summary>Rotisserie Draft (plain or tiered): take this card from the shared pool.</summary>
        public Task<BoardActionResult> PickRotisserieDraftAsync(int cardId, bool tiered) =>
            ActAsync("Couldn't submit that pick.", () => _api.PickRotisserieDraftAsync(GameId, cardId, tiered));

        /// <summary>Submit the deck you've chosen from your drafted or sealed pool.</summary>
        public Task<BoardActionResult> SubmitDraftDeckAsync(IEnumerable<int> cardIds) =>
            ActAsync("Couldn't submit that deck.", () => _api.SubmitDraftDeckAsync(GameId, cardIds));

        /// <summary>Tells the server you're about to read the hint (it affects an achievement), before it's shown. Failures are ignored.</summary>
        public async Task MarkHintViewedAsync()
        {
            if (!IsSpectating)
            {
                await _api.MarkPuzzleHintViewedAsync(GameId);
            }
        }

        /// <summary>The team's proposal for who acts (turn order or the shared draw).</summary>
        public Task<BoardActionResult> ProposeTeamDecisionAsync(int gamePlayerId) =>
            ActAsync("Couldn't send that proposal.", () => _api.ProposeTeamDecisionAsync(GameId, gamePlayerId));

        /// <summary>Agree (true) or disagree (false) with your partner's proposal.</summary>
        public Task<BoardActionResult> ConfirmTeamDecisionAsync(bool approve) =>
            ActAsync(approve ? "Couldn't confirm that." : "Couldn't send that back.", () => _api.ConfirmTeamDecisionAsync(GameId, approve));

        /// <summary>Closed Team Play: the two hand cards passed to your partner.</summary>
        public Task<BoardActionResult> SubmitInitialPassAsync(IEnumerable<int> cardIds) =>
            ActAsync("Couldn't pass those cards.", () => _api.SubmitInitialPassAsync(GameId, cardIds));

        /// <param name="channel">"table", or "team" to reach only your partner (Open Team Play).</param>
        public async Task<BoardActionResult> SendChatAsync(string text, string channel = "table")
        {
            if (IsSpectating)
            {
                return new BoardActionResult { Message = "Spectators can't chat." };
            }

            if (string.IsNullOrWhiteSpace(text))
            {
                return new BoardActionResult { Message = "Type a message first." };
            }

            var result = await _api.SendChatAsync(GameId, text.Trim(), channel);
            if (!result.Ok)
            {
                return new BoardActionResult { Message = result.UserMessage("Couldn't send that message.") };
            }

            // The message arrives with the board's own chat list.
            await RefreshAsync();
            return new BoardActionResult { Ok = true };
        }

        /// <summary>
        /// Starts a game that's waiting to be dealt, once it can be: at once for a plain
        /// game, after the ready check for a synchronous one. The server accepts this
        /// from any seat, so a loser of the race just gets an error, which is ignored
        /// since the next refresh shows what happened. True if it started the game.
        /// </summary>
        public async Task<bool> StartWhenReadyAsync()
        {
            if (IsSpectating || _starting || State == null || !BoardDisplay.ReadyToStart(State))
            {
                return false;
            }

            _starting = true;
            try
            {
                var result = await _api.StartGameAsync(GameId);
                if (!result.Ok)
                {
                    return false;
                }

                await RefreshAsync();
                return true;
            }
            finally
            {
                _starting = false;
            }
        }

        private async Task<BoardActionResult> ActAsync(string failure, Func<Task<ApiResult<GameActionResponse>>> send)
        {
            if (IsSpectating)
            {
                return new BoardActionResult { Message = "You're watching this game." };
            }

            if (Busy)
            {
                return new BoardActionResult { Message = "One moment - still working on your last move." };
            }

            Busy = true;
            try
            {
                var result = await send();

                // Even a refusal can mean the board moved on without us ("not your turn" after a
                // duplicate tap), so read it again either way.
                await RefreshAsync();

                return result.Ok
                    ? new BoardActionResult { Ok = true, Notice = BoardDisplay.OutcomeNotice(result.Value) }
                    : new BoardActionResult { Message = result.UserMessage(failure) };
            }
            finally
            {
                Busy = false;
            }
        }
    }
}
