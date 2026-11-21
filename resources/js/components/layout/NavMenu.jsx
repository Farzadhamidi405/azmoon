import { useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { cx } from '../../lib/cx.js';
import useDismiss from '../../hooks/useDismiss.js';
import { findActiveItem } from '../../config/navigation.js';
import { Icon } from '../ui/index.js';

const ITEM_BASE =
  'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-[9px] text-[12.5px] font-sans transition-colors hover:bg-white/[.07] hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold xl:px-[13px]';
const ACTIVE = 'font-bold text-white shadow-[inset_0_-2px_0_#B08A3E]';
const IDLE = 'text-nav-ink';

function NavGroup({ entry, activeItemId, open, onToggle, onClose }) {
  const boxRef = useRef(null);
  const buttonRef = useRef(null);
  useDismiss({ open, onClose, containerRef: boxRef, restoreFocusRef: buttonRef });
  const hasActive = entry.items.some((item) => item.id === activeItemId);

  return (
    <div ref={boxRef} className="relative nav-group">
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        onClick={onToggle}
        className={cx(
          ITEM_BASE,
          'grp-btn cursor-pointer',
          hasActive ? ACTIVE : IDLE,
          open && 'bg-white/[.09] text-white'
        )}
      >
        <span>{entry.label}</span>
        <span
          aria-hidden="true"
          className={cx(
            'chev inline-block text-[9px] opacity-60 transition-transform duration-100',
            open && 'rotate-180'
          )}
        >
          {'\u25BE'}
        </span>
      </button>

      {open ? (
        <div
          className="grp-items absolute start-0 top-[calc(100%+6px)] z-[60] flex min-w-[240px] flex-col gap-0.5 rounded-xl border border-white/[.12] bg-[#1E3057] p-2 shadow-[0_16px_34px_rgba(0,0,0,.4)] text-right"
          dir="rtl"
        >
          {entry.items.map((item) => {
            const isActive = item.id === activeItemId;
            return (
              <Link
                key={item.id}
                to={item.to}
                onClick={() => {
                  setTimeout(() => onClose(), 40);
                }}
                aria-current={isActive ? 'page' : undefined}
                className={cx(
                  'flex items-center gap-2.5 rounded-[9px] px-2.5 py-2 text-[12.5px] transition-colors cursor-pointer',
                  isActive
                    ? 'bg-[#B08A3E]/20 font-semibold text-white'
                    : 'text-[#AEB8D2] hover:bg-white/[.06] hover:text-white'
                )}
              >
                {item.icon ? (
                  <Icon name={item.icon} size={16} className="opacity-85 shrink-0" />
                ) : null}
                <span className="flex-1 truncate">{item.label}</span>
                {item.pill ? (
                  <span
                    className={cx(
                      'pill text-[10px] font-bold px-1.5 py-0.5 rounded-full shrink-0',
                      isActive ? 'bg-[#B08A3E] text-[#1B1608]' : 'bg-white/10 text-[#DCE2EE]'
                    )}
                  >
                    {item.pill}
                  </span>
                ) : null}
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
    <nav aria-label={label} className={cx('app-nav items-center gap-0.5', className)}>
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
            className={cx(ITEM_BASE, isActive ? ACTIVE : IDLE, 'cursor-pointer')}
          >
            {entry.icon ? <Icon name={entry.icon} size={16} className="opacity-85 shrink-0" /> : null}
            <span>{entry.label}</span>
            {entry.pill ? (
              <span
                className={cx(
                  'pill text-[10px] font-bold px-1.5 py-0.5 rounded-full shrink-0 mr-1',
                  isActive ? 'bg-[#B08A3E] text-[#1B1608]' : 'bg-white/10 text-[#DCE2EE]'
                )}
              >
                {entry.pill}
              </span>
            ) : null}
          </Link>
        );
      })}
    </nav>
  );
}

export { NavMenu };
