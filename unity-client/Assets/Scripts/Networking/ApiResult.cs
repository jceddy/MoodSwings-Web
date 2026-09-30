namespace MoodSwings.Networking
{
    public enum ApiFailureKind
    {
        None,

        /// <summary>No HTTP response at all -- offline, DNS, TLS, timeout.</summary>
        Network,

        /// <summary>401 -- no/expired session, or (on /login) bad credentials.</summary>
        Unauthorized,

        /// <summary>503 with {"status":"maintenance"} -- see php-app "Maintenance mode".</summary>
        Maintenance,

        /// <summary>Any other 4xx, or a 2xx carrying {"status":"error"}.</summary>
        Rejected,

        /// <summary>5xx.</summary>
        Server,

        /// <summary>A response arrived, but its body wasn't the JSON we expected.</summary>
        InvalidResponse,
    }

    public sealed class ApiResult<T>
    {
        public bool Ok => Failure == ApiFailureKind.None;

        public int HttpStatus { get; private set; }

        public ApiFailureKind Failure { get; private set; }

        /// <summary>The server's "message" field, or a client-side description for Network/InvalidResponse. Null when Ok.</summary>
        public string Message { get; private set; }

        /// <summary>The deserialized body; default when not Ok.</summary>
        public T Value { get; private set; }

        public static ApiResult<T> Success(int httpStatus, T value) =>
            new ApiResult<T> { HttpStatus = httpStatus, Value = value };

        public static ApiResult<T> Fail(ApiFailureKind failure, int httpStatus, string message) =>
            new ApiResult<T> { Failure = failure, HttpStatus = httpStatus, Message = message };
    }
}
