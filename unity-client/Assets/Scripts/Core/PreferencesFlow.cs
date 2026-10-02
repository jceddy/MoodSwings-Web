using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// Reads and saves the signed-in user's preferences. A successful save
    /// also updates the local <see cref="User"/>, so the screen and the rest
    /// of the app agree without another round trip to /me; a failed save
    /// leaves it untouched, so the caller can roll its control back.
    /// </summary>
    public sealed class PreferencesFlow
    {
        private readonly ApiClient _api;
        private readonly AuthFlow _auth;

        public PreferencesFlow(ApiClient api, AuthFlow auth)
        {
            _api = api;
            _auth = auth;
        }

        public bool Get(BoolPreference preference)
        {
            var user = _auth.CurrentUser;
            return (user != null ? preference.Read(user) : null) ?? preference.DefaultValue;
        }

        public string GetBoardLayout()
        {
            var layout = _auth.CurrentUser?.BoardLayoutPreference;
            return layout == PreferenceCatalog.BoardLayoutBelowHand ? layout : PreferenceCatalog.BoardLayoutAbovePlayArea;
        }

        public async Task<ApiResult<ApiEnvelope>> SetAsync(
            BoolPreference preference, bool value, CancellationToken cancellationToken = default)
        {
            var result = await _api.SetPreferenceAsync(preference.Route, preference.JsonKey, value, cancellationToken);
            if (result.Ok && _auth.CurrentUser != null)
            {
                preference.Write(_auth.CurrentUser, value);
            }

            return result;
        }

        public async Task<ApiResult<ApiEnvelope>> SetBoardLayoutAsync(string layout, CancellationToken cancellationToken = default)
        {
            var result = await _api.SetPreferenceAsync(PreferenceCatalog.BoardLayoutRoute, PreferenceCatalog.BoardLayoutKey, layout, cancellationToken);
            if (result.Ok && _auth.CurrentUser != null)
            {
                _auth.CurrentUser.BoardLayoutPreference = layout;
            }

            return result;
        }
    }
}
