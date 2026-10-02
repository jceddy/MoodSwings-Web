using System;
using UnityEngine;
using UnityEngine.EventSystems;
using UnityEngine.InputSystem.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Reports a mouse pointer resting on a card. Touch doesn't hover, so a finger never counts;
    /// a plain pointer event (as tests send) is taken to be the mouse.
    /// </summary>
    public sealed class HoverTarget : MonoBehaviour, IPointerEnterHandler, IPointerExitHandler
    {
        public Action<PointerEventData> Entered;
        public Action Exited;

        public void OnPointerEnter(PointerEventData eventData)
        {
            if (IsMouse(eventData))
            {
                Entered?.Invoke(eventData);
            }
        }

        public void OnPointerExit(PointerEventData eventData) => Exited?.Invoke();

        private static bool IsMouse(PointerEventData eventData) =>
            !(eventData is ExtendedPointerEventData extended) || extended.pointerType == UIPointerType.MouseOrPen;
    }
}
