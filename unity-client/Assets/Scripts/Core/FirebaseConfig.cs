using Newtonsoft.Json.Linq;

namespace MoodSwings.Core
{
    /// <summary>
    /// The Firebase project's client settings, read from the google-services.json kept in
    /// Assets/Resources/Firebase (the file Firebase's console gives for the Android app). These identify the
    /// project to Firebase; they aren't secrets, but the API key should be restricted to the app's package name and
    /// signing certificate in the Google Cloud console.
    /// </summary>
    public sealed class FirebaseConfig
    {
        public string ApplicationId { get; private set; }

        public string ApiKey { get; private set; }

        public string ProjectId { get; private set; }

        /// <summary>The FCM sender id (the project number).</summary>
        public string SenderId { get; private set; }

        public string PackageName { get; private set; }

        /// <summary>Reads a google-services.json for the given Android package; null if it isn't one for that package.</summary>
        public static FirebaseConfig Parse(string json, string packageName)
        {
            if (string.IsNullOrWhiteSpace(json))
            {
                return null;
            }

            try
            {
                var root = JObject.Parse(json);
                foreach (var client in (JArray)root["client"] ?? new JArray())
                {
                    if ((string)client["client_info"]?["android_client_info"]?["package_name"] != packageName)
                    {
                        continue;
                    }

                    var config = new FirebaseConfig
                    {
                        ApplicationId = (string)client["client_info"]["mobilesdk_app_id"],
                        ApiKey = (string)client["api_key"]?[0]?["current_key"],
                        ProjectId = (string)root["project_info"]?["project_id"],
                        SenderId = (string)root["project_info"]?["project_number"],
                        PackageName = packageName,
                    };

                    return config.IsComplete ? config : null;
                }
            }
            catch (System.Exception)
            {
                // Not a usable file: push just isn't available.
            }

            return null;
        }

        public bool IsComplete =>
            !string.IsNullOrEmpty(ApplicationId) && !string.IsNullOrEmpty(ApiKey)
            && !string.IsNullOrEmpty(ProjectId) && !string.IsNullOrEmpty(SenderId);
    }
}
