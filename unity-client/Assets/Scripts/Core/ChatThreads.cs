using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// What has been read of a game's chat. A draft match shares one chat between all its games (the same messages, with the same
    /// ids, come with each game of it), so what has been read belongs to that chat, not to whichever game is open: the next game
    /// of the match must not show everything as unread again. Remembered as the newest message id that has been read. UI-free.
    /// </summary>
    public static class ChatThreads
    {
        /// <summary>Which chat a game's messages belong to: its draft match's, or its own when it isn't in one.</summary>
        public static string KeyFor(GameState state) =>
            state.Game.DraftMatchId.HasValue ? "draft-" + state.Game.DraftMatchId.Value : "game-" + state.Game.Id;

        /// <summary>The id of the newest message, or 0 when there are none.</summary>
        public static int NewestId(IEnumerable<BoardChatMessage> messages) =>
            messages == null ? 0 : messages.Select(m => m.Id).DefaultIfEmpty(0).Max();

        /// <summary>How many messages are newer than the one read through.</summary>
        public static int Unread(IEnumerable<BoardChatMessage> messages, int readThroughId) =>
            messages == null ? 0 : messages.Count(m => m.Id > readThroughId);

        /// <summary>
        /// What an older version of the app remembered -- how many of a game's messages had been read -- as the id of the last of
        /// them, so nothing already read shows as unread after an update.
        /// </summary>
        public static int ReadThroughCount(IReadOnlyList<BoardChatMessage> messages, int readCount)
        {
            if (messages == null || messages.Count == 0 || readCount <= 0)
            {
                return 0;
            }

            return messages.OrderBy(m => m.Id).ElementAt(System.Math.Min(readCount, messages.Count) - 1).Id;
        }
    }
}
