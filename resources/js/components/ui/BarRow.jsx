import { cx } from '../../lib/cx.js';

export function BarList({ className, children }) {
  return <div className={cx('flex flex-col gap-3', className)}>{children}</div>;
}

// Pure CSS bar. value/max decide the width; `display` is the text drawn inside the bar.
export default function BarRow({ label, value, max = 100, display, className }) {
  const n = Number(value);
  const m = Number(max);
  const pct = Number.isFinite(n) && Number.isFinite(m) && m > 0 ? Math.min(100, Math.max(0, (n / m) * 100)) : 0;
  return (
    <div className={cx('flex items-center gap-3', className)}>
      <div className="w-24 shrink-0 text-[12.5px] text-ink-soft md:w-28">{label}</div>
      <div
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.round(pct)}
        aria-label={typeof label === 'string' ? label : undefined}
        className="h-5 min-w-0 flex-1 overflow-hidden rounded-[5px] bg-line-soft"
      >
        <div
          className="flex h-full items-center justify-end rounded-[5px] bg-navy-3 pe-2"
          style={{ width: pct + '%' }}
        >
          {display !== undefined && display !== null ? (
            <span className="whitespace-nowrap text-[10.5px] font-bold text-white">{display}</span>
          ) : null}
        </div>
      </div>
    </div>
  );
}

export { BarRow };
