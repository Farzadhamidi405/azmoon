import { cx } from '../../lib/cx.js';

// Variants follow the prototype: on / pending / off / bad.
const VARIANTS = {
  on: 'bg-teal-soft text-teal',
  pending: 'bg-amber-soft text-amber',
  off: 'bg-chip text-ink-soft',
  bad: 'bg-red-soft text-red',
};

export default function Badge({ variant = 'off', dot = true, className, children }) {
  return (
    <span
      className={cx(
        'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold',
        VARIANTS[variant] || VARIANTS.off,
        className,
      )}
    >
      {dot ? <span aria-hidden="true" className="size-1.5 rounded-full bg-current" /> : null}
      {children}
    </span>
  );
}

export { Badge };
