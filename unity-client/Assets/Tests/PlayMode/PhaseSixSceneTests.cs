using System.Collections;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.UI;
using Newtonsoft.Json.Linq;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.EventSystems;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>
    /// The Arena-feel parts of the board, on the same scripted server and captured games as the
    /// Phase 5 tests. Dragging is driven by sending the drag events a pointer would, to the card.
    /// </summary>
    public class PhaseSixSceneTests
    {
        private static readonly Vector2 OverTheTable = new Vector2(960f, 650f);
        private static readonly Vector2 OverTheHand = new Vector2(700f, 120f);

        private static PhaseFiveSceneTests.PlayServer YourTurn(JObject state = null) =>
            new PhaseFiveSceneTests.PlayServer { State = state ?? PhaseFiveSceneTests.Load(406) };

        private static GameObject Card(string name) => PhaseFiveSceneTests.Child("Card " + name).gameObject;

        private static Vector2 ScreenPointOf(GameObject go)
        {
            var canvas = go.GetComponentInParent<Canvas>();
            return RectTransformUtility.WorldToScreenPoint(canvas.worldCamera, go.transform.position);
        }

        private static PointerEventData Pointer(Vector2 at) =>
            new PointerEventData(EventSystem.current) { position = at, button = PointerEventData.InputButton.Left };

        private static IEnumerator Begin(GameObject card, PointerEventData data)
        {
            ExecuteEvents.Execute(card, data, ExecuteEvents.beginDragHandler);
            yield return PhaseTwoSceneTests.Frames(2);
        }

        private static IEnumerator MoveTo(GameObject card, PointerEventData data, Vector2 at)
        {
            data.position = at;
            ExecuteEvents.Execute(card, data, ExecuteEvents.dragHandler);
            yield return PhaseTwoSceneTests.Frames(2);
        }

        private static IEnumerator Release(GameObject card, PointerEventData data)
        {
            ExecuteEvents.Execute(card, data, ExecuteEvents.endDragHandler);
            yield return PhaseTwoSceneTests.Frames(4);
        }

        /// <summary>Picks a card up, carries it to a point, and lets go.</summary>
        private static IEnumerator DragAndDrop(string cardName, Vector2 to)
        {
            var card = Card(cardName);
            var data = Pointer(ScreenPointOf(card));
            yield return Begin(card, data);
            yield return MoveTo(card, data, to);
            yield return Release(card, data);
        }

        private static BoardScreen Board() => PhaseFiveSceneTests.Board();

        private sealed class RecordingSound : ISoundPlayer
        {
            public List<SoundId> Played { get; } = new List<SoundId>();

            public void Play(SoundId sound) => Played.Add(sound);
        }

        private sealed class RecordingHaptics : IHaptics
        {
            public List<HapticKind> Pulses { get; } = new List<HapticKind>();

            public void Pulse(HapticKind kind) => Pulses.Add(kind);
        }

        private RecordingSound _sound;
        private RecordingHaptics _haptics;

        [SetUp]
        public void ListenForFeedback()
        {
            _sound = new RecordingSound();
            _haptics = new RecordingHaptics();
            GameFeedback.Sound = _sound;
            GameFeedback.Haptics = _haptics;
        }

        [TearDown]
        public void StopListening()
        {
            GameFeedback.Sound = null;
            GameFeedback.Haptics = null;
        }

        // --- drag to play ---------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator PickingUpACard_ShowsItFollowingThePointer_AndWhereToDropIt()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            var card = Card("Superiority");
            var data = Pointer(ScreenPointOf(card));

            yield return Begin(card, data);

            Assert.IsTrue(Board().IsDragging);
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Drag ghost"));
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Drop zone"), "the table is marked as the place to drop");
            Assert.AreEqual(0.3f, card.GetComponent<CanvasGroup>().alpha, 0.001f, "the card left behind is faded");

            yield return MoveTo(card, data, OverTheTable);
            Assert.IsTrue(Board().DragIsOverDropZone);
            var ghostPoint = ScreenPointOf(PhaseFiveSceneTests.Child("Drag ghost").gameObject);
            Assert.AreEqual(OverTheTable.x, ghostPoint.x, 2f, "the ghost follows the pointer");
            Assert.AreEqual(OverTheTable.y, ghostPoint.y, 2f);
            ScreenshotHelper.Capture("drag-to-play");

            yield return MoveTo(card, data, OverTheHand);
            Assert.IsFalse(Board().DragIsOverDropZone, "back over the hand it no longer plays");

            yield return Release(card, data);
        }

        [UnityTest]
        public IEnumerator DroppingACardWithNoChoicesOnTheTable_PlaysItAtOnce()
        {
            var server = YourTurn();
            server.OnPost = (path, body) =>
            {
                server.State = PhaseFiveSceneTests.AfterPlaying(14171);
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return DragAndDrop("Superiority", OverTheTable);

            var play = server.Posts("/games/play").Single();
            Assert.AreEqual(14171, (int)play["card_id"]);
            Assert.AreEqual(0, ((JObject)play["choices"]).Count);
            Assert.IsFalse(Board().Choices.IsOpen, "dragging it there was the decision; no form to confirm");
            Assert.IsFalse(Board().IsDragging);
            Assert.AreEqual("BotSage's turn", Board().BannerText);
        }

        [UnityTest]
        public IEnumerator DroppingACardThatAsksForChoices_OpensItsForm_InsteadOfPlayingBlind()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return DragAndDrop("Corruption", OverTheTable);

            Assert.IsTrue(Board().Choices.IsOpen);
            Assert.AreEqual("Corruption", Board().Choices.TitleText);
            Assert.AreEqual(0, server.Posts("/games/play").Count(), "nothing is sent until the form is submitted");
        }

        [UnityTest]
        public IEnumerator ACardLetGoOverTheHand_IsNotPlayed()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return DragAndDrop("Superiority", OverTheHand);

            Assert.AreEqual(0, server.Posts("/games/play").Count());
            Assert.IsFalse(Board().Choices.IsOpen);
            Assert.IsFalse(Board().IsDragging, "the ghost is gone");
            Assert.AreEqual(1f, Card("Superiority").GetComponent<CanvasGroup>().alpha, 0.001f, "and the card is back to normal");
            Assert.IsNull(PhaseFiveSceneTests.Child("Drop zone"));
        }

        [UnityTest]
        public IEnumerator DroppingACardThatCantBePlayed_SaysWhy_AndSendsNothing()
        {
            var server = YourTurn(PhaseFiveSceneTests.Load(406, s => s["you"]["hand"][0]["is_playable"] = false));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return DragAndDrop("Superiority", OverTheTable);

            StringAssert.Contains("can't be played", Board().MessageText);
            Assert.AreEqual(0, server.Posts("/games/play").Count());
        }

        [UnityTest]
        public IEnumerator ADropTheServerRefuses_ShowsItsReason()
        {
            var server = YourTurn();
            server.OnPost = (path, body) => MainSceneTests.Reply(409, "{\"status\":\"error\",\"message\":\"It is not your turn.\"}");
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return DragAndDrop("Superiority", OverTheTable);

            Assert.AreEqual("It is not your turn.", Board().MessageText);
        }

        [UnityTest]
        public IEnumerator WhenItIsNotYourTurn_CardsCantBePickedUp()
        {
            var server = YourTurn(PhaseFiveSceneTests.Load(405, s => s["round"]["pending_decision"] = null));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            var card = Card("Pity");
            Assert.IsNull(card.GetComponent<DraggableCard>());
        }

        [UnityTest]
        public IEnumerator IfTheBoardChangesUnderADrag_TheDragIsCalledOff()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            var card = Card("Superiority");
            var data = Pointer(ScreenPointOf(card));
            yield return Begin(card, data);
            Assert.IsTrue(Board().IsDragging);

            // The turn times out while the card is in the air.
            server.State = PhaseFiveSceneTests.Load(406, s =>
            {
                s["you"]["is_your_turn"] = false;
                s["round"]["current_turn_game_player_id"] = 910;
            });
            yield return PhaseFiveSceneTests.Poll();

            Assert.IsFalse(Board().IsDragging, "nothing is left hanging on screen");
            Assert.AreEqual(0, server.Posts("/games/play").Count());
        }

        [UnityTest]
        public IEnumerator ASpectatorsOrOpponentsCards_AreNotDraggable_AndNeitherAreMoodsOnTheTable()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsNull(Card("Ambition").GetComponent<DraggableCard>(), "a mood in play");
            Assert.IsNull(Card("Frustration").GetComponent<DraggableCard>(), "an opponent's mood");
        }

        // --- cues: sounds, buzzes, cards sliding in, results ----------------------------------------------

        [UnityTest]
        public IEnumerator TheFirstDrawOfAGame_MakesNoSoundAndMovesNothing()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.AreEqual(0, _sound.Played.Count);
            Assert.AreEqual(0, _haptics.Pulses.Count);
            Assert.IsNull(PhaseFiveSceneTests.Child("Flying card"));
        }

        [UnityTest]
        public IEnumerator ACardPlayedByAnOpponent_SlidesInAndThumps()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            server.State = PhaseFiveSceneTests.Load(406, s => ((JArray)s["in_play"]).Add(JObject.Parse(
                "{\"card_id\":99001,\"catalog_card_id\":1,\"name\":\"Newcomer\",\"color\":\"red\",\"base_color\":\"red\"," +
                "\"value\":3,\"base_value\":3,\"owner_game_player_id\":910,\"suppressions\":[],\"choice_fields\":[]}")));
            // Look the moment the card appears: the slide only lasts a moment.
            var waited = 0f;
            while (PhaseFiveSceneTests.Child("Card Newcomer") == null && waited < 6f)
            {
                waited += Time.unscaledDeltaTime;
                yield return null;
            }

            CollectionAssert.AreEqual(new[] { SoundId.CardPlay }, _sound.Played);
            Assert.AreEqual(0, _haptics.Pulses.Count, "an opponent's play doesn't buzz you");
            var card = Card("Newcomer");
            Assert.AreEqual(0f, card.GetComponent<CanvasGroup>().alpha, "hidden while its stand-in is on the way");

            // Mid-flight the stand-in is somewhere between its player's seat and the card's place.
            yield return new WaitForSeconds(0.15f);
            var flying = PhaseFiveSceneTests.Child("Flying card");
            Assert.IsNotNull(flying, "a copy of the card is travelling");
            Assert.AreNotEqual(card.transform.position, flying.position);
            Assert.AreNotEqual(PhaseFiveSceneTests.Child("Seat BotSage").position, flying.position);

            yield return new WaitForSeconds(0.6f);
            Assert.IsNull(PhaseFiveSceneTests.Child("Flying card"), "it has landed");
            Assert.AreEqual(1f, card.GetComponent<CanvasGroup>().alpha, "and the real card shows");
        }

        [UnityTest]
        public IEnumerator TheTurnComingToYou_ChimesAndBuzzes()
        {
            var server = YourTurn(PhaseFiveSceneTests.Load(406, s =>
            {
                s["you"]["is_your_turn"] = false;
                s["round"]["current_turn_game_player_id"] = 910;
            }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            Assert.AreEqual(0, _sound.Played.Count);

            server.State = PhaseFiveSceneTests.Load(406);
            yield return PhaseFiveSceneTests.Poll();

            CollectionAssert.AreEqual(new[] { SoundId.YourTurn }, _sound.Played);
            CollectionAssert.AreEqual(new[] { HapticKind.Medium }, _haptics.Pulses);
        }

        [UnityTest]
        public IEnumerator WithSoundSwitchedOff_NothingPlays_ButItStillBuzzes()
        {
            var server = YourTurn(PhaseFiveSceneTests.Load(406, s =>
            {
                s["you"]["is_your_turn"] = false;
                s["round"]["current_turn_game_player_id"] = 910;
            }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            AppServices.Device.SoundOn = false;

            server.State = PhaseFiveSceneTests.Load(406);
            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual(0, _sound.Played.Count);
            Assert.AreEqual(1, _haptics.Pulses.Count);
        }

        [UnityTest]
        public IEnumerator AQuestionForYou_GetsYourAttention()
        {
            var server = YourTurn(PhaseFiveSceneTests.Load(407, s => s["round"]["pending_decision"] = null));
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);

            server.State = PhaseFiveSceneTests.Load(407);
            yield return PhaseFiveSceneTests.Poll();

            CollectionAssert.Contains(_sound.Played, SoundId.Attention);
            CollectionAssert.Contains(_haptics.Pulses, HapticKind.Medium);
        }

        [UnityTest]
        public IEnumerator AWonRound_IsAnnouncedForAMoment_WithAFanfare()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            server.State = PhaseFiveSceneTests.Load(406, s =>
            {
                s["round"]["round_number"] = 2;
                s["players"][0]["total_wins"] = 1;
            });
            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual("You won the round!", Board().MessageText);
            CollectionAssert.Contains(_sound.Played, SoundId.RoundWon);

            yield return new WaitForSeconds(7.4f);
            Assert.AreEqual(string.Empty, Board().MessageText, "and it fades on its own");
        }

        [UnityTest]
        public IEnumerator AMessageFromAnotherPlayer_Blips()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            server.State = PhaseFiveSceneTests.Load(406, s => s["chat_messages"] = JArray.Parse(
                "[{\"id\":1,\"sender_username\":\"BotSage\",\"message_text\":\"hi\",\"created_at\":\"2026-01-01 00:00:00\"}]"));
            yield return PhaseFiveSceneTests.Poll();

            CollectionAssert.AreEqual(new[] { SoundId.Chat }, _sound.Played);
        }

        [UnityTest]
        public IEnumerator Settings_OffersTheSoundSwitch_AndRemembersIt()
        {
            var server = YourTurn();
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            UnityEngine.Object.FindAnyObjectByType<ScreenRouter>().Show<SettingsScreen>();
            yield return MainSceneTests.WaitFor<SettingsScreen>();

            var toggle = PhaseTwoSceneTests.FindToggle("Sound effects");
            Assert.IsNotNull(toggle);
            Assert.IsTrue(toggle.isOn);

            toggle.isOn = false;
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsFalse(AppServices.Device.SoundOn);
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Vibration"), "vibration is only offered on a phone");
        }

        // --- resting the mouse on a card -------------------------------------------------------------------

        private static IEnumerator HoverOver(string cardName)
        {
            var card = Card(cardName);
            ExecuteEvents.Execute(card, Pointer(ScreenPointOf(card)), ExecuteEvents.pointerEnterHandler);
            yield return null;
        }

        private static IEnumerator MoveAwayFrom(string cardName)
        {
            ExecuteEvents.Execute(Card(cardName), Pointer(Vector2.zero), ExecuteEvents.pointerExitHandler);
            yield return PhaseTwoSceneTests.Frames(2);
        }

        [UnityTest]
        public IEnumerator RestingTheMouseOnACard_ShowsALargerCopy_AfterAMoment()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return HoverOver("Frustration");
            yield return new WaitForSeconds(0.1f);
            Assert.IsFalse(Board().HoverPreviewShown, "not at once, or sweeping the mouse across the table would flash");

            yield return new WaitForSeconds(0.4f);
            Assert.IsTrue(Board().HoverPreviewShown);
            var preview = PhaseFiveSceneTests.Child("Hover preview");
            var shown = preview.GetComponentsInChildren<Transform>().First(t => t.name == "Preview card");
            Assert.Greater(((RectTransform)shown).rect.height, 400f, "much larger than the table card");
            ScreenshotHelper.Capture("hover-preview");

            yield return MoveAwayFrom("Frustration");
            Assert.IsFalse(Board().HoverPreviewShown, "gone as soon as the mouse leaves");
        }

        [UnityTest]
        public IEnumerator TheMouseJustPassingOver_ShowsNothing()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return HoverOver("Frustration");
            yield return new WaitForSeconds(0.1f);
            yield return MoveAwayFrom("Frustration");
            yield return new WaitForSeconds(0.5f);

            Assert.IsFalse(Board().HoverPreviewShown);
        }

        [UnityTest]
        public IEnumerator ThePreview_AppearsOnTheSideAwayFromThePointer()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            var frustration = Card("Frustration"); // on the right half of the table
            ExecuteEvents.Execute(frustration, Pointer(new Vector2(1500f, 700f)), ExecuteEvents.pointerEnterHandler);
            yield return new WaitForSeconds(0.5f);

            var preview = PhaseFiveSceneTests.Child("Hover preview");
            Assert.Less(preview.position.x, PhaseFiveSceneTests.Child("Seat bshaftoe").position.x, "pointer on the right, so the preview is on the left");
        }

        [UnityTest]
        public IEnumerator NoPreviewWhileAPopUpIsOpen()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            yield return PhaseFiveSceneTests.Tap(PhaseTwoSceneTests.FindButton("Log"));

            yield return HoverOver("Frustration");
            yield return new WaitForSeconds(0.5f);

            Assert.IsFalse(Board().HoverPreviewShown);
        }

        [UnityTest]
        public IEnumerator Preview_SaysWhenAMoodsValueIsChanged_AndWhatSuppressesIt()
        {
            var server = YourTurn(PhaseFiveSceneTests.Load(406, s =>
            {
                var mood = s["in_play"].Single(c => (string)c["name"] == "Frustration");
                mood["value"] = 0;
                mood["base_value"] = 5;
                mood["is_suppressed"] = true;
                mood["suppressions"] = JArray.Parse("[{\"expiry\":\"end_of_round\",\"suppressed_by_name\":\"Scorn\"}]");
            }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return HoverOver("Frustration");
            yield return new WaitForSeconds(0.5f);

            var text = PhaseFiveSceneTests.Child("Hover preview").GetComponentsInChildren<Text>().Select(t => t.text).ToList();
            Assert.IsTrue(text.Any(t => t.Contains("Value now 0 (printed 5)")), string.Join(" | ", text));
            Assert.IsTrue(text.Any(t => t.Contains("Suppressed by Scorn")));
        }

        [UnityTest]
        public IEnumerator PickingUpACard_PutsAwayThePreview()
        {
            var server = YourTurn();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return HoverOver("Superiority");
            yield return new WaitForSeconds(0.5f);
            Assert.IsTrue(Board().HoverPreviewShown);

            var card = Card("Superiority");
            var data = Pointer(ScreenPointOf(card));
            yield return Begin(card, data);

            Assert.IsFalse(Board().HoverPreviewShown);
            yield return Release(card, data);
        }
    }
}
