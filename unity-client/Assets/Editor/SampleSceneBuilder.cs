using MoodSwings.UI;
using UnityEditor;
using UnityEditor.SceneManagement;
using UnityEngine;
using UnityEngine.EventSystems;
using UnityEngine.UI;

namespace MoodSwings.Editor
{
    /// <summary>
    /// Generates Assets/Scenes/CardArtSample.unity from code, so the scene
    /// can be rebuilt (and reviewed as a diff of this file) rather than
    /// hand-assembled in the Editor. Also runnable headless:
    /// Unity -batchmode -quit -executeMethod MoodSwings.Editor.SampleSceneBuilder.Build
    /// </summary>
    public static class SampleSceneBuilder
    {
        private const string ScenePath = "Assets/Scenes/CardArtSample.unity";
        private const string ThemePath = "Assets/UI/UiTheme.asset";

        [MenuItem("MoodSwings/Build Card Art Sample Scene")]
        public static void Build()
        {
            var theme = LoadOrCreateTheme();
            var scene = EditorSceneManager.NewScene(NewSceneSetup.EmptyScene, NewSceneMode.Single);

            var cameraObject = new GameObject("Main Camera", typeof(Camera));
            cameraObject.tag = "MainCamera";
            var camera = cameraObject.GetComponent<Camera>();
            camera.clearFlags = CameraClearFlags.SolidColor;
            camera.backgroundColor = theme.background;
            camera.orthographic = true;

            var canvasObject = new GameObject("Canvas", typeof(Canvas), typeof(CanvasScaler), typeof(GraphicRaycaster), typeof(ScreenRouter));
            var canvas = canvasObject.GetComponent<Canvas>();
            canvas.renderMode = RenderMode.ScreenSpaceCamera;
            canvas.worldCamera = camera;
            canvas.planeDistance = 10f;
            CanvasSetup.Configure(canvasObject.GetComponent<CanvasScaler>());

            new GameObject("EventSystem", typeof(EventSystem), typeof(StandaloneInputModule));

            var screenObject = NewUi("CardArtSampleScreen", canvasObject.transform, typeof(CardArtSampleScreen));
            Stretch(screenObject);
            var screen = screenObject.GetComponent<CardArtSampleScreen>();

            var background = NewUi("Background", screenObject.transform, typeof(Image));
            Stretch(background);
            background.GetComponent<Image>().color = theme.background;

            var title = NewText("Title", screenObject.transform, "Card art sample (Phase 0)", 48, theme.textPrimary, TextAnchor.MiddleCenter);
            Anchor(title, new Vector2(0f, 1f), new Vector2(1f, 1f), new Vector2(0f, -120f), new Vector2(0f, 0f));

            var grid = NewUi("Grid", screenObject.transform, typeof(GridLayoutGroup));
            Anchor(grid, new Vector2(0f, 0f), new Vector2(1f, 1f), new Vector2(40f, 100f), new Vector2(-40f, -140f));
            var layout = grid.GetComponent<GridLayoutGroup>();
            layout.cellSize = new Vector2(220f, 308f);
            layout.spacing = new Vector2(theme.spacing, theme.spacing);
            layout.childAlignment = TextAnchor.UpperCenter;

            var status = NewText("Status", screenObject.transform, string.Empty, 32, theme.textMuted, TextAnchor.MiddleCenter);
            Anchor(status, new Vector2(0f, 0f), new Vector2(1f, 0f), new Vector2(0f, 0f), new Vector2(0f, 100f));

            SetField(screen, "grid", grid.GetComponent<RectTransform>());
            SetField(screen, "status", status.GetComponent<Text>());

            var router = canvasObject.GetComponent<ScreenRouter>();
            var routerObject = new SerializedObject(router);
            var screens = routerObject.FindProperty("screens");
            screens.arraySize = 1;
            screens.GetArrayElementAtIndex(0).objectReferenceValue = screen;
            routerObject.FindProperty("initialScreenId").stringValue = screen.ScreenId;
            routerObject.ApplyModifiedPropertiesWithoutUndo();

            EditorSceneManager.SaveScene(scene, ScenePath);
            EditorBuildSettings.scenes = new[] { new EditorBuildSettingsScene(ScenePath, true) };
            Debug.Log("SampleSceneBuilder: wrote " + ScenePath);
        }

        private static UiTheme LoadOrCreateTheme()
        {
            var theme = AssetDatabase.LoadAssetAtPath<UiTheme>(ThemePath);
            if (theme != null)
            {
                return theme;
            }

            System.IO.Directory.CreateDirectory(System.IO.Path.GetDirectoryName(ThemePath));
            theme = ScriptableObject.CreateInstance<UiTheme>();
            AssetDatabase.CreateAsset(theme, ThemePath);
            AssetDatabase.SaveAssets();
            return theme;
        }

        private static GameObject NewUi(string name, Transform parent, params System.Type[] components)
        {
            var go = new GameObject(name, typeof(RectTransform));
            go.transform.SetParent(parent, false);
            foreach (var component in components)
            {
                go.AddComponent(component);
            }

            return go;
        }

        private static GameObject NewText(string name, Transform parent, string text, int size, Color color, TextAnchor alignment)
        {
            var go = NewUi(name, parent, typeof(Text));
            var label = go.GetComponent<Text>();
            label.font = Resources.GetBuiltinResource<Font>("LegacyRuntime.ttf");
            label.text = text;
            label.fontSize = size;
            label.color = color;
            label.alignment = alignment;
            return go;
        }

        private static void Stretch(GameObject go)
        {
            Anchor(go, Vector2.zero, Vector2.one, Vector2.zero, Vector2.zero);
        }

        private static void Anchor(GameObject go, Vector2 min, Vector2 max, Vector2 offsetMin, Vector2 offsetMax)
        {
            var rect = go.GetComponent<RectTransform>();
            rect.anchorMin = min;
            rect.anchorMax = max;
            rect.offsetMin = offsetMin;
            rect.offsetMax = offsetMax;
        }

        private static void SetField(Object target, string field, Object value)
        {
            var serialized = new SerializedObject(target);
            serialized.FindProperty(field).objectReferenceValue = value;
            serialized.ApplyModifiedPropertiesWithoutUndo();
        }
    }
}
