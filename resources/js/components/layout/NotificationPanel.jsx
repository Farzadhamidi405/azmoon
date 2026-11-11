import { Link } from 'react-router-dom';
import { cx } from '../../lib/cx.js';
import { Alert, Button, EmptyState, Skeleton, UI_TEXT } from '../ui/index.js';

export const PANEL_TEXT = Object.freeze({
  title: '\u0627\u0639\u0644\u0627\u0646\u200C\u0647\u0627',
  markAll: '\u0647\u0645\u0647 \u0631\u0627 \u062E\u0648\u0627\u0646\u062F\u0647 \u06A9\u0646',
  viewAll: '\u0645\u0634\u0627\u0647\u062F\u0647\u0654 \u0647\u0645\u0647',
  empty: '\u0627\u0639\u0644\u0627\u0646 \u062C\u062F\u06CC\u062F\u06CC \u0648\u062C\u0648\u062F \u0646\u062F\u0627\u0631\u062F',
  unread: '\u062E\u0648\u0627\u0646\u062F\u0647\u200C\u0646\u0634\u062F\u0647',
});

// Persian (Jalali) calendar with Latin digits; falls back to an ISO-like string when Intl cannot do it.
let formatter = null;
let formatterReady = false;

function getFormatter() {
  if (!formatterReady) {
    formatterReady = true;
    try {
      formatter = new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-latn', { dateStyle: 'short', timeStyle: 'short' });
    } catch (err) {
      formatter = null;
    }
  }
  return formatter;
}

function formatWhen(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  const fmt = getFormatter();
  if (fmt) {
    try {
      return fmt.format(date);
    } catch (err) {
      // fall through to the ISO-like string
    }
  }
  return date.toISOString().replace('T', ' ').slice(0, 16);
}

function SkeletonRows() {
  return (
    <div role="status" aria-busy="true">
      <span className="sr-only">{UI_TEXT.loading}</span>
      <ul aria-hidden="true" className="m-0 list-none p-0">
        {[0, 1, 2].map((n) => (
          <li key={n} className="flex gap-2.5 border-b border-line-soft px-2 py-2.5 last:border-b-0">
            <Skeleton className="mt-1.5 size-2 shrink-0 rounded-full" />
            <div className="flex-1 space-y-2">
              <Skeleton className="h-3 w-2/3" />
              <Skeleton className="h-3 w-full" />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

// Presentational only: data and actions come from the parent (useNotifications lives outside, because the
// mobile Drawer unmounts its content when it closes).
export default function NotificationPanel({
  items = [],
  loading = false,
  loaded = false,
  error = null,
  busy = false,
  hasUnread = false,
  showTitle = true,
  viewAllTo = '/admin/notifications',
  onItemClick,
  onMarkAll,
  onRetry,
  onNavigate,
  className,
}) {
  const hasItems = items.length > 0;
  const showSkeleton = loading && !hasItems;
  const showEmpty = loaded && !loading && !error && !hasItems;

  return (
    <div className={cx('text-ink', className)}>
      <div className="flex items-center justify-between gap-2 px-2 pb-2 pt-1">
        {showTitle ? <div className="text-[13px] font-bold text-navy">{PANEL_TEXT.title}</div> : <span />}
        <button
          type="button"
          onClick={onMarkAll}
          disabled={busy || !hasUnread}
          className="min-h-9 shrink-0 rounded-ds-sm px-2 text-[11.5px] font-bold text-teal hover:bg-teal-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent md:min-h-8"
        >
          {PANEL_TEXT.markAll}
        </button>
      </div>

      {error ? (
        <div className="px-1 pb-2">
          <Alert
            tone="error"
            action={
              <Button size="sm" variant="outline" onClick={onRetry} disabled={loading}>
                {UI_TEXT.retry}
              </Button>
            }
          >
            {error}
          </Alert>
        </div>
      ) : null}

      {showSkeleton ? <SkeletonRows /> : null}

      {showEmpty ? <EmptyState bare icon="bell" title={PANEL_TEXT.empty} /> : null}

      {hasItems ? (
        <ul className="m-0 list-none p-0">
          {items.map((item) => {
            const unread = !item.is_read;
            return (
              <li key={item.id} className="border-b border-line-soft last:border-b-0">
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    if (onItemClick) onItemClick(item);
                  }}
                  className="flex min-h-11 w-full items-start gap-2.5 rounded-lg px-2 py-2.5 text-start hover:bg-[#F6F4EE] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-navy-3 disabled:cursor-wait disabled:opacity-70"
                >
                  <span
                    aria-hidden="true"
                    className={cx('mt-1.5 size-2 shrink-0 rounded-full', unread ? 'bg-red' : 'bg-navy-3')}
                  />
                  <span className="min-w-0 flex-1">
                    <span className={cx('block text-[12.5px] leading-5', unread ? 'font-bold text-navy' : 'font-normal text-ink')}>
                      {unread ? <span className="sr-only">{PANEL_TEXT.unread}: </span> : null}
                      {item.title}
                    </span>
                    {item.body ? <span className="line-clamp-2 text-xs leading-5 text-ink-soft">{item.body}</span> : null}
                    <span className="mt-0.5 block text-[10.5px] text-ink-soft">{formatWhen(item.created_at)}</span>
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      ) : null}

      <div className="mt-1 border-t border-line-soft pt-2 text-center">
        <Link
          to={viewAllTo}
          onClick={onNavigate}
          className="inline-flex min-h-10 items-center justify-center rounded-ds-sm px-3 text-xs font-bold text-navy-3 hover:bg-chip hover:text-navy focus-visible:outline-2 focus-visible:outline-navy-3 md:min-h-9"
        >
          {PANEL_TEXT.viewAll}
        </Link>
      </div>
    </div>
  );
}

export { NotificationPanel };
