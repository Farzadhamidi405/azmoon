import { useRef } from 'react';
import { createPortal } from 'react-dom';
import { cx } from '../../lib/cx.js';
import useOverlay from '../../hooks/useOverlay.js';

// Shared shell for Modal and Drawer: portal, backdrop, dialog panel and keyboard behaviour.
export default function Overlay({
  open,
  onClose,
  dismissible = true,
  role = 'dialog',
  label,
  labelledBy,
  describedBy,
  initialFocusRef,
  wrapperClassName,
  panelClassName,
  children,
}) {
  const panelRef = useRef(null);
  useOverlay({ open, onClose, panelRef, initialFocusRef, dismissible });

  if (!open || typeof document === 'undefined') return null;

  return createPortal(
    <div className={cx('fixed inset-0 z-50 flex', wrapperClassName)}>
      <div
        aria-hidden="true"
        className="absolute inset-0 bg-navy/50"
        onClick={dismissible ? onClose : undefined}
      />
      <div
        ref={panelRef}
        role={role}
        aria-modal="true"
        aria-label={label}
        aria-labelledby={labelledBy}
        aria-describedby={describedBy}
        tabIndex={-1}
        className={cx('relative outline-none', panelClassName)}
      >
        {children}
      </div>
    </div>,
    document.body,
  );
}

export { Overlay };
