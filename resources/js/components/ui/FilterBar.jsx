import { cx } from '../../lib/cx.js';
import { Input, Select } from './Field.jsx';

// Container for list filters: full-width stacked controls on mobile, a wrapping row from md up.
// Pass `onSubmit` to render a search form (Enter submits); `actions` sits at the end of the row.
export default function FilterBar({ onSubmit, actions, label, className, children }) {
  const content = (
    <>
      {children}
      {actions ? <div className="flex flex-wrap items-center gap-2 md:ms-auto">{actions}</div> : null}
    </>
  );
  const classes = cx('flex flex-col gap-2.5 md:flex-row md:flex-wrap md:items-center', className);

  if (typeof onSubmit === 'function') {
    return (
      <form
        role="search"
        aria-label={label}
        onSubmit={(event) => {
          event.preventDefault();
          onSubmit(event);
        }}
        className={classes}
      >
        {content}
      </form>
    );
  }

  return (
    <div role="group" aria-label={label} className={classes}>
      {content}
    </div>
  );
}

// Thin presets of Input/Select without the default bottom margin; give them aria-label (no visible label).
export function FilterInput({ wrapperClassName, ...rest }) {
  return <Input {...rest} wrapperClassName={cx('w-full md:w-[220px]', wrapperClassName)} />;
}

export function FilterSelect({ wrapperClassName, ...rest }) {
  return <Select {...rest} wrapperClassName={cx('w-full md:w-40', wrapperClassName)} />;
}

export { FilterBar };
