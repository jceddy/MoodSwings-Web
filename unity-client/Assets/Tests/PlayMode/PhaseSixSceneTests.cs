using System.Collections;
using System.Linq;
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
    }
}
