import { useEffect, useRef } from 'react';

const FOCUSABLE =
  'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

// Overlays opened later sit on top; only the top-most one reacts to Escape/Tab.
const stack = [];
let lockCount = 0;
let savedOverflow = '';

function lockScroll() {
  if (lockCount === 0) {
    savedOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }
  lockCount += 1;
}

function unlockScroll() {
  lockCount = Math.max(0, lockCount - 1);
  if (lockCount === 0) document.body.style.overflow = savedOverflow;
}

function getFocusable(panel) {
  if (!panel) return [];
  return Array.from(panel.querySelectorAll(FOCUSABLE)).filter(
    (el) => !el.hasAttribute('hidden') && el.getAttribute('aria-hidden') !== 'true',
  );
}

// Escape to close, Tab focus trap, body scroll lock and focus restore for Modal/Drawer.
export default function useOverlay({ open, onClose, panelRef, initialFocusRef, dismissible = true }) {
  const closeRef = useRef(onClose);
  const dismissRef = useRef(dismissible);

  useEffect(() => {
    closeRef.current = onClose;
    dismissRef.current = dismissible;
  });

  useEffect(() => {
    if (!open) return undefined;

    const token = {};
    stack.push(token);
    const previous = document.activeElement;
    lockScroll();

    const panel = panelRef.current;
    const target =
      (initialFocusRef && initialFocusRef.current) ||
      (panel && panel.querySelector('[data-autofocus]')) ||
      getFocusable(panel)[0] ||
      panel;
    if (target && typeof target.focus === 'function') target.focus();

    function onKeyDown(event) {
      if (stack[stack.length - 1] !== token) return;

      if (event.key === 'Escape') {
        if (dismissRef.current && typeof closeRef.current === 'function') {
          event.stopPropagation();
          closeRef.current();
        }
        return;
      }

      if (event.key !== 'Tab') return;

      const current = panelRef.current;
      const items = getFocusable(current);
      if (items.length === 0) {
        event.preventDefault();
        if (current) current.focus();
        return;
      }
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || active === current)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      const index = stack.indexOf(token);
      if (index !== -1) stack.splice(index, 1);
      unlockScroll();
      if (previous && typeof previous.focus === 'function' && document.contains(previous)) {
        previous.focus();
      }
    };
  }, [open, panelRef, initialFocusRef]);
}
