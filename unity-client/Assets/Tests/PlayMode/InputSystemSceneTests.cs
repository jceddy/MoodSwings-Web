using System.Collections;
using System.Linq;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.EventSystems;
using UnityEngine.InputSystem;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>
    /// The other scene tests press buttons by calling their click handler, which would
    /// pass even with no working input at all. These drive a virtual mouse and keyboard
    /// through the Input System, the way a player's would arrive, to prove the UI really
    /// responds: a click lands on a button, Escape goes back, Tab moves between fields.
    /// Typing into a text box can't be simulated here: the legacy text box reads engine
    /// keyboard events, which a virtual keyboard doesn't produce.
    /// </summary>
    public class InputSystemSceneTests : InputTestFixture
    {
        private Mouse _mouse;
        private Keyboard _keyboard;

        public override void Setup()
        {
            base.Setup();
#if UNITY_EDITOR
            // In the Editor, pointer input only reaches the game while the Game view has focus,
            // which a headless run (or a test run in the background) doesn't give it.
            InputSystem.settings.editorInputBehaviorInPlayMode = InputSettings.EditorInputBehaviorInPlayMode.AllDeviceInputAlwaysGoesToGameView;
#endif
            _mouse = InputSystem.AddDevice<Mouse>();
            _keyboard = InputSystem.AddDevice<Keyboard>();
        }

        public override void TearDown()
        {
            // A text box that is still active ends its edit when the scene goes away, and the
            // screens read the keyboard then; let that happen while the keyboard still exists.
            foreach (var field in UnityEngine.Object.FindObjectsByType<InputField>(FindObjectsInactive.Include))
            {
                field.DeactivateInputField();
            }

            base.TearDown();
        }

        private static Vector2 ScreenPointOf(Component component)
        {
            var canvas = component.GetComponentInParent<Canvas>();
            return RectTransformUtility.WorldToScreenPoint(canvas.worldCamera, component.transform.position);
        }

        private IEnumerator ClickOn(Component target)
        {
            // Let the screen finish settling at the test resolution before aiming at a position.
            yield return PhaseTwoSceneTests.Frames(10);
            Set(_mouse.position, ScreenPointOf(target));
            yield return PhaseTwoSceneTests.Frames(3);

            // Down and up on separate frames, as a real click is, so the UI sees both.
            Press(_mouse.leftButton);
            yield return PhaseTwoSceneTests.Frames(3);
            Release(_mouse.leftButton);
            yield return PhaseTwoSceneTests.Frames();
        }

        private IEnumerator SignedInAtHome()
        {
            yield return MainSceneTests.Launch(MainSceneTests.MeOk, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return ReEnableTheUiModule();
        }

        // When another scene (the card art sample) has been loaded earlier in the same test run, the UI
        // module in the next scene doesn't respond to the virtual mouse. Replacing it gives the
        // state a fresh start; the app itself has one scene and a module created at startup,
        // which is the case the other runs of these tests cover.
        private static IEnumerator ReEnableTheUiModule()
        {
            var module = UnityEngine.Object.FindAnyObjectByType<UnityEngine.InputSystem.UI.InputSystemUIInputModule>();
            var go = module.gameObject;
            UnityEngine.Object.Destroy(module);
            UnityEngine.Object.Destroy(go.GetComponent<EventSystem>());
            yield return null;
            go.AddComponent<EventSystem>();
            go.AddComponent<UnityEngine.InputSystem.UI.InputSystemUIInputModule>();
            yield return PhaseTwoSceneTests.Frames(3);
        }

        [UnityTest]
        public IEnumerator AMouseClick_PressesTheButtonUnderIt()
        {
            yield return SignedInAtHome();
            var friends = UnityEngine.Object.FindObjectsByType<Button>(FindObjectsInactive.Exclude)
                .First(b => b.GetComponentInChildren<Text>().text.StartsWith("Friends"));

            yield return ClickOn(friends);

            Assert.IsInstanceOf<FriendsScreen>(UnityEngine.Object.FindAnyObjectByType<ScreenRouter>().Current);
        }

        [UnityTest]
        public IEnumerator Escape_GoesBack()
        {
            yield return SignedInAtHome();
            var router = UnityEngine.Object.FindAnyObjectByType<ScreenRouter>();
            router.Show<FriendsScreen>();
            yield return MainSceneTests.WaitFor<FriendsScreen>();

            Press(_keyboard.escapeKey);
            yield return PhaseTwoSceneTests.Frames();
            Release(_keyboard.escapeKey);

            Assert.IsInstanceOf<HomeScreen>(router.Current);
        }

        [UnityTest]
        public IEnumerator Tab_MovesFromTheUsernameToThePassword_AndShiftTabBack()
        {
            yield return MainSceneTests.Launch(_ => MainSceneTests.Reply(404, "{}"));
            yield return MainSceneTests.WaitFor<LoginScreen>();
            var fields = UnityEngine.Object.FindObjectsByType<InputField>(FindObjectsInactive.Exclude)
                .OrderByDescending(f => f.transform.position.y)
                .ToList();
            var username = fields[0];
            var password = fields[1];
            username.Select();
            yield return null;

            Press(_keyboard.tabKey);
            yield return PhaseTwoSceneTests.Frames(3);
            Release(_keyboard.tabKey);
            yield return null;
            Assert.AreSame(password.gameObject, EventSystem.current.currentSelectedGameObject);

            Press(_keyboard.shiftKey);
            Press(_keyboard.tabKey);
            yield return PhaseTwoSceneTests.Frames(3);
            Release(_keyboard.tabKey);
            Release(_keyboard.shiftKey);
            Assert.AreSame(username.gameObject, EventSystem.current.currentSelectedGameObject);
        }
    }
}
