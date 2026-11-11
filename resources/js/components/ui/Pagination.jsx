import { cx } from '../../lib/cx.js';
import Button from './Button.jsx';
import { UI_TEXT } from './uiText.js';

const GAP = '\u2026';

function toInt(value, fallback) {
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : fallback;
}

// Window: first, last and the current page with its neighbours; single missing pages are shown instead of a gap.
function buildPages(current, last) {
  const wanted = new Set([1, last, current - 1, current, current + 1]);
  const sorted = Array.from(wanted)
    .filter((n) => n >= 1 && n <= last)
    .sort((a, b) => a - b);
  const out = [];
  sorted.forEach((n, i) => {
    const prev = sorted[i - 1];
    if (prev !== undefined) {
      if (n - prev === 2) out.push(prev + 1);
      else if (n - prev > 2) out.push(`gap-${n}`);
    }
    out.push(n);
  });
  return out;
}

// `pagination` is the object returned by unwrapList(): { currentPage, lastPage, total, ... } or null.
export default function Pagination({ pagination, onPageChange, disabled = false, className }) {
  if (!pagination) return null;

  const last = Math.max(1, toInt(pagination.lastPage, 1));
  if (last <= 1) return null;
  const current = Math.min(Math.max(1, toInt(pagination.currentPage, 1)), last);
  const total = pagination.total;
  const hasTotal = total !== null && total !== undefined && total !== '';
  const pages = buildPages(current, last);

  const go = (page) => {
    if (disabled || page === current || page < 1 || page > last) return;
    if (typeof onPageChange === 'function') onPageChange(page);
  };

  return (
    <nav
      aria-label={UI_TEXT.paginationLabel}
      className={cx('flex flex-wrap items-center justify-between gap-3', className)}
    >
      <p className="m-0 text-xs text-ink-soft" aria-live="polite">
        {`${UI_TEXT.page} ${current} ${UI_TEXT.of} ${last}`}
        {hasTotal ? <span className="ms-3">{`${UI_TEXT.total}: ${total}`}</span> : null}
      </p>

      <div className="flex items-center gap-1.5">
        <Button size="sm" variant="outline" disabled={disabled || current <= 1} onClick={() => go(current - 1)}>
          {UI_TEXT.prev}
        </Button>

        <div className="hidden items-center gap-1.5 sm:flex">
          {pages.map((p) =>
            typeof p === 'number' ? (
              <Button
                key={p}
                size="sm"
                variant={p === current ? 'primary' : 'outline'}
                disabled={disabled}
                aria-current={p === current ? 'page' : undefined}
                className="min-w-10 px-2 md:min-w-8"
                onClick={() => go(p)}
              >
                {p}
              </Button>
            ) : (
              <span key={p} aria-hidden="true" className="px-1 text-ink-soft">
                {GAP}
              </span>
            ),
          )}
        </div>

        <Button size="sm" variant="outline" disabled={disabled || current >= last} onClick={() => go(current + 1)}>
          {UI_TEXT.next}
        </Button>
      </div>
    </nav>
  );
}

export { Pagination };
