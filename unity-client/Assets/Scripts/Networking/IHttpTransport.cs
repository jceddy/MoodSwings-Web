using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public sealed class HttpRequest
    {
        public string Method { get; set; } = "GET";
        public string Url { get; set; }
        public string Body { get; set; }
        public Dictionary<string, string> Headers { get; } = new Dictionary<string, string>();
    }

    public sealed class HttpResponse
    {
        /// <summary>HTTP status, or 0 when no response was received at all.</summary>
        public long StatusCode { get; set; }

        public string Body { get; set; }

        /// <summary>Set only when the request failed below the HTTP level (DNS, TLS, timeout, ...).</summary>
        public string NetworkError { get; set; }

        /// <summary>Case-insensitive. A header sent more than once (Set-Cookie) arrives comma-joined.</summary>
        public Dictionary<string, string> Headers { get; } =
            new Dictionary<string, string>(System.StringComparer.OrdinalIgnoreCase);
    }

    /// <summary>
    /// The one seam between ApiClient and UnityWebRequest, so ApiClient's
    /// cookie/envelope/error handling can be unit-tested against a fake.
    /// Implementations must not throw for HTTP error statuses -- those come
    /// back as a normal response -- only report transport failures via
    /// <see cref="HttpResponse.NetworkError"/>.
    /// </summary>
    public interface IHttpTransport
    {
        Task<HttpResponse> SendAsync(HttpRequest request, CancellationToken cancellationToken);
    }
}
