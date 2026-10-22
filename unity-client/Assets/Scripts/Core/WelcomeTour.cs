using System;
using System.Collections.Generic;

namespace MoodSwings.Core
{
    /// <summary>The screen a tour step is shown on. The tour takes the player there.</summary>
    public enum TourScreen
    {
        Home,
        Settings,
    }

    /// <summary>One stop on the welcome tour.</summary>
    public sealed class TourStep
    {
        public string Id { get; set; }

        public TourScreen Screen { get; set; } = TourScreen.Home;

        public string Title { get; set; }

        public string Body { get; set; }

        /// <summary>
        /// Something on its screen to point at: a main menu button ("play", "puzzles", ...), or one of Settings' switches
        /// ("custom-formats", "discoverable"); null for a step that talks about the whole app.
        /// </summary>
        public string Target { get; set; }

        /// <summary>Show a little picture of the table with it.</summary>
        public bool TableDiagram { get; set; }
    }

    /// <summary>What the welcome tour says, in order. The targets are the main menu's buttons (see HomeScreen.TourTarget).</summary>
    public static class TourSteps
    {
        public static readonly IReadOnlyList<TourStep> All = new List<TourStep>
        {
            new TourStep
            {
                Id = "welcome",
                Title = "Welcome to MOOD",
                Body = "Here is a quick look at what you can do. It takes about a minute, and you can skip it at any time. "
                    + "You can run it again later from Settings.",
            },
            new TourStep
            {
                Id = "play",
                Target = "play",
                Title = "Play",
                Body = "Your games are here, the ones waiting on you first; the number on the button says how many. "
                    + "Start a New game against friends and bots, post one to the open lobby, or join a game someone else posted. "
                    + "Drafts, sealed pools and team play are under New game too.",
            },
            new TourStep
            {
                Id = "puzzles",
                Target = "puzzles",
                Title = "Puzzles",
                Body = "Solitaire puzzles, easiest first. Each has its own goal to reach, and some have a hint if you get stuck.",
            },
            new TourStep
            {
                Id = "decklists",
                Target = "decklists",
                Title = "Decklists",
                Body = "Build and save your own decks, or copy a friend's. Play them in a custom duel, or use a saved deck as the pool for a draft.",
            },
            new TourStep
            {
                Id = "friends",
                Target = "friends",
                Title = "Friends",
                Body = "Add friends by username or email, answer requests (the button shows how many are new), see who is online, "
                    + "and invite them to a game.",
            },
            new TourStep
            {
                Id = "achievements",
                Target = "achievements",
                Title = "Achievements",
                Body = "Unlock achievements as you play. New ones are marked on the button until you have looked at them.",
            },
            new TourStep
            {
                Id = "tournaments",
                Target = "tournaments",
                Title = "Tournaments",
                Body = "Join a tournament and play your matches in it.",
            },
            new TourStep
            {
                Id = "stats",
                Target = "stats",
                Title = "Stats",
                Body = "Your record, and statistics for every card.",
            },
            new TourStep
            {
                Id = "settings",
                Target = "settings",
                Title = "Settings",
                Body = "Dark mode, sound and music, notifications, your privacy options and your own card art. "
                    + "Two of its switches change a lot about the games you can start and who you can play them with, "
                    + "so the next two steps take you to them.",
            },
            new TourStep
            {
                Id = "custom-formats",
                Screen = TourScreen.Settings,
                Target = "custom-formats",
                Title = "Show custom card/effect formats",
                Body = "Off by default. Switched on, New game also offers custom formats such as Chaos Draft, "
                    + "where each round you are offered random effects to attach to the cards in your hand. "
                    + "Without it, those formats are not in the list.",
            },
            new TourStep
            {
                Id = "discoverable",
                Screen = TourScreen.Settings,
                Target = "discoverable",
                Title = "Discoverable for open games",
                Body = "On by default. It lets strangers see and join the games you post to the open lobby, and join the open "
                    + "tournaments you run. Switch it off and you can't post to the lobby, so the games you start are "
                    + "only with the friends and bots you invite.",
            },
            new TourStep
            {
                Id = "table",
                Title = "At the table",
                TableDiagram = true,
                Body = "Tap a card in your hand to play it, or drag it onto the table. The scoreboards show points and whose turn it is, "
                    + "and Pass ends your turn. Tap any card to read it up close. The menu, top left, has the game log, "
                    + "chat with quick messages and emoji, and Resign.",
            },
            new TourStep
            {
                Id = "done",
                Title = "You're ready",
                Body = "That's everything. Find a game under Play, or try a puzzle to get the feel of the cards. "
                    + "To see this tour again, open Settings and choose Welcome tour.",
            },
        };
    }

    /// <summary>
    /// Where the welcome tour is: which step, going forward and back, and ending -- finished or skipped, both of which count
    /// as having seen it (it is shown by itself only until then). UI-free; the overlay draws it.
    /// </summary>
    public sealed class TourFlow
    {
        private readonly DeviceSettings _device;
        private bool _replayRequested;

        public TourFlow(DeviceSettings device)
        {
            _device = device;
        }

        public bool IsActive { get; private set; }

        public int Index { get; private set; }

        public int Count => TourSteps.All.Count;

        public TourStep Current => IsActive ? TourSteps.All[Index] : null;

        public bool IsFirst => Index == 0;

        public bool IsLast => Index == Count - 1;

        /// <summary>Whether the tour has run (or been skipped) on this device, so it needn't start by itself.</summary>
        public bool Seen => _device != null && _device.TourSeen;

        /// <summary>The step changed, or the tour started or ended.</summary>
        public event Action Changed;

        /// <summary>The tour ended: true if it was gone through to the end, false if it was skipped.</summary>
        public event Action<bool> Ended;

        public void Start()
        {
            Index = 0;
            IsActive = true;
            Changed?.Invoke();
        }

        public void Next()
        {
            if (!IsActive)
            {
                return;
            }

            if (IsLast)
            {
                Finish(completed: true);
                return;
            }

            Index++;
            Changed?.Invoke();
        }

        public void Back()
        {
            if (!IsActive || IsFirst)
            {
                return;
            }

            Index--;
            Changed?.Invoke();
        }

        public void Skip()
        {
            if (IsActive)
            {
                Finish(completed: false);
            }
        }

        private void Finish(bool completed)
        {
            IsActive = false;
            if (_device != null)
            {
                _device.TourSeen = true;
            }

            Changed?.Invoke();
            Ended?.Invoke(completed);
        }

        /// <summary>Settings asks for the tour again; the main menu starts it once it is on screen.</summary>
        public void RequestReplay() => _replayRequested = true;

        /// <summary>True once for each request to run the tour again.</summary>
        public bool TakeReplayRequest()
        {
            var requested = _replayRequested;
            _replayRequested = false;
            return requested;
        }
    }
}
