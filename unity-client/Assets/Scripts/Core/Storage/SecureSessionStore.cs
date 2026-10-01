using System;
using System.Text;
using MoodSwings.Networking;
using UnityEngine;

namespace MoodSwings.Core.Storage
{
    /// <summary>
    /// Keeps the session_token cookie value across launches, encrypted by an
    /// <see cref="ISecretProtector"/> and stored (base64) in an
    /// <see cref="IKeyValueStore"/>. The token always lives in memory for
    /// the current run; <see cref="Persist"/> only controls whether it's
    /// also written to disk ("remember me"). Fails closed: if the platform
    /// has no secure storage, or encryption/decryption fails, nothing is
    /// written and nothing is restored -- the user just logs in again.
    /// </summary>
    public sealed class SecureSessionStore : ISessionStore
    {
        private const string Key = "session.v1";

        private readonly IKeyValueStore _store;
        private readonly ISecretProtector _protector;

        private string _token;
        private bool _persist = true;
        private bool _warnedUnavailable;

        public SecureSessionStore(IKeyValueStore store, ISecretProtector protector)
        {
            _store = store;
            _protector = protector;
        }

        /// <summary>
        /// Whether the session is written to disk. Turning it off also
        /// deletes whatever is already there; turning it back on writes the
        /// current session.
        /// </summary>
        public bool Persist
        {
            get => _persist;
            set
            {
                _persist = value;
                if (!value)
                {
                    _store.Delete(Key);
                }
                else if (_token != null)
                {
                    WriteToDisk();
                }
            }
        }

        public string Load()
        {
            if (_token != null)
            {
                return _token;
            }

            var stored = _store.GetString(Key);
            if (string.IsNullOrEmpty(stored))
            {
                return null;
            }

            try
            {
                if (_protector.IsAvailable
                    && _protector.TryUnprotect(Convert.FromBase64String(stored), out var bytes))
                {
                    _token = Encoding.UTF8.GetString(bytes);
                    return _token;
                }
            }
            catch (FormatException)
            {
                // Not valid base64 -- fall through and discard it.
            }

            _store.Delete(Key);
            return null;
        }

        public void Save(string sessionToken)
        {
            _token = sessionToken;
            if (_persist)
            {
                WriteToDisk();
            }
        }

        public void Clear()
        {
            _token = null;
            _store.Delete(Key);
        }

        private void WriteToDisk()
        {
            if (!_protector.IsAvailable)
            {
                if (!_warnedUnavailable)
                {
                    _warnedUnavailable = true;
                    Debug.Log("No secure storage on this platform; the session won't be remembered between launches.");
                }

                return;
            }

            try
            {
                var protectedBytes = _protector.Protect(Encoding.UTF8.GetBytes(_token));
                _store.SetString(Key, Convert.ToBase64String(protectedBytes));
            }
            catch (Exception e)
            {
                Debug.LogWarning("Couldn't protect the session token; not saving it. " + e.Message);
                _store.Delete(Key);
            }
        }
    }
}
