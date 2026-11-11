import { cx } from '../../lib/cx.js';
import Icon from './Icon.jsx';
import { UI_TEXT } from './uiText.js';

export default function EmptyState({ icon, title, description, action, bare = false, className }) {
  return (
    <div
      className={cx(
        'flex flex-col items-center px-6 py-10 text-center md:py-12',
        !bare && 'rounded-ds-lg border border-line bg-panel shadow-ds',
        className,
      )}
    >
      {icon ? (
        <span className="mb-3 flex size-14 items-center justify-center rounded-full bg-chip text-navy">
          <Icon name={icon} size={24} />
        </span>
      ) : null}
      <div className="mb-1.5 text-sm font-bold text-navy">{title || UI_TEXT.empty}</div>
      {description ? <div className="max-w-md text-xs leading-6 text-ink-soft">{description}</div> : null}
      {action ? <div className="mt-4">{action}</div> : null}
    </div>
  );
}

export { EmptyState };
