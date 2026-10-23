using System.Collections;
using MoodSwings.Core;
using MoodSwings.Networking;
using MoodSwings.UI;
using Newtonsoft.Json.Linq;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>The unread chat count on the menu icon, across the games of a draft match (which share one chat).</summary>
    public class MatchChatSceneTests
    {
        private static JArray Messages(params int[] ids)
        {
            var list = new JArray();
            foreach (var id in ids)
            {
                list.Add(new JObject
                {
                    ["id"] = id, ["sender_user_id"] = 18, ["sender_username"] = "BotSage", ["channel"] = "table",
                    ["message_text"] = "message " + id, ["created_at"] = "2026-10-01 12:00:00",
                });
            }

            return list;
        }

        private static JObject GameWithChat(int gameId, int? draftMatchId, params int[] messageIds) =>
            PhaseFiveSceneTests.Load(406, s =>
            {
                s["game"]["id"] = gameId;
                s["game"]["draft_match_id"] = draftMatchId.HasValue ? (JToken)draftMatchId.Value : JValue.CreateNull();
                s["chat_messages"] = Messages(messageIds);
                s["round"]["pending_decision"] = null;
            });

        private static string Unread() => PhaseFiveSceneTests.Child("Unread chat")?.GetComponentInChildren<Text>().text;

        private static IEnumerator OpenGame(int gameId)
        {
            AppServices.Device.TourSeen = true;
            var router = Object.FindAnyObjectByType<ScreenRouter>();
            router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, gameId), addToHistory: false);
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
        }

        [UnityTest]
        public IEnumerator TheNextGameOfADraftMatch_DoesNotBringBackWhatWasAlreadyRead()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = GameWithChat(406, 77, 1, 2, 3) };
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            Assert.AreEqual("3", Unread(), "three waiting");

            // Read them.
            yield return PhaseFiveSceneTests.OpenMenu();
            Assert.IsNull(PhaseFiveSceneTests.Child("Unread chat"));
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Close menu"));

            // The match goes on: game 2 carries the same three messages, and a new one.
            server.State = GameWithChat(407, 77, 1, 2, 3, 4);
            yield return OpenGame(407);

            Assert.AreEqual("1", Unread(), "only the new one is waiting, not all four again");
        }

        [UnityTest]
        public IEnumerator WhatWasNotYetRead_StaysWaiting_IntoTheNextGame()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = GameWithChat(406, 77, 1, 2, 3) };
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            Assert.AreEqual("3", Unread());

            // Never opened the chat: on to game 2 with one more message.
            server.State = GameWithChat(407, 77, 1, 2, 3, 4);
            yield return OpenGame(407);

            Assert.AreEqual("4", Unread(), "the number carries over, it doesn't start again");
        }

        [UnityTest]
        public IEnumerator AnUnrelatedGame_HasItsOwnUnreadCount()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = GameWithChat(406, 77, 1, 2, 3) };
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            yield return PhaseFiveSceneTests.OpenMenu();
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Close menu"));
            Assert.IsNull(Unread());

            server.State = GameWithChat(500, null, 8, 9);
            yield return OpenGame(500);

            Assert.AreEqual("2", Unread(), "a game outside the match keeps its own chat");
        }

        [UnityTest]
        public IEnumerator AGameWithACountRememberedByAnOlderVersion_StaysRead()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = GameWithChat(406, 77, 11, 12, 13) };
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            Assert.AreEqual("3", Unread());

            // The older version had remembered that two of this game's messages were read.
            AppServices.Device.SetChatSeen(406, 2);
            var router = Object.FindAnyObjectByType<ScreenRouter>();
            router.Show<HomeScreen>();
            yield return PhaseTwoSceneTests.Frames(3);
            yield return OpenGame(406);

            Assert.AreEqual("1", Unread(), "the third is new; the first two stay read");
        }
    }
}
