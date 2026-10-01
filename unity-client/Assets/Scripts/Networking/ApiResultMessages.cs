namespace MoodSwings.Networking
{
    public static class ApiResultMessages
    {
        /// <summary>
        /// A message fit to show a player for a failed call: the server's own
        /// wording when it gave one (e.g. "User not found."), a plain
        /// explanation for a dead connection, otherwise the fallback. Null
        /// for maintenance, since the app already switches to the
        /// maintenance screen on its own.
        /// </summary>
        public static string UserMessage<T>(this ApiResult<T> result, string fallback)
        {
            switch (result.Failure)
            {
                case ApiFailureKind.None:
                case ApiFailureKind.Maintenance:
                    return null;
                case ApiFailureKind.Network:
                    return "Can't reach the server. Check your connection and try again.";
                default:
                    return string.IsNullOrWhiteSpace(result.Message) ? fallback : result.Message;
            }
        }
    }
}
