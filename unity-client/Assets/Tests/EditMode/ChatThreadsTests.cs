using System.Collections.Generic;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>What has been read of a chat that a draft match shares between its games.</summary>
    public class ChatThreadsTests
    {
        private static List<BoardChatMessage> Messages(params int[] ids)
        {
            var list = new List<BoardChatMessage>();
            foreach (var id in ids)
            {
                list.Add(new BoardChatMessage { Id = id, SenderUsername = "BotSage", MessageText = "m" + id });
            }

            return list;
        }

        [Test]
        public void AllTheGamesOfADraftMatch_ShareOneChat()
        {
            var first = BoardFixtures.Load(405);
            first.Game.DraftMatchId = 77;
            var second = BoardFixtures.Load(406);
            second.Game.DraftMatchId = 77;

            Assert.AreEqual(ChatThreads.KeyFor(first), ChatThreads.KeyFor(second));
            Assert.AreEqual("draft-77", ChatThreads.KeyFor(first));
        }

        [Test]
        public void AGameNotInADraftMatch_HasItsOwnChat()
        {
            var one = BoardFixtures.Load(405);
            one.Game.DraftMatchId = null;
            var other = BoardFixtures.Load(406);
            other.Game.DraftMatchId = null;

            Assert.AreNotEqual(ChatThreads.KeyFor(one), ChatThreads.KeyFor(other));
            Assert.AreEqual("game-" + one.Game.Id, ChatThreads.KeyFor(one));
        }

        [Test]
        public void UnreadIsWhatIsNewerThanTheNewestOneRead()
        {
            var messages = Messages(4, 7, 9, 12);

            Assert.AreEqual(4, ChatThreads.Unread(messages, 0));
            Assert.AreEqual(2, ChatThreads.Unread(messages, 7));
            Assert.AreEqual(0, ChatThreads.Unread(messages, 12));
            Assert.AreEqual(0, ChatThreads.Unread(messages, 50));
            Assert.AreEqual(0, ChatThreads.Unread(null, 0));
            Assert.AreEqual(12, ChatThreads.NewestId(messages));
            Assert.AreEqual(0, ChatThreads.NewestId(Messages()));
        }

        [Test]
        public void WhatWasReadInOneGame_IsStillReadInTheNext_WhichCarriesTheSameMessagesAndMore()
        {
            var inGameOne = Messages(1, 2, 3);
            var readThrough = ChatThreads.NewestId(inGameOne);

            var inGameTwo = Messages(1, 2, 3, 4);

            Assert.AreEqual(1, ChatThreads.Unread(inGameTwo, readThrough), "only the new one");
        }

        [Test]
        public void ACountFromAnOlderVersion_BecomesTheIdOfTheLastMessageRead()
        {
            var messages = Messages(10, 20, 30, 40);

            Assert.AreEqual(0, ChatThreads.ReadThroughCount(messages, 0));
            Assert.AreEqual(20, ChatThreads.ReadThroughCount(messages, 2));
            Assert.AreEqual(40, ChatThreads.ReadThroughCount(messages, 4));
            Assert.AreEqual(40, ChatThreads.ReadThroughCount(messages, 9), "more than there are: all of them");
            Assert.AreEqual(0, ChatThreads.ReadThroughCount(Messages(), 3));
        }

        [Test]
        public void WhatHasBeenRead_IsRememberedPerChat_AndStartsAsNothing()
        {
            var store = new InMemoryKeyValueStore();
            Assert.IsNull(new DeviceSettings(store).ChatSeenThrough("draft-77"));

            new DeviceSettings(store).SetChatSeenThrough("draft-77", 31);

            Assert.AreEqual(31, new DeviceSettings(store).ChatSeenThrough("draft-77"));
            Assert.IsNull(new DeviceSettings(store).ChatSeenThrough("draft-78"), "another match has its own");
            Assert.IsNull(new DeviceSettings(store).ChatSeenThrough("game-77"), "and so does a game with the same number");
        }
    }
}
