import { cx } from '../../lib/cx.js';
import Alert from './Alert.jsx';
import Button from './Button.jsx';
import EmptyState from './EmptyState.jsx';
import { LoadingState } from './Loading.jsx';
import { UI_TEXT } from './uiText.js';

const EMPTY_CELL = '\u2014';
const PANEL = 'rounded-ds-lg border border-line bg-panel shadow-ds';
const ALIGN = { start: 'text-start', center: 'text-center', end: 'text-end' };
const TH =
  'whitespace-nowrap border-b-[1.5px] border-line px-3 py-[11px] text-[11px] font-bold uppercase tracking-wide text-ink-soft';
const TD = 'border-b border-line-soft px-3 py-3 align-middle';

function isBlank(value) {
  return value === null || value === undefined || value === '';
}

function cellContent(col, row, index) {
  if (!row) return EMPTY_CELL;
  const val = row[col.key];

  if (typeof col.render === 'function') {
    if (col.actions) {
      return col.render.length >= 2 ? col.render(val, row, index) : col.render(row, index);
    }
    // اگر render آرگومان‌های (val, row, index) می‌خواهد:
    if (col.render.length >= 2) {
      const out = col.render(val, row, index);
      return isBlank(out) ? EMPTY_CELL : out;
    }
    // اگر تک‌آرگومان است: ابتدا کل شیء row را پاس بدهیم
    const out = col.render(row, index);
    return isBlank(out) ? EMPTY_CELL : out;
  }

  return isBlank(val) ? EMPTY_CELL : val;
}

function resolveKey(row, index, rowKey) {
  const key = typeof rowKey === 'function' ? rowKey(row, index) : row ? row[rowKey] : undefined;
  return isBlank(key) ? `row-${index}` : key;
}

function errorText(error) {
  return typeof error === 'string' && error ? error : UI_TEXT.loadError;
}

// columns: [{ key, header, title, render?(val, row, index), align?, className?, headerClassName?, primary?, actions?, cardHidden? }]
// mobile="cards": a table from md up and vertical cards below 768px.
// mobile="scroll": the table stays and scrolls horizontally on small screens.
export default function DataTable({
  columns = [],
  rows,
  data, // alias for rows
  rowKey = 'id',
  loading = false,
  error = null,
  onRetry,
  emptyTitle,
  emptyDescription,
  emptyIcon,
  emptyAction,
  mobile = 'cards',
  minWidthClass = 'min-w-[640px]',
  caption,
  className,
}) {
  const list = Array.isArray(rows) ? rows : Array.isArray(data) ? data : [];
  const hasRows = list.length > 0;
  const cards = mobile === 'cards';
  const showError = Boolean(error) && !loading;

  if (loading && !hasRows) {
    return (
      <div className={cx(PANEL, className)}>
        <LoadingState />
      </div>
    );
  }

  const errorNode = showError ? (
    <Alert
      tone="error"
      title={errorText(error)}
      action={
        typeof onRetry === 'function' ? (
          <Button size="sm" variant="outline" onClick={onRetry}>
            {UI_TEXT.retry}
          </Button>
        ) : null
      }
    />
  ) : null;

  if (!hasRows) {
    if (showError) return <div className={className}>{errorNode}</div>;
    return (
      <EmptyState
        className={className}
        icon={emptyIcon}
        title={emptyTitle}
        description={emptyDescription}
        action={emptyAction}
      />
    );
  }

  const primary = columns.find((c) => c.primary) || columns.find((c) => !c.actions);
  const detailCols = columns.filter((c) => c !== primary && !c.actions && !c.cardHidden);
  const actionCol = columns.find((c) => c.actions);

  return (
    <div
      aria-busy={loading || undefined}
      className={cx('flex flex-col gap-3', loading && 'pointer-events-none opacity-60 transition-opacity', className)}
    >
      {errorNode}

      <div className={cx('overflow-x-auto', PANEL, cards ? 'hidden md:block' : 'block')}>
        <table className={cx('w-full border-collapse text-[13px]', minWidthClass)}>
          {caption ? <caption className="sr-only">{caption}</caption> : null}
          <thead>
            <tr>
              {columns.map((col, ci) => (
                <th
                  key={col.key ?? `col-${ci}`}
                  scope="col"
                  className={cx(TH, ALIGN[col.align] || 'text-start', col.headerClassName)}
                >
                  {col.header ?? col.title}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {list.map((row, ri) => (
              <tr key={resolveKey(row, ri, rowKey)} className="hover:bg-row-hover [&:last-child>td]:border-b-0">
                {columns.map((col, ci) => (
                  <td key={col.key ?? `col-${ci}`} className={cx(TD, ALIGN[col.align] || 'text-start', col.className)}>
                    {cellContent(col, row, ri)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {cards ? (
        <ul className="m-0 flex list-none flex-col gap-3 p-0 md:hidden">
          {list.map((row, ri) => (
            <li key={resolveKey(row, ri, rowKey)} className={cx(PANEL, 'p-4')}>
              {primary ? (
                <div className={cx('text-sm font-bold text-navy', detailCols.length > 0 && 'mb-2')}>
                  {cellContent(primary, row, ri)}
                </div>
              ) : null}
              {detailCols.length > 0 ? (
                <dl className="m-0 divide-y divide-line-soft">
                  {detailCols.map((col, ci) => (
                    <div
                      key={col.key ?? `col-${ci}`}
                      className="flex items-start justify-between gap-3 py-2 first:pt-0 last:pb-0"
                    >
                      <dt className="shrink-0 text-xs font-semibold text-ink-soft">{col.header ?? col.title}</dt>
                      <dd className="m-0 min-w-0 break-words text-end text-[13px]">{cellContent(col, row, ri)}</dd>
                    </div>
                  ))}
                </dl>
              ) : null}
              {actionCol ? (
                <div className="mt-3 flex flex-wrap gap-2 border-t border-line-soft pt-3">
                  {cellContent(actionCol, row, ri)}
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

export { DataTable };
