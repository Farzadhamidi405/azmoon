import { useCallback, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../hooks/useAuth.js';
import { buildNavigation } from '../../config/navigation.js';
import { cx } from '../../lib/cx.js';
import { Icon, Spinner } from '../ui/index.js';
import Brand from './Brand.jsx';
import MobileDrawer from './MobileDrawer.jsx';
import NavMenu from './NavMenu.jsx';
import NotificationBell from './NotificationBell.jsx';

const TEXT = Object.freeze({
  menu: 'منو',
  logout: 'خروج',
});

const DEFAULT_USER = Object.freeze({
  name: 'ایمل صافی',
  role: 'Exam Administrator',
  initials: 'ای',
});

const ICON_BUTTON =
  'inline-flex size-10 shrink-0 items-center justify-center rounded-lg text-[#AEB8D2] transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold cursor-pointer';

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
    <header
      className="sticky top-0 z-50 w-full h-[58px] md:h-16 text-white border-b border-white/10 shadow-sm shrink-0 overflow-visible"
      style={{
        background: 'linear-gradient(90deg, #152341 0%, #16264A 100%)',
        backgroundColor: '#152341',
      }}
    >
      <div className="mx-auto flex h-full max-w-[1280px] items-center gap-2 px-3 md:gap-4 md:px-6 overflow-visible">
        {/* دکمه منوی موبایل (MobileDrawer toggle) — فقط در عرض‌های کمتر از 1024px */}
        <button
          type="button"
          onClick={() => setDrawerOpen(true)}
          aria-label={TEXT.menu}
          aria-haspopup="dialog"
          aria-expanded={drawerOpen}
          className={cx(ICON_BUTTON, 'lg:hidden')}
        >
          <Icon name="menu" size={22} />
        </button>

        {/* لوگو و نام پوهنتون با قابلیت کلیک برای بازگشت به داشبورد */}
        <Link to="/admin/dashboard" className="shrink-0 flex items-center">
          <Brand className="shrink-0" />
        </Link>

        {/* خط جداکننده عمودی فقط در دسکتاپ */}
        <span aria-hidden="true" className="hidden h-6 w-px shrink-0 bg-white/15 lg:block" />

        {/* منوی ناوبری دسکتاپ — در موبایل (< 1024px) کاملاً پنهان و در دسکتاپ (>= 1024px) فعال */}
        <div className="hidden lg:flex min-w-0 flex-1 items-center overflow-visible">
          <NavMenu entries={entries} label={TEXT.menu} className="flex" />
        </div>

        {/* آیکون‌ها و مشخصات کاربر سمت چپ هدر */}
        <div className="flex shrink-0 items-center gap-1.5 md:gap-3 ms-auto lg:ms-0">
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
            className="hidden min-h-8 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-[#AEB8D2] transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold disabled:cursor-wait disabled:opacity-70 md:inline-flex xl:px-2.5 cursor-pointer"
          >
            {loggingOut ? <Spinner size="sm" /> : null}
            {TEXT.logout}
          </button>
        </div>
      </div>

      {/* دراور مخصوص موبایل */}
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
