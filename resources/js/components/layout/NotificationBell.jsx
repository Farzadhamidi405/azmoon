import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../../hooks/useAuth';
import { canSeeNotifications } from '../../lib/permissions.js';
import { cx } from '../../lib/cx.js';
import useDismiss from '../../hooks/useDismiss.js';
import useMediaQuery from '../../hooks/useMediaQuery.js';
import useNotifications from '../../hooks/useNotifications.js';
import { Drawer, Icon } from '../ui/index.js';
import NotificationPanel, { PANEL_TEXT } from './NotificationPanel.jsx';

// Bell + unread counter. Desktop (md and up): popover closed by useDismiss. Mobile: Drawer from the end edge.
// Without notifications.view the component renders nothing and the hook sends no request.
export default function NotificationBell({ className }) {
  const { user } = useAuth();
  const enabled = canSeeNotifications(user);
  const isDesktop = useMediaQuery('(min-width: 48rem)');
  const { pathname } = useLocation();
  const panelId = useId();
  const wrapRef = useRef(null);
  const buttonRef = useRef(null);
  const [open, setOpen] = useState(false);
  const { unreadCount, items, loading, loaded, error, busy, refreshCount, loadList, markRead, markAllRead } =
    useNotifications({ enabled });

  const close = useCallback(() => setOpen(false), []);

  useEffect(() => {
    setOpen(false);
  }, [pathname]);

  useDismiss({ open: open && isDesktop, onClose: close, containerRef: wrapRef, restoreFocusRef: buttonRef });

  if (!enabled) return null;

  const hasCount = typeof unreadCount === 'number' && unreadCount > 0;
  const shown = hasCount && unreadCount > 99 ? '99+' : String(unreadCount);
  const label = hasCount ? `${PANEL_TEXT.title}: ${shown} ${PANEL_TEXT.unread}` : PANEL_TEXT.title;
  const hasUnread = hasCount || items.some((row) => !row.is_read);

  function toggle() {
    if (open) {
      close();
      return;
    }
    setOpen(true);
    loadList();
    refreshCount();
  }

  const panel = (
    <NotificationPanel
      items={items}
      loading={loading}
      loaded={loaded}
      error={error}
      busy={busy}
      hasUnread={hasUnread}
      showTitle={isDesktop}
      onItemClick={markRead}
      onMarkAll={markAllRead}
      onRetry={() => loadList()}
      onNavigate={close}
    />
  );

  return (
    <div ref={wrapRef} className={cx('relative', className)}>
      <button
        ref={buttonRef}
        type="button"
        onClick={toggle}
        aria-label={label}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-controls={open && isDesktop ? panelId : undefined}
        className="relative inline-flex size-10 items-center justify-center rounded-lg text-nav-ink transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold"
      >
        <Icon name="bell" size={18} />
        {hasCount ? (
          <span
            aria-hidden="true"
            className="absolute end-1 top-1 min-w-4 rounded-full bg-red px-1 text-center text-[10px] font-bold leading-4 text-white"
          >
            {shown}
          </span>
        ) : null}
      </button>

      {open && isDesktop ? (
        <div
          id={panelId}
          role="dialog"
          aria-label={PANEL_TEXT.title}
          className="absolute end-0 top-11 z-[60] w-[300px] max-w-[calc(100vw-1.5rem)] rounded-xl border border-line bg-panel p-2 shadow-ds"
        >
          {panel}
        </div>
      ) : null}

      <Drawer
        open={open && !isDesktop}
        onClose={close}
        side="end"
        size="md"
        title={PANEL_TEXT.title}
        padded={false}
        bodyClassName="p-2"
      >
        {panel}
      </Drawer>
    </div>
  );
}

export { NotificationBell };
