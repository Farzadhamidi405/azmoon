import { cx } from '../../lib/cx.js';
import Icon from './Icon.jsx';
import { Spinner } from './Loading.jsx';

const BASE =
  'inline-flex items-center justify-center gap-1.5 rounded-ds-sm border border-transparent font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-3 disabled:cursor-not-allowed disabled:opacity-50 aria-[disabled=true]:pointer-events-none aria-[disabled=true]:opacity-50';

const VARIANTS = {
  primary: 'bg-navy text-white [&:not(:disabled)]:hover:bg-navy-3',
  gold: 'bg-gold text-[#241D0B] [&:not(:disabled)]:hover:brightness-95',
  outline: 'border-line bg-white text-ink [&:not(:disabled)]:hover:bg-row-hover',
  teal: 'bg-teal text-white [&:not(:disabled)]:hover:brightness-110',
  danger: 'bg-red text-white [&:not(:disabled)]:hover:brightness-110',
  ghost: 'bg-transparent text-navy [&:not(:disabled)]:hover:bg-chip',
};

const SIZES = {
  md: 'min-h-11 px-4 py-2 text-[13px] md:min-h-10',
  sm: 'min-h-10 px-3 py-1.5 text-xs md:min-h-8',
};

// `as` lets a page render a router Link with the same look. Loading also disables the control.
export default function Button({
  as: Component = 'button',
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled = false,
  block = false,
  icon,
  type,
  className,
  children,
  ...rest
}) {
  const inactive = disabled || loading;
  const extra =
    Component === 'button'
      ? { type: type || 'button', disabled: inactive }
      : { 'aria-disabled': inactive ? 'true' : undefined, tabIndex: inactive ? -1 : undefined };
  return (
    <Component
      {...rest}
      {...extra}
      aria-busy={loading || undefined}
      className={cx(BASE, VARIANTS[variant] || VARIANTS.primary, SIZES[size] || SIZES.md, block && 'w-full', className)}
    >
      {loading ? <Spinner size="sm" /> : icon ? <Icon name={icon} size={16} /> : null}
      {children}
    </Component>
  );
}

export { Button };
