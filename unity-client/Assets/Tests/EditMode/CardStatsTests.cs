using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using Newtonsoft.Json;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>The card statistics list: reading GET /stats/cards, then filtering, ordering and paging it.</summary>
    public class CardStatsTests
    {
        private const string Json =
            @"{""status"":""ok"",""cards"":[
              {""catalog_card_id"":1,""name"":""Calm"",""set_code"":""BASE"",""collector_number"":12,""rarity"":""common"",""color"":""blue"",
               ""times_in_deck"":40,""deck_win_rate"":0.55,""times_played"":30,""play_win_rate"":0.6,
               ""quick_draft"":{""average"":4.2,""count"":12},""winston_draft"":{""average"":null,""count"":0},""grid_draft"":{""average"":2,""count"":1},""rotisserie_draft"":{""average"":null,""count"":0}},
              {""catalog_card_id"":2,""name"":""Anger"",""set_code"":""BASE"",""collector_number"":3,""rarity"":""rare"",""color"":""red"",
               ""times_in_deck"":10,""deck_win_rate"":0.3,""times_played"":0,""play_win_rate"":null,
               ""quick_draft"":{""average"":null,""count"":0},""winston_draft"":{""average"":null,""count"":0},""grid_draft"":{""average"":null,""count"":0},""rotisserie_draft"":{""average"":null,""count"":0}},
              {""catalog_card_id"":3,""name"":""Boredom"",""set_code"":""PROMO"",""collector_number"":null,""rarity"":""mythic"",""color"":""black"",
               ""times_in_deck"":0,""deck_win_rate"":null,""times_played"":0,""play_win_rate"":null,
               ""quick_draft"":{""average"":null,""count"":0},""winston_draft"":{""average"":null,""count"":0},""grid_draft"":{""average"":null,""count"":0},""rotisserie_draft"":{""average"":null,""count"":0}},
              {""catalog_card_id"":4,""name"":""Dread"",""set_code"":null,""collector_number"":null,""rarity"":""uncommon"",""color"":""green"",
               ""times_in_deck"":5,""deck_win_rate"":0.8,""times_played"":5,""play_win_rate"":0.4,
               ""quick_draft"":{""average"":1.5,""count"":2},""winston_draft"":{""average"":null,""count"":0},""grid_draft"":{""average"":null,""count"":0},""rotisserie_draft"":{""average"":null,""count"":0}}]}";

        private static List<CardStat> Cards() => JsonConvert.DeserializeObject<CardStatsResponse>(Json).Cards;

        private static string[] Names(IEnumerable<CardStat> cards) => cards.Select(c => c.Name).ToArray();

        [Test]
        public void TheCards_ParseWithNullsForWhatHasNoData()
        {
            var cards = Cards();

            Assert.AreEqual(4, cards.Count);
            Assert.AreEqual(0.55, cards[0].DeckWinRate);
            Assert.AreEqual(4.2, cards[0].QuickDraft.Average);
            Assert.IsNull(cards[0].WinstonDraft.Average);
            Assert.IsNull(cards[1].PlayWinRate);
            Assert.IsNull(cards[2].CollectorNumber);
            Assert.IsNull(cards[3].SetCode);
        }

        [Test]
        public void Rates_AndPicks_ReadAsTheWebPageWritesThem()
        {
            Assert.AreEqual("55%", CardStatsDisplay.Rate(0.55));
            Assert.AreEqual("—", CardStatsDisplay.Rate(null));
            Assert.AreEqual("4.20 (12 picks)", CardStatsDisplay.Pick(Cards()[0].QuickDraft));
            Assert.AreEqual("2.00 (1 pick)", CardStatsDisplay.Pick(Cards()[0].GridDraft));
            Assert.AreEqual("—", CardStatsDisplay.Pick(Cards()[0].WinstonDraft));
            Assert.AreEqual("BASE #12  ·  Common  ·  Blue", CardStatsDisplay.Where(Cards()[0]));
            Assert.AreEqual("No set  ·  Uncommon  ·  Green", CardStatsDisplay.Where(Cards()[3]));
        }

        [Test]
        public void ItStartsByNameAscending()
        {
            var query = new CardStatsQuery();

            CollectionAssert.AreEqual(new[] { "Anger", "Boredom", "Calm", "Dread" }, Names(query.Filtered(Cards())));
        }

        [Test]
        public void ChoosingTheCurrentColumnAgain_FlipsTheDirection_AndANewOneStartsAscending()
        {
            var query = new CardStatsQuery();
            query.SortBy("times_in_deck");
            CollectionAssert.AreEqual(new[] { "Boredom", "Dread", "Anger", "Calm" }, Names(query.Filtered(Cards())));

            query.SortBy("times_in_deck");
            Assert.IsFalse(query.Ascending);
            CollectionAssert.AreEqual(new[] { "Calm", "Anger", "Dread", "Boredom" }, Names(query.Filtered(Cards())));

            query.SortBy("name");
            Assert.IsTrue(query.Ascending);
        }

        [Test]
        public void CardsWithNoValueForAColumn_AlwaysGoLast_WhicheverWayItRuns()
        {
            var query = new CardStatsQuery();
            query.SortBy("play_win_rate");
            CollectionAssert.AreEqual(new[] { "Dread", "Calm" }, Names(query.Filtered(Cards())).Take(2).ToArray());

            query.SortBy("play_win_rate");
            CollectionAssert.AreEqual(new[] { "Calm", "Dread" }, Names(query.Filtered(Cards())).Take(2).ToArray());
            Assert.AreEqual(4, query.Filtered(Cards()).Count, "the rest stay on the list, after");
        }

        [Test]
        public void RarityAndColor_OrderAsTheGameDoes_NotAlphabetically()
        {
            var query = new CardStatsQuery();
            query.SortBy("rarity");
            CollectionAssert.AreEqual(new[] { "Calm", "Dread", "Anger", "Boredom" }, Names(query.Filtered(Cards())), "common, uncommon, rare, mythic");

            query.SortBy("color");
            CollectionAssert.AreEqual(new[] { "Calm", "Boredom", "Anger", "Dread" }, Names(query.Filtered(Cards())), "white, blue, black, red, green");
        }

        [Test]
        public void TheDraftColumns_OrderByTheAverage()
        {
            var query = new CardStatsQuery();
            query.SortBy("quick_draft");

            CollectionAssert.AreEqual(new[] { "Dread", "Calm" }, Names(query.Filtered(Cards())).Take(2).ToArray());
        }

        [Test]
        public void TheSearch_MatchesAnyPartOfTheName_IgnoringCase()
        {
            var query = new CardStatsQuery { Search = "  RE" };

            CollectionAssert.AreEqual(new[] { "Boredom", "Dread" }, Names(query.Filtered(Cards())));
        }

        [Test]
        public void TheSetFilter_StepsThroughEachSet_ThenBackToAll()
        {
            var cards = Cards();
            var query = new CardStatsQuery();
            CollectionAssert.AreEqual(new[] { "BASE", "PROMO" }, CardStatsQuery.SetCodes(cards));

            query.SetCode = query.NextSetCode(cards);
            Assert.AreEqual("BASE", query.SetCode);
            CollectionAssert.AreEqual(new[] { "Anger", "Calm" }, Names(query.Filtered(cards)));

            query.SetCode = query.NextSetCode(cards);
            Assert.AreEqual("PROMO", query.SetCode);

            query.SetCode = query.NextSetCode(cards);
            Assert.AreEqual(string.Empty, query.SetCode);
            Assert.AreEqual(4, query.Filtered(cards).Count);
        }

        [Test]
        public void Paging_ShowsAPageAtATime_AndHoldsThePageInRange()
        {
            var many = Enumerable.Range(1, 95).Select(i => new CardStat { CatalogCardId = i, Name = "Card " + i.ToString("000") }).ToList();
            var query = new CardStatsQuery();
            var filtered = query.Filtered(many);

            Assert.AreEqual(3, query.PageCount(filtered.Count));
            Assert.AreEqual(CardStatsQuery.PageSize, query.OnPage(filtered).Count);
            Assert.AreEqual("Page 1 of 3 (95 cards)", query.PageText(filtered.Count));
            Assert.IsFalse(query.HasPrevious);

            query.NextPage();
            query.NextPage();
            Assert.AreEqual(95 - 2 * CardStatsQuery.PageSize, query.OnPage(filtered).Count);
            Assert.IsFalse(query.HasNext(filtered.Count));

            query.NextPage();
            query.OnPage(filtered);
            Assert.AreEqual(3, query.Page, "never past the last page");

            query.Search = "Card 01";
            Assert.AreEqual(1, query.Page, "a new search starts from the top");
            Assert.AreEqual("Page 1 of 1 (10 cards)", query.PageText(query.Filtered(many).Count));
        }

        [Test]
        public void TheCardStats_AreFetchedFromTheServer_AndForgottenOnLogout()
        {
            var transport = new FakeHttpTransport();
            var flow = new StatsFlow(new ApiClient(new ApiConfig("https://example.test"), transport), new DeviceSettings(new InMemoryKeyValueStore()));
            transport.Enqueue(200, Json);

            Assert.IsTrue(flow.RefreshCardStatsAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/stats/cards", transport.LastRequest.Url);
            Assert.IsTrue(flow.CardStatsLoaded);
            Assert.AreEqual(4, flow.CardStats.Count);

            flow.Clear();
            Assert.IsFalse(flow.CardStatsLoaded);
            Assert.AreEqual(0, flow.CardStats.Count);
        }
    }
}
