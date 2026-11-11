import { cx } from '../../lib/cx.js';
import Icon from './Icon.jsx';
import { UI_TEXT } from './uiText.js';

const TONES = {
  info: 'border-navy-3/30 bg-chip',
  success: 'border-teal/30 bg-teal-soft',
  warning: 'border-amber/30 bg-amber-soft',
  error: 'border-red/30 bg-red-soft',
};

const ICON_TONES = {
  info: 'text-navy-3',
  success: 'text-teal',
  warning: 'text-amber',
  error: 'text-red',
};

function ToneIcon({ tone }) {
  if (tone === 'warning' || tone === 'error') return <Icon name="alert" size={18} />;
  if (tone === 'success') return <span className="text-base font-bold leading-6">{'\u2713'}</span>;
  return null;
}

export default function Alert({ tone = 'info', title, children, action, onClose, className }) {
  const known = TONES[tone] ? tone : 'info';
  return (
    <div
      role={known === 'error' ? 'alert' : 'status'}
      className={cx('flex items-start gap-3 rounded-ds border px-4 py-3 text-[13px] leading-6 text-ink', TONES[known], className)}
    >
      {known !== 'info' ? (
        <span aria-hidden="true" className={cx('mt-0.5 shrink-0', ICON_TONES[known])}>
          <ToneIcon tone={known} />
        </span>
      ) : null}
      <div className="min-w-0 flex-1">
        {title ? <div className="font-bold">{title}</div> : null}
        {children ? <div>{children}</div> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
      {onClose ? (
        <button
          type="button"
          onClick={onClose}
          aria-label={UI_TEXT.close}
          className="min-h-8 min-w-8 shrink-0 rounded-ds-sm text-lg leading-none opacity-70 hover:opacity-100"
        >
          {'\u00D7'}
        </button>
      ) : null}
    </div>
  );
}

export { Alert };
