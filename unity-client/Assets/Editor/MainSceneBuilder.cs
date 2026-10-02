using MoodSwings.UI;
using UnityEditor;
using UnityEditor.SceneManagement;
using UnityEngine;
using UnityEngine.EventSystems;
using UnityEngine.InputSystem.UI;
using UnityEngine.UI;

namespace MoodSwings.Editor
{
    /// <summary>
    /// Generates Assets/Scenes/Main.unity -- the app's entry scene: a camera,
    /// a Canvas carrying the ScreenRouter and AppBootstrap, and one child per
    /// screen (each screen builds its own UI at runtime, see UiFactory).
    /// Rebuild it whenever a screen is added. Also runnable headless:
    /// Unity -batchmode -quit -executeMethod MoodSwings.Editor.MainSceneBuilder.Build
    /// </summary>
    public static class MainSceneBuilder
    {
        private const string ScenePath = "Assets/Scenes/Main.unity";

        [MenuItem("MoodSwings/Build Main Scene")]
        public static void Build()
        {
            var scene = EditorSceneManager.NewScene(NewSceneSetup.EmptyScene, NewSceneMode.Single);

            var cameraObject = new GameObject("Main Camera", typeof(Camera));
            cameraObject.tag = "MainCamera";
            var camera = cameraObject.GetComponent<Camera>();
            camera.clearFlags = CameraClearFlags.SolidColor;
            var theme = ScriptableObject.CreateInstance<UiTheme>();
            camera.backgroundColor = theme.background;
            Object.DestroyImmediate(theme);
            camera.orthographic = true;

            var canvasObject = new GameObject(
                "Canvas", typeof(Canvas), typeof(CanvasScaler), typeof(GraphicRaycaster), typeof(ScreenRouter), typeof(AppBootstrap));
            var canvas = canvasObject.GetComponent<Canvas>();
            canvas.renderMode = RenderMode.ScreenSpaceCamera;
            canvas.worldCamera = camera;
            canvas.planeDistance = 10f;
            CanvasSetup.Configure(canvasObject.GetComponent<CanvasScaler>());

            new GameObject("EventSystem", typeof(EventSystem), typeof(InputSystemUIInputModule));

            var screens = new UiScreen[]
            {
                AddScreen<SplashScreen>(canvasObject.transform),
                AddScreen<LoginScreen>(canvasObject.transform),
                AddScreen<HomeScreen>(canvasObject.transform),
                AddScreen<FriendsScreen>(canvasObject.transform),
                AddScreen<SettingsScreen>(canvasObject.transform),
                AddScreen<PlayScreen>(canvasObject.transform),
                AddScreen<NewGameScreen>(canvasObject.transform),
                AddScreen<OpenGamesScreen>(canvasObject.transform),
                AddScreen<BoardScreen>(canvasObject.transform),
                AddScreen<WatchScreen>(canvasObject.transform),
                AddScreen<MaintenanceScreen>(canvasObject.transform),
            };

            var router = new SerializedObject(canvasObject.GetComponent<ScreenRouter>());
            var list = router.FindProperty("screens");
            list.arraySize = screens.Length;
            for (var i = 0; i < screens.Length; i++)
            {
                list.GetArrayElementAtIndex(i).objectReferenceValue = screens[i];
            }

            router.ApplyModifiedPropertiesWithoutUndo();

            EditorSceneManager.SaveScene(scene, ScenePath);
            SceneBuildSettings.Ensure(ScenePath, makeFirst: true);
            Debug.Log("MainSceneBuilder: wrote " + ScenePath);
        }

        private static T AddScreen<T>(Transform parent) where T : UiScreen
        {
            var go = new GameObject(typeof(T).Name, typeof(RectTransform));
            go.transform.SetParent(parent, false);

            var rect = (RectTransform)go.transform;
            rect.anchorMin = Vector2.zero;
            rect.anchorMax = Vector2.one;
            rect.offsetMin = Vector2.zero;
            rect.offsetMax = Vector2.zero;

            return go.AddComponent<T>();
        }
    }
}
