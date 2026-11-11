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

const FALLBACK_LETTER = '\u06A9';

const ICON_BUTTON =
  'inline-flex size-10 shrink-0 items-center justify-center rounded-lg text-nav-ink transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold';

// Admin top bar: hamburger (below lg), brand, desktop menu (lg and up), bell, avatar, logout.
// No transform / backdrop-filter on the header, so fixed children (Drawer) are never trapped.
export default function TopNavbar() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const entries = useMemo(() => buildNavigation(user), [user]);
  const closeDrawer = useCallback(() => setDrawerOpen(false), []);

  const name = user && typeof user.name === 'string' ? user.name.trim() : '';
  const letter = name ? Array.from(name)[0] : FALLBACK_LETTER;

  async function handleLogout() {
    if (loggingOut) return;
    setLoggingOut(true);
    try {
      await logout();
    } catch (err) {
      // logout() never rejects; this only guards against unexpected errors.
    }
    navigate('/login', { replace: true });
    setLoggingOut(false);
  }

  return (
    <header className="sticky top-0 z-40 h-14 bg-linear-to-r from-navy to-[#16264A] text-white md:h-16">
      <div className="mx-auto flex h-full max-w-[1280px] items-center gap-2 px-3 md:gap-3 md:px-6">
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
        <span aria-hidden="true" className="hidden h-5 w-px shrink-0 bg-white/20 lg:block" />

        <div className="min-w-0 flex-1">
          <NavMenu entries={entries} label={TEXT.menu} className="hidden lg:flex" />
        </div>

        <div className="flex shrink-0 items-center gap-1 xl:gap-3">
          <NotificationBell />

          <div className="flex items-center gap-2">
            <span
              aria-hidden="true"
              className="flex size-7 shrink-0 items-center justify-center rounded-full bg-teal text-[11px] font-bold text-white"
            >
              {letter}
            </span>
            {name ? <span className="max-w-40 truncate text-xs font-semibold text-white max-xl:sr-only">{name}</span> : null}
          </div>

          <button
            type="button"
            onClick={handleLogout}
            disabled={loggingOut}
            aria-busy={loggingOut}
            className="hidden min-h-10 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-nav-ink transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold disabled:cursor-wait disabled:opacity-70 md:inline-flex xl:px-3"
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
