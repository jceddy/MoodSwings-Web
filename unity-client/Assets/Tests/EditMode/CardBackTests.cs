using MoodSwings.Core;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class CardBackTests
    {
        [Test]
        public void TheCardBack_IsInTheProject_ImportedAsASprite()
        {
            var back = CardArtLibrary.CardBack();

            Assert.IsNotNull(back, "Assets/Resources/UI/card-back should import as a sprite");
        }

        [Test]
        public void TheCardBack_HasTheShapeOfACard()
        {
            var back = CardArtLibrary.CardBack();

            // Card faces are 744x1040.
            Assert.AreEqual(744f / 1040f, back.rect.width / back.rect.height, 0.01f);
        }
    }
}
