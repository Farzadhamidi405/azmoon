import { cx } from '../../lib/cx.js';
import { Skeleton } from './Loading.jsx';

const ACCENTS = {
  gold: 'bg-gold',
  teal: 'bg-teal',
  navy: 'bg-navy',
  amber: 'bg-amber',
  red: 'bg-red',
};

const GRID = {
  3: 'sm:grid-cols-2 lg:grid-cols-3',
  4: 'sm:grid-cols-2 xl:grid-cols-4',
};

// 1 column on mobile, 2 on small/tablet, 3 or 4 on desktop.
export function StatGrid({ cols = 4, className, children }) {
  return <div className={cx('grid grid-cols-1 gap-3.5', GRID[cols] || GRID[4], className)}>{children}</div>;
}

// A missing value is shown as an em dash; no placeholder numbers are ever invented.
export default function StatCard({ label, value, hint, accent = 'gold', loading = false, className }) {
  const missing = value === null || value === undefined || value === '';
  return (
    <div
      aria-busy={loading || undefined}
      className={cx('relative overflow-hidden rounded-ds-lg border border-line bg-panel px-4 py-4 shadow-ds', className)}
    >
      <span aria-hidden="true" className={cx('absolute inset-y-0 start-0 w-1', ACCENTS[accent] || ACCENTS.gold)} />
      {loading ? (
        <Skeleton className="h-8 w-20" />
      ) : (
        <div className="text-2xl font-extrabold text-navy md:text-[26px]">{missing ? '\u2014' : value}</div>
      )}
      <div className="mt-0.5 text-xs text-ink-soft">{label}</div>
      {hint && !loading ? <div className="mt-1 text-[11px] text-ink-soft">{hint}</div> : null}
    </div>
  );
}

export { StatCard };
