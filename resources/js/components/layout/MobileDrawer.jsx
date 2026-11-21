import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { cx } from '../../lib/cx.js';
import { Button, Drawer, Icon } from '../ui/index.js';
import useMediaQuery from '../../hooks/useMediaQuery.js';
import { findActiveItem } from '../../config/navigation.js';

const FALLBACK_LETTER = '\u06A9';
const ROW = 'flex min-h-11 w-full items-center justify-between gap-2 rounded-lg px-3 text-sm';

export default function MobileDrawer({ open, onClose, entries, user, title, logoutLabel, onLogout, loggingOut = false }) {
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const isDesktop = useMediaQuery('(min-width: 64rem)');
  const active = findActiveItem(entries, pathname);
  const [openId, setOpenId] = useState(active.groupId);

  const handleLinkClick = (e, to) => {
    e.preventDefault();
    onClose();
    navigate(to);
  };

  useEffect(() => {
    if (open) setOpenId(active.groupId);
  }, [open, active.groupId]);

  useEffect(() => {
    if (open && isDesktop) onClose();
  }, [open, isDesktop, onClose]);

  useEffect(() => {
    if (open) onClose();
  }, [pathname]);

  const name = (user && typeof user.name === 'string' && user.name.trim()) || '\u0627\u06CC\u0645\u0644 \u0635\u0627\u0641\u06CC';
  const letter = name.charAt(0) || FALLBACK_LETTER;
  const role = (user && typeof user.role === 'string') ? user.role : 'Exam Administrator';

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
          {role ? <span className="block truncate text-xs text-[#8B96B3]">{role}</span> : null}
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
                            onClick={(e) => handleLinkClick(e, item.to)}
                            aria-current={isActive ? 'page' : undefined}
                            className={cx(
                              ROW,
                              'text-[13px] transition-colors hover:bg-white/[.07] hover:text-white gap-2 cursor-pointer',
                              isActive ? 'bg-[#B08A3E]/20 font-bold text-white' : 'text-nav-ink',
                            )}
                          >
                            <span className="flex items-center gap-2 truncate">
                              {item.icon ? <Icon name={item.icon} size={15} className="opacity-80 shrink-0" /> : null}
                              <span>{item.label}</span>
                            </span>
                            {item.pill ? (
                              <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-white/10 text-[#DCE2EE]">
                                {item.pill}
                              </span>
                            ) : null}
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
              onClick={(e) => handleLinkClick(e, entry.to)}
              aria-current={isActive ? 'page' : undefined}
              className={cx(
                ROW,
                'transition-colors hover:bg-white/[.07] hover:text-white gap-2 cursor-pointer',
                isActive ? 'bg-[#B08A3E]/20 font-bold text-white' : 'text-nav-ink',
              )}
            >
              <span className="flex items-center gap-2 truncate">
                {entry.icon ? <Icon name={entry.icon} size={15} className="opacity-80 shrink-0" /> : null}
                <span>{entry.label}</span>
              </span>
              {entry.pill ? (
                <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-white/10 text-[#DCE2EE]">
                  {entry.pill}
                </span>
              ) : null}
            </Link>
          );
        })}
      </nav>
    </Drawer>
  );
}

export { MobileDrawer };
