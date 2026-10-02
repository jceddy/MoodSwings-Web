using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>
    /// The choices a card asks for: which options are legal, what's acceptable,
    /// and the body that gets sent. The pending decisions and hand cards of the
    /// real captured games are used where they exist; the rest are fields written
    /// the way the server sends them, on a small table made for the purpose.
    /// </summary>
    public class ChoiceFormTests
    {
        private const int Me = 1;
        private const int Ann = 2;
        private const int Bo = 3;

        private static ChoiceField Field(string json) => JsonConvert.DeserializeObject<ChoiceField>(json);

        private static BoardCard Mood(int id, string color, int value, int owner, string name = null) =>
            new BoardCard { CardId = id, Name = name ?? "Mood" + id, Color = color, Value = value, BaseValue = value, OwnerGamePlayerId = owner };

        /// <summary>Three players; Me holds moods 10 (red 2) and 11 (blue 5), Ann 20 (red 4) and 21 (green 7), Bo 30 (blue 3).</summary>
        private static GameState Table()
        {
            var state = new GameState
            {
                Players = new List<BoardPlayer>
                {
                    new BoardPlayer { GamePlayerId = Me, SeatOrder = 0, Username = "Me", HandCount = 3 },
                    new BoardPlayer { GamePlayerId = Ann, SeatOrder = 1, Username = "Ann", HandCount = 0 },
                    new BoardPlayer { GamePlayerId = Bo, SeatOrder = 2, Username = "Bo", HandCount = 2 },
                },
                InPlay = new List<BoardCard>
                {
                    Mood(10, "red", 2, Me),
                    Mood(11, "blue", 5, Me),
                    Mood(20, "red", 4, Ann),
                    Mood(21, "green", 7, Ann),
                    Mood(30, "blue", 3, Bo),
                },
            };
            state.You.GamePlayerId = Me;
            state.You.Hand = new List<BoardCard>
            {
                Mood(100, "white", 0, Me, "Calm"),
                Mood(101, "black", 3, Me, "Dread"),
                Mood(102, "green", 6, Me, "Hope"),
            };
            return state;
        }

        private static ChoiceForm FormFor(GameState state, params ChoiceField[] fields) =>
            ChoiceForm.ForCard(state, new BoardCard
            {
                CardId = 999,
                Name = "Test Card",
                IsPlayable = true,
                ChoiceFields = fields.ToList(),
            });

        private static List<string> Ids(ChoiceForm form, ChoiceField field) =>
            form.OptionsFor(field).Select(o => o.Id).ToList();

        // --- pending decisions in the real games --------------------------------------------

        [Test]
        public void FuryWaitingOnYou_HasOneLegalMood_SoItIsAlreadyChosen()
        {
            var state = BoardFixtures.Load(405);
            var decision = state.Round.PendingDecision;
            Assert.IsTrue(decision.IsYou);

            var form = ChoiceForm.ForDecision(state, decision);

            Assert.AreEqual("discarded_mood_id_907", form.Fields.Single().Key);
            Assert.AreEqual(new[] { "14128" }, Ids(form, decision.Field));
            Assert.IsTrue(form.CanSubmit, "nothing left to pick");
            Assert.AreEqual(14128, (int)form.BuildChoices()["discarded_mood_id_907"]);
        }

        [Test]
        public void ConfusionWaitingOnYou_OffersYourHand_AndNeedsAnAnswer()
        {
            var state = BoardFixtures.Load(407);
            var decision = state.Round.PendingDecision;
            var form = ChoiceForm.ForDecision(state, decision);

            Assert.AreEqual(4, form.OptionsFor(decision.Field).Count);
            Assert.IsFalse(form.CanSubmit, "it's required, and four cards are on offer");

            var first = form.OptionsFor(decision.Field)[0];
            form.Select(decision.Field.Key, first.Id);

            Assert.IsTrue(form.CanSubmit);
            Assert.AreEqual(int.Parse(first.Id), (int)form.BuildChoices()["given_card_id_912"]);
        }

        [Test]
        public void ADecision_IsNeverBlockedByTheCardThatCausedIt_NotBeingPlayable()
        {
            // Duplicity's repeat offer is about a mood already in play, which isn't "playable".
            var state = Table();
            var decision = new PendingDecision
            {
                DecisionType = "duplicity_repeat_offer",
                IsYou = true,
                PlayedCardId = 10,
                Field = Field(@"{""key"":""duplicity_repeat"",""type"":""nested"",""required"":false,""label"":""Repeat?"",
                    ""fields"":[{""key"":""repeat"",""type"":""bool"",""label"":""Repeat it""},
                                {""key"":""choices"",""type"":""nested"",""label"":""Its choices"",""fields"":[
                                    {""key"":""target_mood_id"",""type"":""mood"",""scope"":""any"",""required"":false,""label"":""Target""}]}]}"),
            };

            var form = ChoiceForm.ForDecision(state, decision);

            Assert.IsNull(form.Problem);
            Assert.IsTrue(form.CanSubmit);
            Assert.AreEqual("{}", form.BuildChoices().ToString(Formatting.None), "declining sends nothing");

            form.SetChecked("duplicity_repeat.repeat", true);
            form.Select("duplicity_repeat.choices.target_mood_id", "20");

            var choices = form.BuildChoices();
            Assert.IsTrue((bool)choices["duplicity_repeat"]["repeat"]);
            Assert.AreEqual(20, (int)choices["duplicity_repeat"]["choices"]["target_mood_id"]);
        }

        [Test]
        public void ARealCard_ThatIsntPlayableYet_SaysSo()
        {
            var neurosis = BoardFixtures.Load(405).You.Hand.Single(c => c.Name == "Neurosis");
            Assert.IsFalse(neurosis.IsPlayable);

            var form = ChoiceForm.ForCard(BoardFixtures.Load(405), neurosis);

            StringAssert.Contains("can't be played", form.Problem);
            Assert.IsFalse(form.CanSubmit);
        }

        [Test]
        public void RealCorruption_AsksForAModeAndUpToTwoDiscardPileCards()
        {
            var state = BoardFixtures.Load(406);
            var corruption = state.You.Hand.Single(c => c.Name == "Corruption");
            var form = ChoiceForm.ForCard(state, corruption);

            Assert.AreEqual(new[] { "mode", "discard_card_ids" }, form.Fields.Select(f => f.Key).ToArray());
            Assert.IsTrue(form.CanSubmit, "everything on it is optional");

            form.Select("mode", "cycle");
            var discards = form.OptionsFor(form.Fields[1]);
            Assert.AreEqual(state.DiscardPile.Count, discards.Count);
            foreach (var option in discards.Take(3))
            {
                form.Toggle("discard_card_ids", option.Id);
            }

            if (discards.Count > 2)
            {
                StringAssert.Contains("at most 2", form.Problem);
            }
        }

        // --- options -----------------------------------------------------------------------

        [Test]
        public void MoodOptions_FollowScope_AndAreGroupedByOwner()
        {
            var state = Table();
            var own = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""own"",""label"":""x""}");
            var other = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""other"",""label"":""x""}");
            var any = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x""}");
            var form = FormFor(state, own);

            Assert.AreEqual(new[] { "10", "11" }, Ids(form, own));
            Assert.AreEqual(new[] { "20", "21", "30" }, Ids(form, other));
            Assert.AreEqual(5, Ids(form, any).Count);
            Assert.AreEqual(new[] { "Ann", "Ann", "Bo" }, form.OptionsFor(other).Select(o => o.Group).ToArray());
            Assert.AreEqual("Mood20 (red, 4)", form.OptionsFor(other)[0].Label);
        }

        [Test]
        public void MoodFilters_NarrowByColorParityAndValue()
        {
            var state = Table();
            var colors = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x"",""filter"":{""colors"":[""red"",""green""]}}");
            var odd = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x"",""filter"":{""parity"":""odd""}}");
            var even = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x"",""filter"":{""parity"":""even""}}");
            var big = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x"",""filter"":{""min_value"":5}}");
            var small = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x"",""filter"":{""max_value"":3}}");
            var form = FormFor(state, colors);

            Assert.AreEqual(new[] { "10", "20", "21" }, Ids(form, colors));
            Assert.AreEqual(new[] { "11", "21", "30" }, Ids(form, odd));
            Assert.AreEqual(new[] { "10", "20" }, Ids(form, even));
            Assert.AreEqual(new[] { "11", "21" }, Ids(form, big));
            Assert.AreEqual(new[] { "10", "30" }, Ids(form, small));
        }

        [Test]
        public void ServerCandidateList_WinsOverScopeAndFilter()
        {
            var state = Table();
            var field = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""own"",""label"":""x"",""candidate_card_ids"":[21,30]}");

            Assert.AreEqual(new[] { "21", "30" }, Ids(FormFor(state, field), field));
        }

        [Test]
        public void ACardThatMayNameItself_IsOfferedAsSelfFirst_OthersDontGetIt()
        {
            var state = Table();
            var withSelf = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x"",""includes_self"":true}");
            var without = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x""}");

            var options = FormFor(state, withSelf).OptionsFor(withSelf);
            StringAssert.EndsWith("[self]", options[0].Label);
            Assert.AreEqual("999", options[0].Id);
            Assert.AreEqual("Me", options[0].Group);
            Assert.AreEqual(6, options.Count);

            Assert.AreEqual(5, FormFor(state, without).OptionsFor(without).Count);
        }

        [Test]
        public void PlayerOptions_DropYouWhenOther_Resigned_Teammate_AndThoseWithoutEnough()
        {
            var state = Table();
            state.You.TeammateGamePlayerId = Bo;
            var any = Field(@"{""key"":""p"",""type"":""player"",""scope"":""any"",""label"":""x""}");
            var other = Field(@"{""key"":""p"",""type"":""player"",""scope"":""other"",""label"":""x""}");
            var opponent = Field(@"{""key"":""p"",""type"":""player"",""scope"":""other"",""excludes_teammate"":true,""label"":""x""}");
            var hasCards = Field(@"{""key"":""p"",""type"":""player"",""scope"":""any"",""label"":""x"",""filter"":{""min_hand_count"":1}}");
            var twoMoods = Field(@"{""key"":""p"",""type"":""player"",""scope"":""any"",""label"":""x"",""filter"":{""min_mood_count"":2}}");
            var form = FormFor(state, any);

            Assert.AreEqual(new[] { "1", "2", "3" }, Ids(form, any));
            Assert.AreEqual(new[] { "2", "3" }, Ids(form, other));
            Assert.AreEqual(new[] { "2" }, Ids(form, opponent));
            Assert.AreEqual(new[] { "1", "3" }, Ids(form, hasCards), "Ann holds no cards");
            Assert.AreEqual(new[] { "1", "2" }, Ids(form, twoMoods), "Bo has one mood");

            state.Players[1].Resigned = true;
            Assert.AreEqual(new[] { "3" }, Ids(form, other));
        }

        [Test]
        public void HandCardOptions_LeaveOutTheCardBeingPlayed_AndFollowTheFilter()
        {
            var state = Table();
            state.You.Hand.Add(new BoardCard { CardId = 999, Name = "Test Card", Color = "red", Value = 2 });
            var any = Field(@"{""key"":""c"",""type"":""hand_card"",""label"":""x""}");
            var values = Field(@"{""key"":""c"",""type"":""hand_card"",""label"":""x"",""filter"":{""values"":[0,6]}}");
            var colors = Field(@"{""key"":""c"",""type"":""hand_card"",""label"":""x"",""filter"":{""colors"":[""black""]}}");
            var form = FormFor(state, any);

            Assert.AreEqual(new[] { "100", "101", "102" }, Ids(form, any));
            Assert.AreEqual(new[] { "100", "102" }, Ids(form, values));
            Assert.AreEqual(new[] { "101" }, Ids(form, colors));
        }

        [Test]
        public void DiscardPileOptions_NameWhoseCardItWas()
        {
            var state = Table();
            state.DiscardPile.Add(new BoardCard { CardId = 50, Name = "Gone", Color = "blue", Value = 1, LastOwnerName = "Ann" });
            var field = Field(@"{""key"":""d"",""type"":""discard_card"",""label"":""x""}");

            Assert.AreEqual("Gone (blue, 1) - Ann", FormFor(state, field).OptionsFor(field).Single().Label);
        }

        [Test]
        public void ModeOptions_AreCapitalized_AndADirectionNamesWhoIsThere()
        {
            var threePlayers = Table();
            var direction = Field(@"{""key"":""direction"",""type"":""mode"",""required"":true,""label"":""x"",""options"":[""left"",""right""]}");
            var plain = Field(@"{""key"":""mode"",""type"":""mode"",""label"":""x"",""options"":[""double_win"",""cycle""]}");

            var form = FormFor(threePlayers, direction, plain);

            // Left is the next seat in turn order, right the previous one.
            Assert.AreEqual(new[] { "Left (Ann)", "Right (Bo)" }, form.OptionsFor(direction).Select(o => o.Label).ToArray());
            Assert.AreEqual(new[] { "Double win", "Cycle" }, form.OptionsFor(plain).Select(o => o.Label).ToArray());

            // With two players, both sides are the same person, so naming them says nothing.
            var twoPlayers = Table();
            twoPlayers.Players.RemoveAt(2);
            Assert.AreEqual(new[] { "Left", "Right" }, FormFor(twoPlayers, direction).OptionsFor(direction).Select(o => o.Label).ToArray());
        }

        [Test]
        public void ValueOptions_CoverTheRange_PlusAnyExtraValuesInPlay()
        {
            var state = Table();
            var field = Field(@"{""key"":""value"",""type"":""value"",""min"":0,""max"":3,""extra_values"":[9],""label"":""x""}");

            Assert.AreEqual(new[] { "0", "1", "2", "3", "9" }, Ids(FormFor(state, field), field));
        }

        [Test]
        public void GrantChoice_ListsTheServersOwnOptions()
        {
            var state = Table();
            var field = Field(@"{""key"":""grant_source_card_id"",""type"":""grant_choice"",""label"":""Which play"",
                ""options"":[{""value"":0,""label"":""The base play""},{""value"":77,""label"":""An extra play from Hope""}]}");
            var form = FormFor(state, field);

            Assert.AreEqual(new[] { "0", "77" }, Ids(form, field));
            form.Select("grant_source_card_id", "77");
            Assert.AreEqual(77, (int)form.BuildChoices()["grant_source_card_id"]);
        }

        // --- picking and defaults -------------------------------------------------------------

        [Test]
        public void ARequiredFieldWithOneOption_IsFilledInAlready()
        {
            var state = Table();
            var field = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""other"",""required"":true,""label"":""x"",""filter"":{""colors"":[""green""]}}");
            var form = FormFor(state, field);

            Assert.IsTrue(form.IsSelected("m", "21"));
            Assert.IsTrue(form.RequiredFilled);
        }

        [Test]
        public void ARequiredFieldWithSeveralOptions_StaysEmptyUntilPicked()
        {
            var state = Table();
            var field = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""other"",""required"":true,""label"":""x""}");
            var form = FormFor(state, field);

            Assert.IsFalse(form.RequiredFilled);
            Assert.IsFalse(form.CanSubmit);
            form.Select("m", "20");
            Assert.IsTrue(form.CanSubmit);
        }

        [Test]
        public void PickingTheChosenOptionAgain_ClearsIt()
        {
            var state = Table();
            var field = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x""}");
            var form = FormFor(state, field);

            form.Select("m", "20");
            form.Select("m", "21");
            Assert.AreEqual(new[] { "21" }, form.Selected("m").ToArray(), "one at a time");

            form.Select("m", "21");
            Assert.AreEqual(0, form.Selected("m").Count);
            Assert.AreEqual("{}", form.BuildChoices().ToString(Formatting.None));
        }

        [Test]
        public void TheServersSuggestedMode_IsPreselected_ButCanBeChanged()
        {
            var state = Table();
            var field = Field(@"{""key"":""color"",""type"":""mode"",""required"":true,""label"":""x"",""default"":""blue"",""options"":[""white"",""blue"",""red""]}");
            var form = FormFor(state, field);

            Assert.IsTrue(form.IsSelected("color", "blue"));
            form.Select("color", "red");
            Assert.AreEqual("red", (string)form.BuildChoices()["color"]);
        }

        [Test]
        public void ARequiredTarget_WithNoLegalTarget_DoesNotBlockThePlay_UnlessItIsNotOptional()
        {
            var state = Table();
            var relaxed = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""own"",""required"":true,""optional_if_no_targets"":true,""label"":""x"",""filter"":{""colors"":[""white""]}}");
            var strict = Field(@"{""key"":""m"",""type"":""mood"",""scope"":""own"",""required"":true,""label"":""x"",""filter"":{""colors"":[""white""]}}");

            Assert.IsTrue(FormFor(state, relaxed).CanSubmit);
            Assert.IsFalse(FormFor(state, strict).CanSubmit);
        }

        // --- counts and constraints -----------------------------------------------------------

        private static ChoiceForm MultiForm(GameState state, string extra, out ChoiceField field)
        {
            field = Field(@"{""key"":""ms"",""type"":""mood"",""scope"":""any"",""multi"":true,""label"":""x""," + extra + "}");
            return FormFor(state, field);
        }

        [Test]
        public void ExactCount_MustBeMet()
        {
            var form = MultiForm(Table(), @"""count"":{""min"":2,""max"":2}", out _);

            form.Toggle("ms", "10");
            Assert.AreEqual("Choose exactly 2", form.Problem);
            form.Toggle("ms", "20");
            Assert.IsNull(form.Problem);
            form.Toggle("ms", "30");
            Assert.AreEqual("Choose exactly 2", form.Problem);
        }

        [Test]
        public void ZeroOkCount_AllowsNone_ButNotJustOne()
        {
            var form = MultiForm(Table(), @"""count"":{""min"":2,""max"":2,""zero_ok"":true}", out _);

            Assert.IsNull(form.Problem);
            form.Toggle("ms", "10");
            Assert.AreEqual("Choose exactly 2", form.Problem);
        }

        [Test]
        public void MinimumAndMaximumCounts_ReadAsAtLeastAndAtMost()
        {
            var atLeast = MultiForm(Table(), @"""required"":true,""count"":{""min"":1}", out _);
            Assert.IsFalse(atLeast.CanSubmit, "a required multi field needs at least one");
            atLeast.Toggle("ms", "10");
            Assert.IsTrue(atLeast.CanSubmit);

            var atMost = MultiForm(Table(), @"""count"":{""max"":1}", out _);
            atMost.Toggle("ms", "10");
            atMost.Toggle("ms", "20");
            Assert.AreEqual("Choose at most 1", atMost.Problem);
        }

        [Test]
        public void TogglingAnOptionTwice_TakesItBackOut()
        {
            var form = MultiForm(Table(), @"""count"":{""max"":2}", out _);

            form.Toggle("ms", "10");
            form.Toggle("ms", "20");
            form.Toggle("ms", "10");

            Assert.AreEqual(new[] { "20" }, form.Selected("ms").ToArray());
        }

        [Test]
        public void DistinctOwners_RefusesTwoMoodsOfOnePlayer()
        {
            var form = MultiForm(Table(), @"""constraint"":{""type"":""distinct_owners""}", out _);

            form.Toggle("ms", "20");
            form.Toggle("ms", "30");
            Assert.IsNull(form.Problem);
            form.Toggle("ms", "21");
            Assert.AreEqual("You can only choose one mood per player", form.Problem);
        }

        [Test]
        public void SameOwner_RefusesMoodsOfTwoPlayers()
        {
            var form = MultiForm(Table(), @"""constraint"":{""type"":""same_owner""}", out _);

            form.Toggle("ms", "20");
            form.Toggle("ms", "21");
            Assert.IsNull(form.Problem);

            form.Toggle("ms", "21");
            form.Toggle("ms", "30");
            Assert.AreEqual("Both moods must belong to the same opponent", form.Problem);
        }

        [Test]
        public void SameColorOrValue_AcceptsEitherMatch_RefusesNeither()
        {
            var form = MultiForm(Table(), @"""constraint"":{""type"":""same_color_or_value""}", out _);

            form.Toggle("ms", "10"); // red 2
            form.Toggle("ms", "20"); // red 4: same color
            Assert.IsNull(form.Problem);

            form.Toggle("ms", "20");
            form.Toggle("ms", "30"); // blue 3: neither
            Assert.AreEqual("The two chosen moods must share a color or have the same value", form.Problem);
        }

        [Test]
        public void MaxTotalValue_AddsUpTheValuesAsTheyWillBe()
        {
            var plain = MultiForm(Table(), @"""constraint"":{""type"":""max_total_value"",""max"":5}", out _);
            plain.Toggle("ms", "10"); // 2
            plain.Toggle("ms", "30"); // 3
            Assert.IsNull(plain.Problem, "2 + 3 is 5");
            plain.Toggle("ms", "20"); // 4
            StringAssert.Contains("cannot exceed 5", plain.Problem);

            // The server may say a mood's value will differ once the card is in play.
            var adjusted = MultiForm(Table(), @"""constraint"":{""type"":""max_total_value"",""max"":5},""candidate_values"":{""10"":1,""30"":1}", out _);
            adjusted.Toggle("ms", "10");
            adjusted.Toggle("ms", "30");
            adjusted.Toggle("ms", "11"); // 5, not in the map
            StringAssert.Contains("cannot exceed 5", adjusted.Problem);
            adjusted.Toggle("ms", "11");
            Assert.IsNull(adjusted.Problem, "1 + 1");
        }

        // --- what's sent ----------------------------------------------------------------------

        [Test]
        public void BuildChoices_UsesTheRightJsonTypeForEachKind()
        {
            var state = Table();
            var form = FormFor(
                state,
                Field(@"{""key"":""m"",""type"":""mood"",""scope"":""any"",""label"":""x""}"),
                Field(@"{""key"":""ms"",""type"":""mood"",""scope"":""any"",""multi"":true,""label"":""x""}"),
                Field(@"{""key"":""mode"",""type"":""mode"",""label"":""x"",""options"":[""single"",""all""]}"),
                Field(@"{""key"":""modes"",""type"":""mode"",""multi"":true,""label"":""x"",""options"":[""a"",""b""]}"),
                Field(@"{""key"":""flag"",""type"":""bool"",""label"":""x""}"),
                Field(@"{""key"":""unchecked"",""type"":""bool"",""label"":""x""}"),
                Field(@"{""key"":""v"",""type"":""value"",""min"":0,""max"":3,""label"":""x""}"),
                Field(@"{""key"":""p"",""type"":""player"",""scope"":""any"",""label"":""x""}"));

            form.Select("m", "11");
            form.Toggle("ms", "10");
            form.Toggle("ms", "20");
            form.Select("mode", "all");
            form.Toggle("modes", "b");
            form.SetChecked("flag", true);
            form.Select("v", "2");
            form.Select("p", "3");

            Assert.AreEqual(
                @"{""m"":11,""ms"":[10,20],""mode"":""all"",""modes"":[""b""],""flag"":true,""v"":2,""p"":3}",
                form.BuildChoices().ToString(Formatting.None));
        }

        [Test]
        public void ACardOrderField_StartsInTheServersOrder_AndCanBeReordered()
        {
            var state = Table();
            var field = Field(@"{""key"":""ordered_card_ids"",""type"":""card_order"",""required"":true,""label"":""x"",
                ""cards"":[{""card_id"":5,""name"":""A""},{""card_id"":6,""name"":""B""},{""card_id"":7,""name"":""C""}]}");
            var form = ChoiceForm.ForDecision(state, new PendingDecision { DecisionType = "after_scoring_order", IsYou = true, Field = field });

            Assert.IsTrue(form.CanSubmit, "an untouched order is a complete answer");
            Assert.AreEqual("[5,6,7]", form.BuildChoices()["ordered_card_ids"].ToString(Formatting.None));

            form.Move("ordered_card_ids", "7", -2);
            Assert.AreEqual("[7,5,6]", form.BuildChoices()["ordered_card_ids"].ToString(Formatting.None));

            form.Move("ordered_card_ids", "7", -1);
            Assert.AreEqual("[7,5,6]", form.BuildChoices()["ordered_card_ids"].ToString(Formatting.None), "can't go past the top");
        }

        // --- the "did you mean that?" questions -----------------------------------------------

        private static ChoiceForm FormForCard(string effectKey, string name, params ChoiceField[] fields) =>
            ChoiceForm.ForCard(Table(), new BoardCard
            {
                CardId = 999,
                Name = name,
                EffectKey = effectKey,
                IsPlayable = true,
                ChoiceFields = fields.ToList(),
            });

        [Test]
        public void ACardWhoseOnlyChoiceIsAnOptionalTarget_AsksBeforeBeingPlayedWithoutOne()
        {
            var form = FormForCard("hate", "Hate", Field(@"{""key"":""target_mood_id"",""type"":""mood"",""scope"":""any"",""required"":false,""label"":""x""}"));

            StringAssert.Contains("haven't selected a target for Hate", form.Confirmations().Single());

            form.Select("target_mood_id", "20");
            Assert.AreEqual(0, form.Confirmations().Count);
        }

        [Test]
        public void ACardWithSeveralChoices_DoesNotAskAboutAnEmptyOne()
        {
            var form = FormForCard("worry", "Worry",
                Field(@"{""key"":""a"",""type"":""mood"",""scope"":""own"",""label"":""x""}"),
                Field(@"{""key"":""b"",""type"":""mood"",""scope"":""any"",""label"":""x""}"));

            Assert.AreEqual(0, form.Confirmations().Count);
        }

        [Test]
        public void WrathAndRage_AskWhenTheirBoxIsLeftUnchecked()
        {
            var wrath = FormForCard("wrath", "Wrath", Field(@"{""key"":""discard_all_other_moods"",""type"":""bool"",""label"":""Put every other mood into the discard pile""}"));
            var rage = FormForCard("rage", "Rage", Field(@"{""key"":""discard_qualifying_moods"",""type"":""bool"",""label"":""Put every small mood into the discard pile""}"));

            StringAssert.Contains("haven't checked \"Put every other mood into the discard pile\"", wrath.Confirmations().Single());
            StringAssert.Contains("Rage's ability won't do anything", rage.Confirmations().Single());

            wrath.SetChecked("discard_all_other_moods", true);
            Assert.AreEqual(0, wrath.Confirmations().Count);
        }

        [Test]
        public void ATargetChosenWithoutTheModeItNeeds_Asks()
        {
            var form = FormForCard("contempt", "Contempt",
                Field(@"{""key"":""mode"",""type"":""mode"",""label"":""x"",""options"":[""single"",""all""]}"),
                Field(@"{""key"":""target_mood_id"",""type"":""mood"",""scope"":""any"",""label"":""x"",""requires_mode"":""single""}"));

            form.Select("target_mood_id", "20");
            StringAssert.Contains("haven't chosen a mode", form.Confirmations().Single());

            form.Select("mode", "single");
            Assert.AreEqual(0, form.Confirmations().Count);
        }

        // --- Creativity -----------------------------------------------------------------------

        private static BoardCard Creativity()
        {
            return new BoardCard
            {
                CardId = 999,
                Name = "Creativity",
                EffectKey = "creativity",
                IsPlayable = true,
                ChoiceFields = new List<ChoiceField>
                {
                    Field(@"{""key"":""copy_card_id"",""type"":""mood"",""scope"":""any"",""required"":false,""label"":""Copy""}"),
                },
                CopySimulation = new Dictionary<int, CopySimulation>
                {
                    [10] = new CopySimulation
                    {
                        CostPayable = true,
                        ExtraFields = new List<ChoiceField>
                        {
                            Field(@"{""key"":""target_player_id"",""type"":""player"",""scope"":""other"",""required"":true,""label"":""Target""}"),
                        },
                    },
                    [20] = new CopySimulation { CostPayable = false, ExtraFields = new List<ChoiceField>() },
                },
            };
        }

        [Test]
        public void Creativity_TakesOnTheQuestionsOfTheMoodItCopies()
        {
            var form = ChoiceForm.ForCard(Table(), Creativity());
            var changes = 0;
            form.FieldsChanged += () => changes++;

            Assert.AreEqual(new[] { "copy_card_id" }, form.Fields.Select(f => f.Key).ToArray());
            Assert.IsTrue(form.CanSubmit, "uncopied it's just a blue 0");

            form.Select("copy_card_id", "10");

            Assert.AreEqual(1, changes);
            Assert.AreEqual(new[] { "copy_card_id", "target_player_id" }, form.Fields.Select(f => f.Key).ToArray());
            Assert.IsFalse(form.CanSubmit, "the copied card needs a target");

            form.Select("target_player_id", "2");
            Assert.IsTrue(form.CanSubmit);
            Assert.AreEqual(@"{""copy_card_id"":10,""target_player_id"":2}", form.BuildChoices().ToString(Formatting.None));
        }

        [Test]
        public void Creativity_ChangingWhatItCopies_DropsTheOldAnswers()
        {
            var form = ChoiceForm.ForCard(Table(), Creativity());
            form.Select("copy_card_id", "10");
            form.Select("target_player_id", "2");

            form.Select("copy_card_id", "10"); // tap again: no longer copying anything

            Assert.AreEqual(new[] { "copy_card_id" }, form.Fields.Select(f => f.Key).ToArray());
            Assert.AreEqual("{}", form.BuildChoices().ToString(Formatting.None));
        }

        [Test]
        public void Creativity_CopyingAMoodWhoseCostCantBePaid_IsRefused()
        {
            var form = ChoiceForm.ForCard(Table(), Creativity());

            form.Select("copy_card_id", "20");

            StringAssert.Contains("can't be paid", form.Problem);
            Assert.IsFalse(form.CanSubmit);

            form.Select("copy_card_id", "10");
            form.Select("target_player_id", "3");
            Assert.IsNull(form.Problem);
        }
    }
}
