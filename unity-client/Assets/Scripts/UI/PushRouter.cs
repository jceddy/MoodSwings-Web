using MoodSwings.Core;

namespace MoodSwings.UI
{
    /// <summary>Opens the screen a tapped notification points at.</summary>
    public static class PushRouter
    {
        public static void Open(ScreenRouter router, PushRoute route)
        {
            if (route == null)
            {
                return;
            }

            switch (route.Target)
            {
                case PushRoute.Kind.Game:
                    router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, route.GameId));
                    break;
                case PushRoute.Kind.Friends:
                    router.Show<FriendsScreen>();
                    break;
                case PushRoute.Kind.Achievements:
                    router.Show<AchievementsScreen>();
                    break;
            }
        }
    }
}
