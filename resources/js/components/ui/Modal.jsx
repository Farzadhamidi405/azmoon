import { useId } from 'react';
import { cx } from '../../lib/cx.js';
import Overlay from './Overlay.jsx';
import { UI_TEXT } from './uiText.js';

const SIZES = {
  sm: 'md:max-w-sm',
  md: 'md:max-w-lg',
  lg: 'md:max-w-2xl',
  xl: 'md:max-w-4xl',
};

const CLOSE_BUTTON =
  'flex size-10 shrink-0 items-center justify-center rounded-ds-sm text-2xl leading-none text-ink-soft transition-colors hover:bg-chip hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-3';

// Full-screen on mobile, centered dialog from the md breakpoint. `compact` keeps a small centered card on mobile too.
export default function Modal({
  open,
  onClose,
  title,
  description,
  footer,
  size = 'md',
  compact = false,
  dismissible = true,
  role = 'dialog',
  initialFocusRef,
  className,
  bodyClassName,
  children,
}) {
  const uid = useId();
  const titleId = `${uid}-title`;
  const descId = `${uid}-desc`;
  const hasBody = Boolean(description || children);

  const wrapperClassName = compact
    ? 'items-center justify-center p-4 md:p-6'
    : 'items-stretch justify-center md:items-center md:p-6';

  const shapeClassName = compact
    ? 'h-auto max-h-full w-full rounded-ds-lg border border-line shadow-[0_20px_60px_rgba(21,35,65,0.28)]'
    : 'h-full w-full md:h-auto md:max-h-full md:rounded-ds-lg md:border md:border-line md:shadow-[0_20px_60px_rgba(21,35,65,0.28)]';

  return (
    <Overlay
      open={open}
      onClose={onClose}
      dismissible={dismissible}
      role={role}
      labelledBy={title ? titleId : undefined}
      describedBy={description ? descId : undefined}
      initialFocusRef={initialFocusRef}
      wrapperClassName={wrapperClassName}
      panelClassName={cx('flex flex-col bg-panel text-ink', shapeClassName, SIZES[size] || SIZES.md, className)}
    >
      {title || dismissible ? (
        <div className="flex items-start gap-3 border-b border-line-soft px-4 py-3 md:px-5">
          <div className="min-w-0 flex-1 py-1.5">
            {title ? (
              <h2 id={titleId} className="m-0 text-[15px] font-bold leading-7 text-navy">
                {title}
              </h2>
            ) : null}
          </div>
          {dismissible ? (
            <button type="button" onClick={onClose} aria-label={UI_TEXT.close} className={CLOSE_BUTTON}>
              {'\u00D7'}
            </button>
          ) : null}
        </div>
      ) : null}

      {hasBody ? (
        <div className={cx('min-h-0 flex-1 overflow-y-auto px-4 py-4 md:px-5', bodyClassName)}>
          {description ? (
            <p id={descId} className={cx('m-0 text-[13px] leading-6 text-ink-soft', children && 'mb-3')}>
              {description}
            </p>
          ) : null}
          {children}
        </div>
      ) : null}

      {footer ? (
        <div className="flex flex-col gap-2 border-t border-line-soft px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:flex-row sm:flex-wrap md:px-5 [&>*]:w-full sm:[&>*]:w-auto">
          {footer}
        </div>
      ) : null}
    </Overlay>
  );
}

export { Modal };
