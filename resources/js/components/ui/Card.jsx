import { cx } from '../../lib/cx.js';

export default function Card({ as: Component = 'div', title, actions, padded = true, className, children, ...rest }) {
  return (
    <Component
      {...rest}
      className={cx('rounded-ds-lg border border-line bg-panel shadow-ds', padded && 'p-4 md:p-5', className)}
    >
      {title || actions ? (
        <div
          className={cx(
            'mb-3 flex flex-wrap items-center justify-between gap-2',
            !padded && 'px-4 pt-4 md:px-5 md:pt-5',
          )}
        >
          {title ? <h2 className="text-[13.5px] font-bold text-navy">{title}</h2> : <span />}
          {actions}
        </div>
      ) : null}
      {children}
    </Component>
  );
}

export function SectionTitle({ children, className }) {
  return (
    <h3
      className={cx(
        'mb-3 mt-5 border-b border-line pb-2 text-[12.5px] font-bold uppercase tracking-[0.03em] text-navy',
        className,
      )}
    >
      {children}
    </h3>
  );
}

export { Card };
