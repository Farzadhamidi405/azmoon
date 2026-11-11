import { useEffect, useRef } from 'react';

// Dismiss behaviour for non-modal popups (dropdown menus, popovers):
// outside pointer press, focus leaving the container, and Escape (focus returns to the trigger).
// Modal surfaces (Modal/Drawer) use useOverlay instead.
export default function useDismiss({ open, onClose, containerRef, restoreFocusRef }) {
  const closeRef = useRef(onClose);

  useEffect(() => {
    closeRef.current = onClose;
  });

  useEffect(() => {
    if (!open) return undefined;

    function isOutside(target) {
      const box = containerRef.current;
      return Boolean(box) && target instanceof Node && !box.contains(target);
    }

    function close() {
      if (typeof closeRef.current === 'function') closeRef.current();
    }

    function onPointerDown(event) {
      if (isOutside(event.target)) close();
    }

    function onFocusIn(event) {
      if (isOutside(event.target)) close();
    }

    function onKeyDown(event) {
      if (event.key !== 'Escape') return;
      close();
      const trigger = restoreFocusRef && restoreFocusRef.current;
      if (trigger && typeof trigger.focus === 'function') trigger.focus();
    }

    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('focusin', onFocusIn);
    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('focusin', onFocusIn);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open, containerRef, restoreFocusRef]);
}

export { useDismiss };
