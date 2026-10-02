using System;
using UnityEngine;
using UnityEngine.EventSystems;

namespace MoodSwings.UI
{
    /// <summary>
    /// Lets a card be dragged (mouse or touch). It only reports the drag; the screen
    /// decides what dragging means. A card that is dragged does not also count as clicked.
    /// </summary>
    public sealed class DraggableCard : MonoBehaviour, IBeginDragHandler, IDragHandler, IEndDragHandler
    {
        public Action<PointerEventData> Began;
        public Action<PointerEventData> Moved;
        public Action<PointerEventData> Ended;

        public void OnBeginDrag(PointerEventData eventData) => Began?.Invoke(eventData);

        public void OnDrag(PointerEventData eventData) => Moved?.Invoke(eventData);

        public void OnEndDrag(PointerEventData eventData) => Ended?.Invoke(eventData);
    }
}
