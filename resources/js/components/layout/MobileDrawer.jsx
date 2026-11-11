import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { cx } from '../../lib/cx.js';
import { Button, Drawer } from '../ui/index.js';
import useMediaQuery from '../../hooks/useMediaQuery.js';
import { findActiveItem } from '../../config/navigation.js';
import { roleName } from '../../lib/permissions.js';

// Mobile menu (below lg): Drawer on the start side (right in RTL). Touch targets are at least 44px.
const FALLBACK_LETTER = '\u06A9';
const ROW = 'flex min-h-11 w-full items-center justify-between gap-2 rounded-lg px-3 text-sm';

export default function MobileDrawer({ open, onClose, entries, user, title, logoutLabel, onLogout, loggingOut = false }) {
  const { pathname } = useLocation();
  const isDesktop = useMediaQuery('(min-width: 64rem)');
  const active = findActiveItem(entries, pathname);
  const [openId, setOpenId] = useState(active.groupId);

  useEffect(() => {
    if (open) setOpenId(active.groupId);
  }, [open]);

  // Reaching the desktop width closes the drawer, because the desktop menu takes over.
  useEffect(() => {
    if (open && isDesktop) onClose();
  }, [open, isDesktop, onClose]);

  // Browser back/forward while open.
  useEffect(() => {
    if (open) onClose();
  }, [pathname]);

  const name = user && typeof user.name === 'string' ? user.name.trim() : '';
  const letter = name.charAt(0) || FALLBACK_LETTER;
  const role = roleName(user);

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={title}
      side="start"
      tone="navy"
      footer={
        <Button variant="outline" block loading={loggingOut} onClick={onLogout}>
          {logoutLabel}
        </Button>
      }
    >
      <div className="mb-3 flex items-center gap-3 border-b border-white/10 pb-3">
        <span
          aria-hidden="true"
          className="grid size-9 shrink-0 place-items-center rounded-full bg-teal text-sm font-bold text-white"
        >
          {letter}
        </span>
        <span className="min-w-0">
          <span className="block truncate text-sm font-bold text-white">{name}</span>
          {role ? <span className="block truncate text-xs text-nav-ink">{role}</span> : null}
        </span>
      </div>
      <nav aria-label={title}>
        {entries.map((entry) => {
          if (entry.type === 'group') {
            const expanded = openId === entry.id;
            const hasActive = entry.items.some((item) => item.id === active.itemId);
            return (
              <div key={entry.id}>
                <button
                  type="button"
                  aria-expanded={expanded}
                  onClick={() => setOpenId((current) => (current === entry.id ? null : entry.id))}
                  className={cx(
                    ROW,
                    'cursor-pointer transition-colors hover:bg-white/[.07] hover:text-white',
                    hasActive || expanded ? 'font-bold text-white' : 'text-nav-ink',
                  )}
                >
                  <span>{entry.label}</span>
                  <span
                    aria-hidden="true"
                    className={cx('inline-block text-[10px] opacity-60 transition-transform duration-100', expanded && 'rotate-180')}
                  >
                    {'\u25BE'}
                  </span>
                </button>
                {expanded ? (
                  <ul className="mb-1 ms-3 list-none border-s border-white/10 p-0 ps-1">
                    {entry.items.map((item) => {
                      const isActive = item.id === active.itemId;
                      return (
                        <li key={item.id}>
                          <Link
                            to={item.to}
                            onClick={onClose}
                            aria-current={isActive ? 'page' : undefined}
                            className={cx(
                              ROW,
                              'text-[13px] transition-colors hover:bg-white/[.07] hover:text-white',
                              isActive ? 'bg-white/15 font-bold text-white' : 'text-nav-ink',
                            )}
                          >
                            {item.label}
                          </Link>
                        </li>
                      );
                    })}
                  </ul>
                ) : null}
              </div>
            );
          }
          const isActive = active.itemId === entry.id;
          return (
            <Link
              key={entry.id}
              to={entry.to}
              onClick={onClose}
              aria-current={isActive ? 'page' : undefined}
              className={cx(
                ROW,
                'transition-colors hover:bg-white/[.07] hover:text-white',
                isActive ? 'bg-white/15 font-bold text-white' : 'text-nav-ink',
              )}
            >
              {entry.label}
            </Link>
          );
        })}
      </nav>
    </Drawer>
  );
}

export { MobileDrawer };
