import { useCallback, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../hooks/useAuth';
import { buildNavigation } from '../../config/navigation.js';
import { cx } from '../../lib/cx.js';
import { Icon, Spinner } from '../ui/index.js';
import Brand from './Brand.jsx';
import MobileDrawer from './MobileDrawer.jsx';
import NavMenu from './NavMenu.jsx';
import NotificationBell from './NotificationBell.jsx';

const TEXT = Object.freeze({
  menu: '\u0645\u0646\u0648',
  logout: '\u062E\u0631\u0648\u062C',
});

const DEFAULT_USER = Object.freeze({
  name: '\u0627\u06CC\u0645\u0644 \u0635\u0627\u0641\u06CC',
  role: 'Exam Administrator',
  initials: '\u0627\u06CC',
});

const ICON_BUTTON =
  'inline-flex size-10 shrink-0 items-center justify-center rounded-lg text-[#AEB8D2] transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold';

export default function TopNavbar() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const entries = useMemo(() => buildNavigation(), [user]);
  const closeDrawer = useCallback(() => setDrawerOpen(false), []);

  const displayName = (user && typeof user.name === 'string' && user.name.trim()) || DEFAULT_USER.name;
  const displayRole = (user && typeof user.role === 'string' && user.role.trim()) || DEFAULT_USER.role;
  const initials = displayName
    .split(' ')
    .slice(0, 2)
    .map((w) => Array.from(w)[0])
    .join('') || DEFAULT_USER.initials;

  async function handleLogout() {
    if (loggingOut) return;
    setLoggingOut(true);
    try {
      await logout();
    } catch (err) {
      // logout failsafe
    }
    navigate('/login', { replace: true });
    setLoggingOut(false);
  }

  return (
    <header className="sticky top-0 z-40 h-[58px] bg-gradient-to-r from-navy to-[#16264A] text-white border-b border-white/[.08]">
      <div className="mx-auto flex h-full max-w-[1280px] items-center gap-2 px-3 md:gap-4 md:px-6">
        <button
          type="button"
          onClick={() => setDrawerOpen(true)}
          aria-label={TEXT.menu}
          aria-haspopup="dialog"
          aria-expanded={drawerOpen}
          className={cx(ICON_BUTTON, 'lg:hidden')}
        >
          <Icon name="menu" size={20} />
        </button>

        <Brand className="shrink-0" />
        <span aria-hidden="true" className="hidden h-6 w-px shrink-0 bg-white/15 lg:block" />

        <div className="min-w-0 flex-1 overflow-x-auto scrollbar-none">
          <NavMenu entries={entries} label={TEXT.menu} className="hidden lg:flex" />
        </div>

        <div className="flex shrink-0 items-center gap-1.5 md:gap-3">
          <NotificationBell />

          <div className="sb-user flex items-center gap-2 rounded-lg py-1 px-1.5 transition-colors hover:bg-white/5">
            <span
              aria-hidden="true"
              className="av flex size-8 shrink-0 items-center justify-center rounded-full bg-teal text-[12px] font-bold text-white shadow-xs"
            >
              {initials}
            </span>
            <div className="hidden xl:flex flex-col text-right leading-tight">
              <span className="nm text-[12.5px] font-semibold text-white">{displayName}</span>
              <span className="rl text-[10px] text-[#8B96B3]">{displayRole}</span>
            </div>
          </div>

          <button
            type="button"
            onClick={handleLogout}
            disabled={loggingOut}
            aria-busy={loggingOut}
            className="hidden min-h-8 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-[#AEB8D2] transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold disabled:cursor-wait disabled:opacity-70 md:inline-flex xl:px-2.5"
          >
            {loggingOut ? <Spinner size="sm" /> : null}
            {TEXT.logout}
          </button>
        </div>
      </div>

      <MobileDrawer
        open={drawerOpen}
        onClose={closeDrawer}
        entries={entries}
        user={user}
        title={TEXT.menu}
        logoutLabel={TEXT.logout}
        onLogout={handleLogout}
        loggingOut={loggingOut}
      />
    </header>
  );
}

export { TopNavbar };
