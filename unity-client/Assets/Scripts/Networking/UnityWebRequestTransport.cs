using System.Text;
using System.Threading;
using System.Threading.Tasks;
using UnityEngine;
using UnityEngine.Networking;

namespace MoodSwings.Networking
{
    public sealed class UnityWebRequestTransport : IHttpTransport
    {
        private readonly int _timeoutSeconds;

        public UnityWebRequestTransport(int timeoutSeconds = 30)
        {
            _timeoutSeconds = timeoutSeconds;
        }

        /// <summary>Optional diagnostics sink (the Unity log in the app).</summary>
        public System.Action<string> Diagnostic { get; set; }

        private void ClearEngineCookies(string url)
        {
            try
            {
                UnityWebRequest.ClearCookieCache(new System.Uri(url));
            }
            catch (System.Exception e)
            {
                Diagnostic?.Invoke("Couldn't clear Unity's cookie store: " + e.Message);
            }
        }

        public async Task<HttpResponse> SendAsync(HttpRequest request, CancellationToken cancellationToken)
        {
            // Unity keeps its own cookie store and adds what's in it to every request, next to the Cookie header
            // ApiClient sets itself. A stale session_token in there (from an earlier login) would be sent first, and PHP
            // reads the first one -- so the server would reject a perfectly good login. ApiClient is the only owner of
            // the session cookie, so empty Unity's store before each request.
            ClearEngineCookies(request.Url);

            using var webRequest = new UnityWebRequest(request.Url, request.Method);
            webRequest.downloadHandler = new DownloadHandlerBuffer();
            webRequest.timeout = _timeoutSeconds;

            if (request.Body != null)
            {
                webRequest.uploadHandler = new UploadHandlerRaw(Encoding.UTF8.GetBytes(request.Body));
            }

            foreach (var header in request.Headers)
            {
                webRequest.SetRequestHeader(header.Key, header.Value);
            }

            var operation = webRequest.SendWebRequest();
            while (!operation.isDone)
            {
                if (cancellationToken.IsCancellationRequested)
                {
                    webRequest.Abort();
                    cancellationToken.ThrowIfCancellationRequested();
                }

                await Awaitable.NextFrameAsync(cancellationToken);
            }

            var response = new HttpResponse { StatusCode = webRequest.responseCode };

            // ProtocolError = the server answered with a 4xx/5xx; that's a
            // normal API response here (the JSON body carries the message).
            if (webRequest.result == UnityWebRequest.Result.ConnectionError
                || webRequest.result == UnityWebRequest.Result.DataProcessingError)
            {
                response.NetworkError = webRequest.error;
                return response;
            }

            response.Body = webRequest.downloadHandler.text;

            var headers = webRequest.GetResponseHeaders();
            if (headers != null)
            {
                foreach (var header in headers)
                {
                    response.Headers[header.Key] = header.Value;
                }
            }

            return response;
        }
    }
}
