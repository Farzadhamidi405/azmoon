import { cx } from '../../lib/cx.js';
import { UI_TEXT } from './uiText.js';

const SPINNER_SIZES = {
  sm: 'size-4 border-2',
  md: 'size-6 border-2',
  lg: 'size-9 border-[3px]',
};

export function Spinner({ size = 'md', className }) {
  return (
    <span
      aria-hidden="true"
      className={cx(
        'inline-block animate-spin rounded-full border-current border-t-transparent',
        SPINNER_SIZES[size] || SPINNER_SIZES.md,
        className,
      )}
    />
  );
}

export function LoadingState({ label = UI_TEXT.loading, className }) {
  return (
    <div
      role="status"
      aria-live="polite"
      className={cx('flex flex-col items-center justify-center gap-3 px-4 py-10 text-sm text-ink-soft', className)}
    >
      <Spinner size="lg" className="text-navy" />
      <span>{label}</span>
    </div>
  );
}

export function Skeleton({ className }) {
  return (
    <div aria-hidden="true" className={cx('animate-pulse rounded-ds-sm bg-line-soft', className || 'h-4 w-full')} />
  );
}

export default LoadingState;
