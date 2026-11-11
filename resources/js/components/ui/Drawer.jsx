import { useId } from 'react';
import { cx } from '../../lib/cx.js';
import Overlay from './Overlay.jsx';
import { UI_TEXT } from './uiText.js';

const WIDTHS = {
  sm: 'w-72',
  md: 'w-80',
  lg: 'w-96',
};

const TONES = {
  light: {
    panel: 'bg-panel text-ink',
    line: 'border-line-soft',
    title: 'text-navy',
    close: 'text-ink-soft hover:bg-chip hover:text-ink focus-visible:outline-navy-3',
  },
  navy: {
    panel: 'bg-navy text-white',
    line: 'border-white/10',
    title: 'text-white',
    close: 'text-white/80 hover:bg-white/10 hover:text-white focus-visible:outline-gold',
  },
};

// Side panel anchored to the inline-start edge (right in RTL); `side="end"` anchors it to the opposite edge.
export default function Drawer({
  open,
  onClose,
  title,
  label,
  footer,
  side = 'start',
  size = 'md',
  tone = 'light',
  dismissible = true,
  padded = true,
  initialFocusRef,
  className,
  bodyClassName,
  children,
}) {
  const uid = useId();
  const titleId = `${uid}-title`;
  const t = TONES[tone] || TONES.light;

  return (
    <Overlay
      open={open}
      onClose={onClose}
      dismissible={dismissible}
      label={title ? undefined : label}
      labelledBy={title ? titleId : undefined}
      initialFocusRef={initialFocusRef}
      wrapperClassName={side === 'end' ? 'justify-end' : 'justify-start'}
      panelClassName={cx(
        'flex h-full max-w-[88vw] flex-col shadow-[0_20px_60px_rgba(21,35,65,0.28)]',
        WIDTHS[size] || WIDTHS.md,
        t.panel,
        className,
      )}
    >
      {title || dismissible ? (
        <div className={cx('flex items-center gap-3 border-b px-4 py-2', t.line)}>
          <div className="min-w-0 flex-1">
            {title ? (
              <h2 id={titleId} className={cx('m-0 text-[15px] font-bold leading-7', t.title)}>
                {title}
              </h2>
            ) : null}
          </div>
          {dismissible ? (
            <button
              type="button"
              onClick={onClose}
              aria-label={UI_TEXT.close}
              className={cx(
                'flex size-10 shrink-0 items-center justify-center rounded-ds-sm text-2xl leading-none transition-colors focus-visible:outline-2 focus-visible:outline-offset-2',
                t.close,
              )}
            >
              {'\u00D7'}
            </button>
          ) : null}
        </div>
      ) : null}

      <div className={cx('min-h-0 flex-1 overflow-y-auto overscroll-contain', padded && 'px-4 py-4', bodyClassName)}>
        {children}
      </div>

      {footer ? (
        <div className={cx('border-t px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]', t.line)}>{footer}</div>
      ) : null}
    </Overlay>
  );
}

export { Drawer };
