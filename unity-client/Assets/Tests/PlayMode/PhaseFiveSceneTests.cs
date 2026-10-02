using System;
using System.Collections;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.RegularExpressions;
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
    /// <summary>
    /// Plays the real board against a scripted server: opens a hand card's play form,
    /// answers a card's question, passes, resigns, chats, starts a game. The states
    /// are the real captured games (406 is a turn of yours with three playable cards;
    /// 405 and 407 are waiting on you to answer a Fury and a Confusion); a few tests
    /// edit them where the capture doesn't hold the situation (a turn waiting to be
    /// acknowledged, a game about to start). Each test checks what the server was
    /// sent and what's on screen afterwards.
    /// </summary>
    public class PhaseFiveSceneTests
    {
        private sealed class PlayServer
        {
            /// <summary>What GET /games/state answers with; replaced by tests to move the game along.</summary>
            public JObject State;

            public List<string> Calls { get; } = new List<string>();

            /// <summary>Answers a POST under /games/ with this, given its path and body; null means a plain "ok".</summary>
            public Func<string, JObject, HttpResponse> OnPost;

            public IEnumerable<JObject> Posts(string route) =>
                Calls.Where(c => c.StartsWith("POST " + route + " ")).Select(c => JObject.Parse(c.Substring(("POST " + route + " ").Length)));

            public HttpResponse Handle(HttpRequest request)
            {
                var path = request.Url.Substring(request.Url.IndexOf("/app/", StringComparison.Ordinal) + 4);
                Calls.Add($"{request.Method} {path} {request.Body}");

                if (path.StartsWith("/games/state") || path.StartsWith("/games/spectate/state"))
                {
                    return MainSceneTests.Reply(200, State.ToString());
                }

                if (request.Method == "POST" && path.StartsWith("/games/"))
                {
                    var body = string.IsNullOrEmpty(request.Body) ? new JObject() : JObject.Parse(request.Body);
                    return OnPost?.Invoke(path, body) ?? MainSceneTests.Reply(200, "{\"status\":\"ok\"}");
                }

                switch (path)
                {
                    case "/me": return MainSceneTests.Reply(200, Fixture("me"));
                    case "/friends": return MainSceneTests.Reply(200, Fixture("friends"));
                    case "/friends/invites": return MainSceneTests.Reply(200, Fixture("friends_invites"));
                    case "/games": return MainSceneTests.Reply(200, Fixture("games"));
                    case "/games/past": return MainSceneTests.Reply(200, Fixture("games_past"));
                    case "/games/spectatable": return MainSceneTests.Reply(200, Fixture("games"));
                }

                return MainSceneTests.Reply(404, "{\"status\":\"error\"}");
            }
        }

        private static string Fixture(string name) =>
            File.ReadAllText(Path.Combine(Application.dataPath, "Tests", "Fixtures", name + ".json"));

        private static JObject Load(int gameId, Action<JObject> edit = null)
        {
            var state = JObject.Parse(Fixture($"game_{gameId}_state"));
            edit?.Invoke(state);
            return state;
        }

        private static HttpResponse Refuse(string message, int status = 409) =>
            MainSceneTests.Reply(status, "{\"status\":\"error\",\"message\":\"" + message + "\"}");

        /// <summary>Game 406 after you've played a card: it's gone from your hand and the turn is BotSage's.</summary>
        private static JObject AfterPlaying(int cardId) => Load(406, s =>
        {
            var hand = (JArray)s["you"]["hand"];
            hand.Remove(hand.Single(c => (int)c["card_id"] == cardId));
            s["you"]["is_your_turn"] = false;
            s["round"]["current_turn_game_player_id"] = 910;
            s["round"]["plays_remaining"] = 0;
        });

        private static IEnumerator OpenBoard(PlayServer server, int gameId, bool spectate = false)
        {
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            var router = UnityEngine.Object.FindAnyObjectByType<ScreenRouter>();
            var session = spectate
                ? BoardSession.ForSpectator(AppServices.Api, gameId, "CODE1")
                : BoardSession.ForPlayer(AppServices.Api, gameId);
            router.Show<BoardScreen>(session);
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
        }

        private static BoardScreen Board() => MainSceneTests.Screen<BoardScreen>();

        private static Transform Child(string name) =>
            UnityEngine.Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).FirstOrDefault(t => t.name == name);

        private static Button ButtonNamed(string name)
        {
            var found = Child(name);
            return found != null ? found.GetComponent<Button>() : null;
        }

        /// <summary>The visible option button in a choice form whose label starts with this.</summary>
        private static Button Option(string labelStart) =>
            UnityEngine.Object.FindObjectsByType<Button>(FindObjectsInactive.Exclude)
                .FirstOrDefault(b => b.name.StartsWith("Option " + labelStart));

        private static IEnumerator Tap(Button button)
        {
            Assert.IsNotNull(button, "No such button on screen");
            Assert.IsTrue(button.interactable, "The button is greyed out: " + button.name);
            button.onClick.Invoke();
            yield return PhaseTwoSceneTests.Frames();
        }

        private static IEnumerator TapHandCard(string name)
        {
            yield return Tap(Child("Card " + name).GetComponent<Button>());
        }

        private static IEnumerator Submit()
        {
            yield return Tap(ButtonNamed("Submit"));
        }

        private static IEnumerator Poll()
        {
            yield return new WaitForSeconds(3.6f);
            yield return PhaseTwoSceneTests.Frames();
        }

        private static string Primary() => Child("Primary action")?.GetComponentInChildren<Text>().text;

        // --- the actions beside your hand --------------------------------------------------------

        [UnityTest]
        public IEnumerator OnYourTurn_PassAndResignAreOffered()
        {
            var server = new PlayServer { State = Load(406) };
            yield return OpenBoard(server, 406);

            Assert.AreEqual("Pass", Primary());
            Assert.IsTrue(ButtonNamed("Primary action").interactable);
            Assert.IsTrue(ButtonNamed("Resign").interactable);
            ScreenshotHelper.Capture("board-your-turn");
        }

        [UnityTest]
        public IEnumerator OnSomeoneElsesTurn_PassIsGreyedOut_ButResigningIsStillThere()
        {
            var server = new PlayServer { State = Load(405, s => s["round"]["pending_decision"] = null) };
            yield return OpenBoard(server, 405);

            Assert.AreEqual("Pass", Primary());
            Assert.IsFalse(ButtonNamed("Primary action").interactable);
            Assert.IsTrue(ButtonNamed("Resign").interactable);
        }

        [UnityTest]
        public IEnumerator Passing_EndsYourTurn()
        {
            var server = new PlayServer { State = Load(406) };
            server.OnPost = (path, body) =>
            {
                server.State = Load(406, s =>
                {
                    s["you"]["is_your_turn"] = false;
                    s["round"]["current_turn_game_player_id"] = 910;
                });
                return null;
            };
            yield return OpenBoard(server, 406);

            yield return Tap(ButtonNamed("Primary action"));

            Assert.AreEqual(1, server.Posts("/games/pass").Count());
            Assert.AreEqual(406, (int)server.Posts("/games/pass").Single()["game_id"]);
            Assert.AreEqual("BotSage's turn", Board().BannerText);
            Assert.IsFalse(ButtonNamed("Primary action").interactable, "it isn't your turn any more");
        }

        [UnityTest]
        public IEnumerator PassingThatScoresTheRound_SaysSo()
        {
            var server = new PlayServer { State = Load(406) };
            server.OnPost = (path, body) => MainSceneTests.Reply(200, "{\"status\":\"ok\",\"round_scored\":true,\"game_completed\":false}");
            yield return OpenBoard(server, 406);

            yield return Tap(ButtonNamed("Primary action"));

            StringAssert.StartsWith("Round scored", Board().MessageText);
        }

        [UnityTest]
        public IEnumerator APassTheServerRefuses_ShowsItsReason()
        {
            var server = new PlayServer { State = Load(406) };
            server.OnPost = (path, body) => Refuse("It is not your turn.");
            yield return OpenBoard(server, 406);

            yield return Tap(ButtonNamed("Primary action"));

            Assert.AreEqual("It is not your turn.", Board().MessageText);
        }

        [UnityTest]
        public IEnumerator Resigning_AsksFirst_AndNothingIsSentUntilYouSayYes()
        {
            var server = new PlayServer { State = Load(406) };
            yield return OpenBoard(server, 406);

            yield return Tap(ButtonNamed("Resign"));
            Assert.IsTrue(Board().Confirm.IsOpen);
            StringAssert.Contains("cannot be undone", Board().Confirm.MessageText);

            yield return Tap(PhaseTwoSceneTests.FindButton("Keep playing"));
            Assert.IsFalse(Board().Confirm.IsOpen);
            Assert.AreEqual(0, server.Posts("/games/resign").Count());

            yield return Tap(ButtonNamed("Resign"));
            yield return Tap(PhaseTwoSceneTests.FindButton("Resign"));
            Assert.AreEqual(1, server.Posts("/games/resign").Count());
        }

        [UnityTest]
        public IEnumerator WhenYourTurnWaitsToBeAcknowledged_TheButtonSaysAdvanceTurn()
        {
            var server = new PlayServer { State = Load(406, s => s["you"]["turn_pending_acknowledgment"] = true) };
            server.OnPost = (path, body) =>
            {
                server.State = Load(406);
                return null;
            };
            yield return OpenBoard(server, 406);

            Assert.AreEqual("Advance turn", Primary());
            yield return Tap(Child("Card Superiority").GetComponent<Button>());
            Assert.IsFalse(Board().Choices.IsOpen, "no play until the turn is acknowledged");
            Assert.IsTrue(Board().DetailOpen);

            yield return Tap(PhaseTwoSceneTests.FindButton("Advance turn"));

            Assert.AreEqual(1, server.Posts("/games/advance-turn").Count());
            Assert.AreEqual("Pass", Primary());
        }

        [UnityTest]
        public IEnumerator ACompletedGame_OffersNoActions()
        {
            var server = new PlayServer
            {
                State = Load(406, s =>
                {
                    s["game"]["status"] = "completed";
                    s["game"]["winner_usernames"] = new JArray("bshaftoe");
                }),
            };
            yield return OpenBoard(server, 406);

            Assert.AreEqual("You won!", Board().BannerText);
            Assert.IsNull(Child("Primary action"));
            Assert.IsNull(Child("Resign"));
        }

        [UnityTest]
        public IEnumerator APlayThatEndsTheGame_SaysGameComplete()
        {
            var server = new PlayServer { State = Load(406) };
            server.OnPost = (path, body) =>
            {
                server.State = Load(406, s =>
                {
                    s["game"]["status"] = "completed";
                    s["game"]["winner_usernames"] = new JArray("bshaftoe");
                });
                return MainSceneTests.Reply(200, "{\"status\":\"ok\",\"round_scored\":false,\"game_completed\":true}");
            };
            yield return OpenBoard(server, 406);

            yield return Tap(ButtonNamed("Primary action"));

            Assert.AreEqual("Game complete!", Board().MessageText);
            Assert.AreEqual("You won!", Board().BannerText);
        }

        // --- playing a card ---------------------------------------------------------------------

        [UnityTest]
        public IEnumerator ClickingACardInYourHand_OpensItsPlayForm()
        {
            var server = new PlayServer { State = Load(406) };
            yield return OpenBoard(server, 406);

            yield return TapHandCard("Superiority");

            Assert.IsTrue(Board().Choices.IsOpen);
            Assert.AreEqual("Superiority", Board().Choices.TitleText);
            Assert.IsTrue(Board().Choices.SubmitEnabled, "nothing to choose, so it can be played straight away");
            Assert.IsNotNull(Child("Prompt card"), "the card is shown large beside the form");
            ScreenshotHelper.Capture("play-form-no-choices");
        }

        [UnityTest]
        public IEnumerator PlayingACardWithNoChoices_SendsItAndMovesTheGameOn()
        {
            var server = new PlayServer { State = Load(406) };
            server.OnPost = (path, body) =>
            {
                server.State = AfterPlaying(14171);
                return null;
            };
            yield return OpenBoard(server, 406);

            yield return TapHandCard("Superiority");
            yield return Submit();

            var play = server.Posts("/games/play").Single();
            Assert.AreEqual(406, (int)play["game_id"]);
            Assert.AreEqual(14171, (int)play["card_id"]);
            Assert.AreEqual(0, ((JObject)play["choices"]).Count);
            Assert.IsFalse(Board().Choices.IsOpen);
            Assert.AreEqual("BotSage's turn", Board().BannerText);
            Assert.IsNull(Child("Card Superiority"), "it's gone from your hand");
        }

        [UnityTest]
        public IEnumerator ACardWithChoices_ListsThem_AndSendsWhatYouPicked()
        {
            var server = new PlayServer { State = Load(406) };
            server.OnPost = (path, body) =>
            {
                server.State = AfterPlaying(14175);
                return null;
            };
            yield return OpenBoard(server, 406);

            yield return TapHandCard("Corruption");

            Assert.AreEqual("Corruption", Board().Choices.TitleText);
            Assert.IsNotNull(Option("Cycle"));
            Assert.IsNotNull(Option("Double win"));
            Assert.IsNotNull(Option("Confusion"), "the one card in the discard pile can be cycled");
            ScreenshotHelper.Capture("play-form-choices");

            yield return Tap(Option("Cycle"));
            yield return Tap(Option("Confusion"));
            yield return Submit();

            var choices = (JObject)server.Posts("/games/play").Single()["choices"];
            Assert.AreEqual("cycle", (string)choices["mode"]);
            Assert.AreEqual(new[] { 14174 }, choices["discard_card_ids"].Select(t => (int)t).ToArray());
        }

        [UnityTest]
        public IEnumerator PickedOptions_AreMarked_AndTappingAgainTakesThemBack()
        {
            var server = new PlayServer { State = Load(406) };
            yield return OpenBoard(server, 406);
            yield return TapHandCard("Corruption");

            var cycle = Option("Cycle");
            var plain = cycle.GetComponent<Image>().color;
            yield return Tap(cycle);
            Assert.AreNotEqual(plain, cycle.GetComponent<Image>().color, "a picked option looks different");

            yield return Tap(cycle);
            Assert.AreEqual(plain, cycle.GetComponent<Image>().color);
        }

        [UnityTest]
        public IEnumerator ARefusedPlay_KeepsTheFormOpen_WithTheServersReason()
        {
            var server = new PlayServer { State = Load(406) };
            server.OnPost = (path, body) => Refuse("Pick a mood that is in play.", 400);
            yield return OpenBoard(server, 406);

            yield return TapHandCard("Superiority");
            yield return Submit();

            Assert.IsTrue(Board().Choices.IsOpen, "most refusals are a mistake to fix, not a reason to start over");
            Assert.AreEqual("Pick a mood that is in play.", Board().Choices.ProblemText);
            Assert.IsTrue(Board().Choices.SubmitEnabled, "and it can be tried again");
        }

        [UnityTest]
        public IEnumerator ACardThatCantBePlayed_OpensButCantBeSent()
        {
            var server = new PlayServer { State = Load(406, s => s["you"]["hand"][1]["is_playable"] = false) };
            yield return OpenBoard(server, 406);

            Assert.IsNotNull(Child("Card Eagerness").GetComponent<CanvasGroup>(), "it's dimmed in the hand");
            Assert.IsNull(Child("Card Superiority").GetComponent<CanvasGroup>());

            yield return TapHandCard("Eagerness");

            Assert.IsTrue(Board().Choices.IsOpen);
            StringAssert.Contains("can't be played", Board().Choices.ProblemText);
            Assert.IsFalse(Board().Choices.SubmitEnabled);
        }

        [UnityTest]
        public IEnumerator CancellingThePlayForm_SendsNothing()
        {
            var server = new PlayServer { State = Load(406) };
            yield return OpenBoard(server, 406);

            yield return TapHandCard("Superiority");
            yield return Tap(ButtonNamed("Cancel"));

            Assert.IsFalse(Board().Choices.IsOpen);
            Assert.AreEqual(0, server.Posts("/games/play").Count());
        }

        [UnityTest]
        public IEnumerator Back_CallsOffThePlayForm_ButStaysOnTheBoard()
        {
            var server = new PlayServer { State = Load(406) };
            yield return OpenBoard(server, 406);
            yield return TapHandCard("Superiority");

            Assert.IsTrue(Board().HandleBack());

            Assert.IsFalse(Board().Choices.IsOpen);
        }

        [UnityTest]
        public IEnumerator WhenTheTurnMovesOn_AnOpenPlayFormCloses()
        {
            var server = new PlayServer { State = Load(406) };
            yield return OpenBoard(server, 406);
            yield return TapHandCard("Superiority");
            Assert.IsTrue(Board().Choices.IsOpen);

            server.State = Load(406, s =>
            {
                s["you"]["is_your_turn"] = false;
                s["round"]["current_turn_game_player_id"] = 910;
            });
            yield return Poll();

            Assert.IsFalse(Board().Choices.IsOpen, "the turn timed out under you");
            Assert.AreEqual("BotSage's turn", Board().BannerText);
        }

        [UnityTest]
        public IEnumerator WhenItIsNotYourTurn_ACardOnlyShowsItsCloseUp()
        {
            var server = new PlayServer { State = Load(405, s => s["round"]["pending_decision"] = null) };
            yield return OpenBoard(server, 405);

            yield return TapHandCard("Pity");

            Assert.IsFalse(Board().Choices.IsOpen);
            Assert.IsTrue(Board().DetailOpen);
        }

        [UnityTest]
        public IEnumerator ALegalPlayThatWouldDoNothing_AsksFirst()
        {
            // A card whose only choice is an optional target, played without one.
            var server = new PlayServer
            {
                State = Load(406, s =>
                {
                    var card = s["you"]["hand"][0];
                    card["effect_key"] = "hate";
                    card["choice_fields"] = JArray.Parse(
                        "[{\"key\":\"target_mood_id\",\"type\":\"mood\",\"scope\":\"any\",\"required\":false,\"label\":\"Mood to move\"}]");
                }),
            };
            server.OnPost = (path, body) =>
            {
                server.State = AfterPlaying(14171);
                return null;
            };
            yield return OpenBoard(server, 406);

            yield return TapHandCard("Superiority");
            yield return Submit();

            Assert.IsTrue(Board().Confirm.IsOpen);
            StringAssert.Contains("haven't selected a target", Board().Confirm.MessageText);
            Assert.AreEqual(0, server.Posts("/games/play").Count());

            yield return Tap(PhaseTwoSceneTests.FindButton("Cancel"));
            Assert.AreEqual(0, server.Posts("/games/play").Count());
            Assert.IsTrue(Board().Choices.IsOpen, "you're back in the form");

            yield return Submit();
            yield return Tap(PhaseTwoSceneTests.FindButton("Play anyway"));
            Assert.AreEqual(1, server.Posts("/games/play").Count());
        }

        [UnityTest]
        public IEnumerator ACardWithATargetList_GroupsMoodsByOwner()
        {
            var server = new PlayServer
            {
                State = Load(406, s => s["you"]["hand"][0]["choice_fields"] = JArray.Parse(
                    "[{\"key\":\"target_mood_id\",\"type\":\"mood\",\"scope\":\"any\",\"required\":true,\"label\":\"A mood\"}]")),
            };
            yield return OpenBoard(server, 406);

            yield return TapHandCard("Superiority");

            Assert.IsNotNull(Option("Ambition"), "yours");
            Assert.IsNotNull(Option("Frustration"), "BotSageQuick's");
            var texts = UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToList();
            Assert.IsTrue(texts.Contains("bshaftoe") && texts.Contains("BotSageQuick"), "each listed under its owner");
            Assert.IsFalse(Board().Choices.SubmitEnabled, "a required target must be chosen");

            yield return Tap(Option("Frustration"));
            Assert.IsTrue(Board().Choices.SubmitEnabled);
        }

        // --- questions the game has for you -----------------------------------------------------

        [UnityTest]
        public IEnumerator AQuestionForYou_OpensByItself_AndCantBeCalledOff()
        {
            var server = new PlayServer { State = Load(407) };
            yield return OpenBoard(server, 407);

            Assert.IsTrue(Board().Choices.IsOpen);
            Assert.AreEqual("Respond to Confusion", Board().Choices.TitleText);
            Assert.IsFalse(Board().Choices.Cancellable);
            Assert.IsNull(Child("Cancel"), "there's nothing to cancel");
            Assert.IsFalse(Board().Choices.Cancel());
            ScreenshotHelper.Capture("decision-confusion");
        }

        [UnityTest]
        public IEnumerator AnsweringConfusion_ChoosesAHandCardToGive()
        {
            var server = new PlayServer { State = Load(407) };
            server.OnPost = (path, body) =>
            {
                server.State = Load(407, s => s["round"]["pending_decision"] = null);
                return null;
            };
            yield return OpenBoard(server, 407);

            Assert.IsFalse(Board().Choices.SubmitEnabled, "a card has to be chosen");
            foreach (var card in new[] { "Loyalty", "Patience", "Rationalization", "Cheer" })
            {
                Assert.IsNotNull(Option(card), card + " is on offer");
            }

            yield return Tap(Option("Patience"));
            yield return Submit();

            var response = server.Posts("/games/respond").Single();
            Assert.AreEqual(407, (int)response["game_id"]);
            Assert.AreEqual(14217, (int)response["choices"]["given_card_id_912"]);
            Assert.IsFalse(Board().Choices.IsOpen);
        }

        [UnityTest]
        public IEnumerator AQuestionWithOnlyOneAnswer_IsReadyToSend()
        {
            var server = new PlayServer { State = Load(405) };
            server.OnPost = (path, body) =>
            {
                server.State = Load(405, s => s["round"]["pending_decision"] = null);
                return null;
            };
            yield return OpenBoard(server, 405);

            Assert.AreEqual("Respond to Fury", Board().Choices.TitleText);
            Assert.IsTrue(Board().Choices.SubmitEnabled, "Laziness is the only mood you could give up");

            yield return Submit();

            Assert.AreEqual(14128, (int)server.Posts("/games/respond").Single()["choices"]["discarded_mood_id_907"]);
        }

        [UnityTest]
        public IEnumerator TheQuestionIsNotRebuiltWhileYouAnswer_WhenOtherThingsChange()
        {
            var server = new PlayServer { State = Load(407) };
            yield return OpenBoard(server, 407);
            yield return Tap(Option("Cheer"));

            // Someone chats; the board redraws, but your half-answered question stays as it is.
            server.State = Load(407, s => s["chat_messages"] = JArray.Parse(
                "[{\"id\":1,\"sender_username\":\"BotSage\",\"message_text\":\"hi\",\"created_at\":\"2026-01-01 00:00:00\"}]"));
            yield return Poll();

            Assert.IsTrue(Board().Choices.IsOpen);
            Assert.IsTrue(Board().Choices.Form.IsSelected("given_card_id_912", "14220"), "your pick survived the redraw");
        }

        [UnityTest]
        public IEnumerator ARefusedAnswer_RebuildsTheQuestionFreshAndSaysWhy()
        {
            var server = new PlayServer { State = Load(407) };
            server.OnPost = (path, body) => Refuse("That card can't be given.", 400);
            yield return OpenBoard(server, 407);

            yield return Tap(Option("Patience"));
            yield return Submit();

            Assert.IsTrue(Board().Choices.IsOpen, "the question is still outstanding");
            Assert.AreEqual("That card can't be given.", Board().Choices.ProblemText);
            Assert.AreEqual(0, Board().Choices.Form.Selected("given_card_id_912").Count, "rebuilt from the latest state");
        }

        [UnityTest]
        public IEnumerator AQuestionForSomeoneElse_OnlyShowsAWaitingBanner_AndFreezesYourButtons()
        {
            var server = new PlayServer
            {
                State = Load(407, s =>
                {
                    var decision = s["round"]["pending_decision"];
                    decision["is_you"] = false;
                    decision["target_game_player_id"] = 913;
                    ((JObject)decision).Remove("field");
                }),
            };
            yield return OpenBoard(server, 407);

            Assert.IsFalse(Board().Choices.IsOpen);
            StringAssert.StartsWith("Waiting for BotSage to respond", Board().BannerText);
            Assert.IsFalse(ButtonNamed("Primary action").interactable);
            Assert.IsFalse(ButtonNamed("Resign").interactable, "a decision freezes the round");
        }

        [UnityTest]
        public IEnumerator OrderingAfterScoringEffects_ReordersAndSendsTheOrder()
        {
            var server = new PlayServer
            {
                State = Load(405, s => s["round"]["pending_decision"] = JObject.Parse(@"{
                    ""decision_type"":""after_scoring_order"",""is_you"":true,""initiating_game_player_id"":907,
                    ""target_game_player_id"":907,""played_card_id"":null,""played_card_name"":null,
                    ""field"":{""key"":""ordered_card_ids"",""type"":""card_order"",""required"":true,""label"":""Choose the order"",
                        ""cards"":[{""card_id"":1,""name"":""First"",""description"":""does a""},{""card_id"":2,""name"":""Second"",""description"":""does b""}]}}")),
            };
            server.OnPost = (path, body) =>
            {
                server.State = Load(405, s => s["round"]["pending_decision"] = null);
                return null;
            };
            yield return OpenBoard(server, 405);

            Assert.AreEqual("Order your after-scoring effects", Board().Choices.TitleText);
            Assert.IsTrue(Board().Choices.SubmitEnabled, "the given order is already an answer");
            ScreenshotHelper.Capture("decision-card-order");

            yield return Tap(ButtonNamed("Down First"));
            yield return Submit();

            Assert.AreEqual(new[] { 2, 1 }, server.Posts("/games/respond").Single()["choices"]["ordered_card_ids"].Select(t => (int)t).ToArray());
        }

        [UnityTest]
        public IEnumerator AScoringDecision_ShowsTheScoresSoFar()
        {
            var server = new PlayServer
            {
                State = Load(405, s =>
                {
                    s["round"]["pending_decision"] = JObject.Parse(@"{
                        ""decision_type"":""enthusiasm_extra_score"",""is_you"":true,""initiating_game_player_id"":907,
                        ""target_game_player_id"":907,""played_card_id"":null,""played_card_name"":""Enthusiasm"",
                        ""field"":{""key"":""take_bonus"",""type"":""bool"",""required"":false,""label"":""Score your best mood again""}}");
                    s["round"]["scoring_preview"] = JObject.Parse(@"{""scores"":{""907"":7,""908"":4},""sneakiness_swaps"":[]}");
                }),
            };
            yield return OpenBoard(server, 405);

            Assert.AreEqual("Enthusiasm's bonus", Board().Choices.TitleText);
            var texts = UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToList();
            Assert.IsTrue(texts.Any(t => t.Contains("bshaftoe: 7") && t.Contains("BotSage: 4")), string.Join(" | ", texts));
            Assert.IsTrue(Board().Choices.SubmitEnabled, "declining is a valid answer");
        }

        // --- suppressed moods ---------------------------------------------------------------------

        private static JObject WithLazinessSuppressedByScorn() => Load(405, s =>
        {
            s["round"]["pending_decision"] = null;
            var laziness = s["in_play"].Single(c => (string)c["name"] == "Laziness");
            laziness["is_suppressed"] = true;
            laziness["value"] = 0;
            laziness["suppressions"] = JArray.Parse(
                "[{\"expiry\":\"end_of_round\",\"suppressed_by_card_id\":14134,\"suppressed_by_name\":\"Scorn\"}]");
        });

        [UnityTest]
        public IEnumerator ASuppressedMood_IsLabelledSuppressed_AndTurnedOnItsSide()
        {
            var server = new PlayServer { State = WithLazinessSuppressedByScorn() };
            yield return OpenBoard(server, 405);

            var card = (RectTransform)Child("Card Laziness");
            Assert.AreEqual(270f, card.Find("Face").localEulerAngles.z, 0.01f, "the card face is turned a quarter turn clockwise");
            Assert.AreEqual(0f, card.localEulerAngles.z, 0.01f, "but the card's own space, and what's laid on it, is not");
            Assert.AreEqual(0f, Child("Card Fury").localEulerAngles.z, 0.01f, "other moods stay upright");
            Assert.IsNull(Child("Card Fury").Find("Face"));

            var label = card.GetComponentsInChildren<Text>().Single(t => t.text == "SUPPRESSED");
            Assert.AreEqual(0f, label.transform.eulerAngles.z, 0.01f, "the label reads upright across the turned card");
            Assert.IsFalse(UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Any(t => t.text == "OFF"));

            // The value chip sits in the top-right of the turned card, not on the turned card's old corner.
            var chip = (RectTransform)card.Find("Value");
            Assert.AreEqual("0", chip.GetComponentInChildren<Text>().text);
            Assert.AreEqual(0f, chip.eulerAngles.z, 0.01f);
            Assert.AreEqual(new Vector2(1f, 1f), chip.anchorMin);
            var corners = new Vector3[4];
            card.GetWorldCorners(corners);
            var chipCorners = new Vector3[4];
            chip.GetWorldCorners(chipCorners);
            Assert.Greater(chipCorners[2].x, card.position.x, "right of the middle");
            Assert.Greater(chipCorners[2].y, card.position.y, "above the middle");
            Assert.LessOrEqual(chipCorners[2].x, corners[2].x + 0.001f, "and not past the card's right edge");

            // On its side it is as wide as a card is tall, and its slot makes room for that.
            var slot = (RectTransform)card.parent;
            Assert.AreEqual("Suppressed slot", slot.name);
            Assert.AreEqual(card.sizeDelta.x, slot.GetComponent<LayoutElement>().preferredWidth, 0.01f);
            Assert.Greater(card.sizeDelta.x, card.sizeDelta.y, "wider than tall");
            ScreenshotHelper.Capture("board-suppressed-mood");
        }

        [UnityTest]
        public IEnumerator TheCloseUpOfASuppressedMood_NamesWhatSuppressesIt_InBoldRed()
        {
            var server = new PlayServer { State = WithLazinessSuppressedByScorn() };
            yield return OpenBoard(server, 405);

            Child("Card Laziness").GetComponent<Button>().onClick.Invoke();
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsTrue(Board().DetailOpen);
            var detail = UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude)
                .Select(t => t.text).First(t => t.Contains("Suppressed by"));
            StringAssert.Contains("<b><color=#D94040>Suppressed by Scorn</color></b>", detail);
            StringAssert.DoesNotContain("switched off", detail);
            StringAssert.Contains("Value right now: 0", detail);
        }

        // --- chat --------------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator Chat_SendsToTheTable_AndClearsTheBox()
        {
            var server = new PlayServer { State = Load(406) };
            yield return OpenBoard(server, 406);

            yield return Tap(PhaseTwoSceneTests.FindButton("Chat"));
            Child("ChatEntry").GetComponentInChildren<InputField>().text = "good luck";
            yield return Tap(ButtonNamed("Send chat"));

            var chat = server.Posts("/games/chat").Single();
            Assert.AreEqual("good luck", (string)chat["message_text"]);
            Assert.AreEqual("table", (string)chat["channel"]);
            Assert.AreEqual(string.Empty, Child("ChatEntry").GetComponentInChildren<InputField>().text);
        }

        [UnityTest]
        public IEnumerator ARefusedChatMessage_KeepsTheTextAndSaysWhy()
        {
            var server = new PlayServer { State = Load(406) };
            server.OnPost = (path, body) => Refuse("Message too long.", 400);
            yield return OpenBoard(server, 406);

            yield return Tap(PhaseTwoSceneTests.FindButton("Chat"));
            Child("ChatEntry").GetComponentInChildren<InputField>().text = "x";
            yield return Tap(ButtonNamed("Send chat"));

            Assert.AreEqual("x", Child("ChatEntry").GetComponentInChildren<InputField>().text);
            Assert.IsTrue(UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Any(t => t.text == "Message too long."));
        }

        // --- watching ------------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator ASpectator_HasNoButtonsAndNoChatBox_AndNoHandCardOpensAForm()
        {
            var server = new PlayServer
            {
                State = Load(406, s =>
                {
                    s["you"]["game_player_id"] = null;
                    s["you"]["hand"] = new JArray();
                }),
            };
            yield return OpenBoard(server, 406, spectate: true);

            Assert.IsNull(Child("Primary action"));
            Assert.IsNull(Child("Resign"));
            Assert.IsFalse(Board().Choices.IsOpen);

            yield return Tap(PhaseTwoSceneTests.FindButton("Chat"));
            Assert.IsNull(Child("ChatEntry"));
            Assert.AreEqual(0, server.Calls.Count(c => c.StartsWith("POST")));
        }

        // --- starting a game ---------------------------------------------------------------------

        [UnityTest]
        public IEnumerator AGameThatIsWaiting_IsStartedByTheBoard()
        {
            var server = new PlayServer { State = Load(406, s => s["game"]["status"] = "waiting") };
            server.OnPost = (path, body) =>
            {
                server.State = Load(406);
                return null;
            };
            yield return OpenBoard(server, 406);

            Assert.AreEqual(1, server.Posts("/games/start").Count());
            Assert.AreEqual("Your turn", Board().BannerText);
        }

        [UnityTest]
        public IEnumerator ASynchronousGame_WaitsForYouToBeReady_ThenStartsWhenEveryoneIs()
        {
            JObject Waiting(bool youReady) => Load(406, s =>
            {
                s["game"]["status"] = "waiting";
                s["game"]["synchronous_mode"] = true;
                foreach (var player in s["players"])
                {
                    player["ready"] = (int)player["game_player_id"] != 909 || youReady;
                }
            });

            var server = new PlayServer { State = Waiting(youReady: false) };
            server.OnPost = (path, body) =>
            {
                server.State = path == "/games/ready" ? Waiting(youReady: true) : Load(406);
                return null;
            };
            yield return OpenBoard(server, 406);

            Assert.AreEqual("I'm ready", Primary());
            StringAssert.Contains("Not yet: bshaftoe", Board().RoundText);
            Assert.AreEqual(0, server.Posts("/games/start").Count(), "not everyone is ready");

            yield return Tap(ButtonNamed("Primary action"));
            Assert.AreEqual(1, server.Posts("/games/ready").Count());

            yield return Poll();
            Assert.AreEqual(1, server.Posts("/games/start").Count(), "now that everyone is ready");
            Assert.AreEqual("Your turn", Board().BannerText);
        }

        // --- kinds of game the app can't play yet ---------------------------------------------------

        [UnityTest]
        public IEnumerator ADraftGame_ShowsTheBoardButSaysItCantBePlayedHere()
        {
            var server = new PlayServer { State = Load(406, s => s["game"]["deck_type"] = "quick_draft") };
            yield return OpenBoard(server, 406);

            StringAssert.Contains("can't be played in the app yet", Board().RoundText);
            Assert.IsFalse(ButtonNamed("Primary action").interactable);

            yield return TapHandCard("Superiority");
            Assert.IsFalse(Board().Choices.IsOpen);
            Assert.IsTrue(Board().DetailOpen);
        }

        // --- the clock and warnings ------------------------------------------------------------------

        [UnityTest]
        public IEnumerator TheActionClock_CountsDownInTheHeader()
        {
            var deadline = DateTime.UtcNow.AddSeconds(30).ToString("yyyy-MM-dd HH:mm:ss");
            var server = new PlayServer
            {
                State = Load(406, s =>
                {
                    s["game"]["synchronous_mode"] = true;
                    s["game"]["action_deadline_at"] = deadline;
                    s["game"]["action_deadline_game_player_id"] = 909;
                }),
            };
            yield return OpenBoard(server, 406);

            var first = Regex.Match(Board().RoundText, @"bshaftoe's clock: (\d+)s");
            Assert.IsTrue(first.Success, Board().RoundText);

            yield return new WaitForSeconds(1.6f);

            var later = Regex.Match(Board().RoundText, @"bshaftoe's clock: (\d+)s");
            Assert.Less(int.Parse(later.Groups[1].Value), int.Parse(first.Groups[1].Value), "it ticks without waiting for a poll");
        }

        [UnityTest]
        public IEnumerator ALoopWarning_IsShownOnce_ThenAgainWhenItGetsWorse()
        {
            JObject Warned(int count) => Load(406, s => s["game"]["loop_warning"] = JObject.Parse(
                "{\"game_player_id\":909,\"occurrence_count\":" + count + "}"));

            var server = new PlayServer { State = Warned(3) };
            yield return OpenBoard(server, 406);

            Assert.IsTrue(Board().Confirm.IsOpen);
            StringAssert.Contains("repeated 3 times", Board().Confirm.MessageText);
            Board().Confirm.Press(true);

            yield return Poll();
            Assert.IsFalse(Board().Confirm.IsOpen, "the same warning isn't shown again");

            server.State = Warned(4);
            yield return Poll();
            Assert.IsTrue(Board().Confirm.IsOpen);
            StringAssert.Contains("repeated 4 times", Board().Confirm.MessageText);
        }
    }
}
