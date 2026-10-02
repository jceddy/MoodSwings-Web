using System;
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

            // The board polls every few seconds; most polls find nothing new, and
            // redrawing identical content would only flicker.
            var snapshot = JsonConvert.SerializeObject(State);
            if (snapshot != _lastSnapshot)
            {
                _lastSnapshot = snapshot;
                Changed?.Invoke();
            }

            return new BoardRefreshResult { Ok = true };
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

        public async Task<BoardActionResult> SendChatAsync(string text)
        {
            if (IsSpectating)
            {
                return new BoardActionResult { Message = "Spectators can't chat." };
            }

            if (string.IsNullOrWhiteSpace(text))
            {
                return new BoardActionResult { Message = "Type a message first." };
            }

            var result = await _api.SendChatAsync(GameId, text.Trim());
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
