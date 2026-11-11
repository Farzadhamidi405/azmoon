import { useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { cx } from '../../lib/cx.js';
import useDismiss from '../../hooks/useDismiss.js';
import { findActiveItem } from '../../config/navigation.js';

// Desktop menu (lg and up). Groups are disclosure buttons (aria-expanded), not ARIA menus.
// No overflow on <nav> or its parents, otherwise the absolute panels would be clipped.
const ITEM_BASE =
  'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-[9px] text-[12.5px] transition-colors hover:bg-white/[.07] hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold xl:px-[13px]';
const ACTIVE = 'font-bold text-white shadow-[inset_0_-2px_0_#B08A3E]';
const IDLE = 'text-nav-ink';

function NavGroup({ entry, activeItemId, open, onToggle, onClose }) {
  const boxRef = useRef(null);
  const buttonRef = useRef(null);
  useDismiss({ open, onClose, containerRef: boxRef, restoreFocusRef: buttonRef });
  const hasActive = entry.items.some((item) => item.id === activeItemId);

  return (
    <div ref={boxRef} className="relative">
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        onClick={onToggle}
        className={cx(ITEM_BASE, 'cursor-pointer', hasActive ? ACTIVE : IDLE, open && 'bg-white/[.09] text-white')}
      >
        {entry.label}
        <span
          aria-hidden="true"
          className={cx('inline-block text-[9px] opacity-60 transition-transform duration-100', open && 'rotate-180')}
        >
          {'\u25BE'}
        </span>
      </button>
      {open ? (
        <div className="absolute start-0 top-[calc(100%+6px)] z-[60] min-w-[230px] rounded-xl border border-white/[.12] bg-navy-2 p-2 shadow-[0_16px_34px_rgba(0,0,0,.4)]">
          {entry.items.map((item) => {
            const isActive = item.id === activeItemId;
            return (
              <Link
                key={item.id}
                to={item.to}
                onClick={onClose}
                aria-current={isActive ? 'page' : undefined}
                className={cx(
                  'block rounded-lg px-3 py-2 text-xs transition-colors hover:bg-white/[.07] hover:text-white focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-gold',
                  isActive ? 'bg-white/15 font-bold text-white' : 'font-normal text-nav-ink',
                )}
              >
                {item.label}
              </Link>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

export default function NavMenu({ entries, label, className }) {
  const { pathname } = useLocation();
  const [openId, setOpenId] = useState(null);
  const active = findActiveItem(entries, pathname);

  useEffect(() => {
    setOpenId(null);
  }, [pathname]);

  return (
    <nav aria-label={label} className={cx('items-center gap-0.5', className)}>
      {entries.map((entry) => {
        if (entry.type === 'group') {
          return (
            <NavGroup
              key={entry.id}
              entry={entry}
              activeItemId={active.itemId}
              open={openId === entry.id}
              onToggle={() => setOpenId((current) => (current === entry.id ? null : entry.id))}
              onClose={() => setOpenId((current) => (current === entry.id ? null : current))}
            />
          );
        }
        const isActive = active.itemId === entry.id;
        return (
          <Link
            key={entry.id}
            to={entry.to}
            aria-current={isActive ? 'page' : undefined}
            className={cx(ITEM_BASE, isActive ? ACTIVE : IDLE)}
          >
            {entry.label}
          </Link>
        );
      })}
    </nav>
  );
}

export { NavMenu };
