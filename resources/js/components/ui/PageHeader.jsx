import { cx } from '../../lib/cx.js';

// crumb: a string or an array of strings; the last item is emphasised (prototype .crumb).
export default function PageHeader({ title, crumb, actions, className }) {
  const items = Array.isArray(crumb) ? crumb : crumb ? [crumb] : [];
  return (
    <div className={cx('mb-5 flex flex-col gap-3 md:flex-row md:items-start md:justify-between', className)}>
      <div className="min-w-0">
        {items.length > 0 ? (
          <nav aria-label="breadcrumb" className="mb-1 text-[11.5px] text-ink-soft">
            {items.map((item, i) => (
              <span key={i}>
                {i > 0 ? ' / ' : null}
                {i === items.length - 1 ? <b className="font-semibold text-navy">{item}</b> : item}
              </span>
            ))}
          </nav>
        ) : null}
        <h1 className="text-xl font-extrabold text-navy">{title}</h1>
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
    </div>
  );
}

export { PageHeader };
