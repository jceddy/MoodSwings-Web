using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Tests
{
    /// <summary>Scripted transport: queue responses, then inspect what ApiClient sent.</summary>
    public sealed class FakeHttpTransport : IHttpTransport
    {
        private readonly Queue<HttpResponse> _responses = new Queue<HttpResponse>();

        public List<HttpRequest> Requests { get; } = new List<HttpRequest>();

        public HttpRequest LastRequest => Requests[Requests.Count - 1];

        public void Enqueue(long status, string body, string setCookie = null)
        {
            var response = new HttpResponse { StatusCode = status, Body = body };
            if (setCookie != null)
            {
                response.Headers["Set-Cookie"] = setCookie;
            }

            _responses.Enqueue(response);
        }

        public void EnqueueNetworkError(string error)
        {
            _responses.Enqueue(new HttpResponse { NetworkError = error });
        }

        public Task<HttpResponse> SendAsync(HttpRequest request, CancellationToken cancellationToken)
        {
            Requests.Add(request);
            if (_responses.Count == 0)
            {
                throw new InvalidOperationException("FakeHttpTransport: no response queued for " + request.Url);
            }

            return Task.FromResult(_responses.Dequeue());
        }
    }
}
