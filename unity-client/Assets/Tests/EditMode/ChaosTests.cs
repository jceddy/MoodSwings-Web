using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class ChaosTests
    {
        private const string TwoEffects =
            "\"effect_1\":{\"id\":11,\"rarity\":\"rare\",\"shape\":\"x\",\"rules_text\":\"Draw a card.\"}," +
            "\"effect_2\":{\"id\":12,\"rarity\":\"common\",\"shape\":\"y\",\"rules_text\":\"Gain a point.\"}";

        private static ChaosOfferInfo Info(string offerJson, bool roundReady = true) =>
            JsonConvert.DeserializeObject<ChaosOfferInfo>(
                "{\"status\":\"ok\",\"offer\":" + offerJson + ",\"round_ready\":" + (roundReady ? "true" : "false") + "}");

        private static string Offer(bool team = false, string phase = "choose", int? proposer = null) =>
            "{" + TwoEffects + ",\"is_team_offer\":" + (team ? "true" : "false") + ",\"phase\":\"" + phase + "\",\"proposer_game_player_id\":" +
            (proposer.HasValue ? proposer.ToString() : "null") + "}";

        private static GameState ChaosGame(ChaosOfferInfo info)
        {
            var state = BoardFixtures.Load(405);
            state.Game.DeckType = "chaos_draft";
            state.Round.PendingDecision = null;
            state.ChaosOffer = info;
            return state;
        }

        [Test]
        public void AnOffer_IsRead()
        {
            var info = Info(Offer(team: true, phase: "confirm", proposer: 913), roundReady: false);

            Assert.AreEqual(11, info.Offer.Effect1.Id);
            Assert.AreEqual("common", info.Offer.Effect2.Rarity);
            Assert.IsTrue(info.Offer.IsAwaitingConfirmation);
            Assert.AreEqual(913, info.Offer.ProposerGamePlayerId);
            Assert.IsFalse(info.RoundReady);
        }

        [Test]
        public void NoOffer_MeansThereIsNothingToChoose()
        {
            var info = Info("null");

            Assert.IsNull(info.Offer);
            Assert.IsTrue(info.RoundReady);
        }

        [Test]
        public void ACardWithAnEffect_CarriesItsRarityAndValueChange()
        {
            var card = JsonConvert.DeserializeObject<BoardCard>(
                "{\"card_id\":5,\"name\":\"Calm\",\"chaos_effect\":{\"id\":3,\"rarity\":\"mythic\",\"rules_text\":\"Does it.\"},\"chaos_value_delta\":2,\"chaos_value_override\":null}");

            Assert.AreEqual("mythic", card.ChaosEffect.Rarity);
            Assert.AreEqual("Mythic Chaos effect: Does it.", ChaosDisplay.EffectOnCard(card.ChaosEffect));
            Assert.AreEqual("(chaos: +2 to the value)", ChaosDisplay.ValueNote(card));
            Assert.IsNull(JsonConvert.DeserializeObject<BoardCard>("{\"card_id\":6}").ChaosEffect);
        }

        [Test]
        public void AValueFixedByAnEffect_IsSaidSo_AndLowersAreSigned()
        {
            Assert.AreEqual("(chaos: value fixed at 4)", ChaosDisplay.ValueNote(new BoardCard { ChaosValueOverride = 4, ChaosValueDelta = 9 }));
            Assert.AreEqual("(chaos: -1 to the value)", ChaosDisplay.ValueNote(new BoardCard { ChaosValueDelta = -1 }));
            Assert.IsNull(ChaosDisplay.ValueNote(new BoardCard()));
        }

        [Test]
        public void ChoosingBetweenEffects_ReadsWithTheirRarity()
        {
            var offer = Info(Offer()).Offer;

            Assert.AreEqual("Rare  -  Draw a card.", ChaosDisplay.EffectSummary(offer.Effect1));
            Assert.AreEqual("Common  -  Gain a point.", ChaosDisplay.EffectSummary(offer.Effect2));
        }

        [Test]
        public void AnOpenOffer_HoldsPlay_AndTheBannerAsksForTheChoice()
        {
            var state = ChaosGame(Info(Offer()));

            Assert.IsTrue(BoardDisplay.ChaosHoldsPlay(state));
            Assert.IsTrue(BoardDisplay.NeedsChaosChoice(state));
            Assert.IsFalse(BoardDisplay.CanAct(state));
            Assert.AreEqual("Choose a Chaos effect", BoardDisplay.TurnBanner(state));
            Assert.IsTrue(BoardDisplay.BannerNeedsViewer(state));
        }

        [Test]
        public void WhenYouAreDone_ButSomeoneElseIsNot_PlayWaitsForThem()
        {
            var state = ChaosGame(Info("null", roundReady: false));

            Assert.IsTrue(BoardDisplay.ChaosHoldsPlay(state));
            Assert.IsFalse(BoardDisplay.NeedsChaosChoice(state));
            Assert.IsFalse(BoardDisplay.CanAct(state));
            Assert.AreEqual("Waiting for everyone to choose their Chaos effect", BoardDisplay.TurnBanner(state));
        }

        [Test]
        public void OnceEveryoneHasChosen_PlayIsOpenAgain()
        {
            var state = ChaosGame(Info("null"));

            Assert.IsFalse(BoardDisplay.ChaosHoldsPlay(state));
            Assert.AreNotEqual("Waiting for everyone to choose their Chaos effect", BoardDisplay.TurnBanner(state));
            Assert.AreEqual(BoardDisplay.CanAct(BoardFixtures.Load(405)), BoardDisplay.CanAct(state));
        }

        [Test]
        public void AGameThatIsNotChaos_IsNeverHeldUp()
        {
            var state = BoardFixtures.Load(405);

            Assert.IsNull(state.ChaosOffer);
            Assert.IsFalse(BoardDisplay.ChaosHoldsPlay(state));
            Assert.IsFalse(BoardDisplay.NeedsChaosChoice(state));
        }

        [Test]
        public void ATeamsProposal_WaitsForThePartner_OrAsksYouToAgree()
        {
            var mine = ChaosGame(Info(Offer(team: true, phase: "confirm", proposer: BoardFixtures.Load(405).You.GamePlayerId)));
            Assert.IsFalse(BoardDisplay.NeedsChaosChoice(mine), "the proposer only waits");
            Assert.AreEqual("Waiting for your partner to confirm your Chaos effect", BoardDisplay.TurnBanner(mine));
            Assert.IsTrue(BoardDisplay.ChaosHoldsPlay(mine));

            var theirs = ChaosGame(Info(Offer(team: true, phase: "confirm", proposer: 12345)));
            Assert.IsTrue(BoardDisplay.NeedsChaosChoice(theirs));
            Assert.AreEqual("Your partner proposed a Chaos effect", BoardDisplay.TurnBanner(theirs));
        }

        [Test]
        public void ATeamOffer_CanGoOnYourPartnersCardsToo()
        {
            var state = ChaosGame(Info(Offer(team: true)));
            state.You.Hand = new System.Collections.Generic.List<BoardCard> { new BoardCard { CardId = 1, Name = "Mine" } };
            state.You.TeammateHand = new System.Collections.Generic.List<BoardCard> { new BoardCard { CardId = 2, Name = "Theirs" } };
            state.You.TeammateGamePlayerId = state.Players.First(p => p.GamePlayerId != state.You.GamePlayerId).GamePlayerId;

            var cards = ChaosDisplay.AttachableCards(state);

            Assert.AreEqual(2, cards.Count);
            Assert.IsNull(cards[0].Value);
            Assert.IsNotNull(cards[1].Value, "named, so the player is asked first");
        }

        [Test]
        public void ASoloOffer_IsOnlyForYourOwnHand()
        {
            var state = ChaosGame(Info(Offer()));
            state.You.TeammateHand = new System.Collections.Generic.List<BoardCard> { new BoardCard { CardId = 2 } };

            Assert.AreEqual(state.You.Hand.Count, ChaosDisplay.AttachableCards(state).Count);
        }

        [Test]
        public void ALoopShortcut_IsRead()
        {
            var state = JsonConvert.DeserializeObject<GameState>(
                "{\"game\":{\"chaos_loop_shortcut\":{\"game_player_id\":7,\"kind\":\"spawn\",\"cap\":25,\"label\":\"Spawn up to 25 tokens?\"}}}");

            Assert.AreEqual(25, state.Game.ChaosLoopShortcut.Cap);
            Assert.AreEqual("spawn", state.Game.ChaosLoopShortcut.Kind);
        }

        // --- the session ---------------------------------------------------------------------------

        private FakeHttpTransport _transport;
        private ApiClient _api;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _api = new ApiClient(new ApiConfig("https://example.test"), _transport);
        }

        private static string ChaosStateJson(string status = "in_progress", string deckType = "chaos_draft")
        {
            var state = JObject.Parse(TestFixtures.Read("game_405_state"));
            state["game"]["deck_type"] = deckType;
            state["game"]["status"] = status;
            return state.ToString();
        }

        [Test]
        public void ARefreshOfAChaosGame_AlsoAsksForTheOffer_AndAttachesIt()
        {
            _transport.Enqueue(200, ChaosStateJson());
            _transport.Enqueue(200, "{\"status\":\"ok\",\"offer\":" + Offer() + ",\"round_ready\":false}");
            var session = BoardSession.ForPlayer(_api, 405);

            Assert.IsTrue(session.RefreshAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/games/chaos-draft-offer?game_id=405", _transport.LastRequest.Url);
            Assert.AreEqual(11, session.State.ChaosOffer.Offer.Effect1.Id);
            Assert.IsTrue(BoardDisplay.ChaosHoldsPlay(session.State));
        }

        [Test]
        public void ARefreshOfAnyOtherGame_NeverAsksForAnOffer()
        {
            _transport.Enqueue(200, ChaosStateJson(deckType: "structure"));

            BoardSession.ForPlayer(_api, 405).RefreshAsync().GetAwaiter().GetResult();

            Assert.AreEqual(1, _transport.Requests.Count);
        }

        [Test]
        public void AGameThatIsNotUnderWay_IsNotAskedForAnOffer()
        {
            _transport.Enqueue(200, ChaosStateJson(status: "waiting"));

            BoardSession.ForPlayer(_api, 405).RefreshAsync().GetAwaiter().GetResult();

            Assert.AreEqual(1, _transport.Requests.Count);
        }

        [Test]
        public void ASpectator_IsNotAskedForAnOffer()
        {
            _transport.Enqueue(200, ChaosStateJson());

            BoardSession.ForSpectator(_api, 405).RefreshAsync().GetAwaiter().GetResult();

            Assert.AreEqual(1, _transport.Requests.Count);
        }

        [Test]
        public void AFailedOfferRead_KeepsTheLastAnswer_RatherThanGuessing()
        {
            _transport.Enqueue(200, ChaosStateJson());
            _transport.Enqueue(200, "{\"status\":\"ok\",\"offer\":" + Offer() + ",\"round_ready\":true}");
            _transport.Enqueue(200, ChaosStateJson());
            _transport.EnqueueNetworkError("offline");
            var session = BoardSession.ForPlayer(_api, 405);

            session.RefreshAsync().GetAwaiter().GetResult();
            session.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsNotNull(session.State.ChaosOffer.Offer, "still held up until the server says otherwise");
        }

        [Test]
        public void TheEffectIsAttached_ByChoosingOrProposing()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, ChaosStateJson());
            _transport.Enqueue(200, "{\"status\":\"ok\",\"offer\":null,\"round_ready\":true}");
            var session = BoardSession.ForPlayer(_api, 405);

            session.ChooseChaosEffectAsync(11, 77, team: false).GetAwaiter().GetResult();

            var post = JObject.Parse(_transport.Requests[0].Body);
            Assert.AreEqual("https://example.test/app/games/chaos-draft-effect", _transport.Requests[0].Url);
            Assert.AreEqual("choose", (string)post["action"]);
            Assert.AreEqual(11, (int)post["chosen_effect_id"]);
            Assert.AreEqual(77, (int)post["attach_game_card_id"]);
            Assert.AreEqual(405, (int)post["game_id"]);
        }

        [Test]
        public void ATeam_ProposesAndItsPartnerConfirms()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, ChaosStateJson());
            _transport.Enqueue(200, "{\"status\":\"ok\",\"offer\":null,\"round_ready\":true}");
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, ChaosStateJson());
            _transport.Enqueue(200, "{\"status\":\"ok\",\"offer\":null,\"round_ready\":true}");
            var session = BoardSession.ForPlayer(_api, 405);

            session.ChooseChaosEffectAsync(12, 3, team: true).GetAwaiter().GetResult();
            session.ConfirmChaosEffectAsync(false).GetAwaiter().GetResult();

            Assert.AreEqual("propose", (string)JObject.Parse(_transport.Requests[0].Body)["action"]);
            var confirm = JObject.Parse(_transport.Requests[3].Body);
            Assert.AreEqual("confirm", (string)confirm["action"]);
            Assert.IsFalse((bool)confirm["approve"]);
        }

        [Test]
        public void TheLoopShortcut_IsAppliedACountOfTimes()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, ChaosStateJson());
            _transport.Enqueue(200, "{\"status\":\"ok\",\"offer\":null,\"round_ready\":true}");

            BoardSession.ForPlayer(_api, 405).ApplyChaosLoopShortcutAsync(12).GetAwaiter().GetResult();

            Assert.AreEqual("https://example.test/app/games/apply-chaos-loop-shortcut", _transport.Requests[0].Url);
            Assert.AreEqual(12, (int)JObject.Parse(_transport.Requests[0].Body)["count"]);
        }

        // --- setting one up ------------------------------------------------------------------------

        [Test]
        public void ChaosDraft_IsOnlyOfferedToPlayersWhoSwitchedOnCustomFormats()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat };

            CollectionAssert.DoesNotContain(setup.DecksForFormat.Select(d => d.Id).ToList(), "chaos_draft");

            setup.AllowCustomContent = true;
            CollectionAssert.Contains(setup.DecksForFormat.Select(d => d.Id).ToList(), "chaos_draft");
            CollectionAssert.Contains(new GameSetup { Format = GameSetup.OpenTeamFormat, AllowCustomContent = true }.DecksForFormat.Select(d => d.Id).ToList(), "chaos_draft");
        }

        [Test]
        public void AChaosDraft_NamesItsPoolLikeQuickDraft_AndIsAMatchOfItsOwn()
        {
            var setup = new GameSetup
            {
                Format = GameSetup.DraftFormat, DeckType = "chaos_draft", AllowCustomContent = true, PoolSource = "jceddys_75", BestOfThree = true, OpponentUserIds = { 18 },
            };
            setup.Normalize(false);

            var body = setup.ToDirectGameBody();
            Assert.AreEqual("chaos_draft", body["deck_type"]);
            Assert.AreEqual("jceddys_75", body["quick_draft_pool_source"]);
            Assert.IsFalse(body.ContainsKey("chaos_draft_pool_source"));
            Assert.IsFalse(setup.BestOfThree);
        }

        [Test]
        public void SwitchingTheCustomFormatsOff_DropsAChaosDeckFromTheSetup()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, DeckType = "chaos_draft", AllowCustomContent = false, OpponentUserIds = { 18 } };
            setup.Normalize(false);

            Assert.AreEqual("quick_draft", setup.DeckType);
        }

        [Test]
        public void ChaosDraft_DraftsLikeQuickDraft()
        {
            Assert.AreEqual("quick_draft", DraftDisplay.Kind("chaos_draft"));
            Assert.AreEqual("winston_draft", DraftDisplay.Kind("winston_draft"));
            Assert.IsTrue(DraftDisplay.Implemented.Contains("chaos_draft"));
        }
    }
}
