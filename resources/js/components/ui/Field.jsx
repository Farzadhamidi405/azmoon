import { useId } from 'react';
import { cx } from '../../lib/cx.js';

// 16px text on mobile avoids iOS zoom on focus; 13.5px from md up (prototype size).
const CONTROL_BASE =
  'min-h-11 w-full rounded-ds-sm border bg-white px-3 py-2 text-base text-ink placeholder:text-ink-soft/60 focus:outline-2 focus:outline-offset-1 focus:outline-navy-3 disabled:cursor-not-allowed disabled:bg-chip disabled:text-ink-soft md:min-h-10 md:text-[13.5px]';

function controlClass(error, className) {
  return cx(CONTROL_BASE, error ? 'border-red' : 'border-line', className);
}

function useControlIds(id, error, helper) {
  const auto = useId();
  const fid = id || auto;
  let describedBy;
  if (error) describedBy = fid + '-error';
  else if (helper) describedBy = fid + '-help';
  return { fid, describedBy };
}

// Label + helper/error wrapper. Pass wrapperClassName="" (or "mb-0") to remove the default bottom margin.
export function Field({ id, label, helper, error, required, wrapperClassName, children }) {
  const wrap = wrapperClassName === undefined ? 'mb-4' : wrapperClassName;
  return (
    <div className={wrap}>
      {label ? (
        <label htmlFor={id} className="mb-1.5 block text-[12.5px] font-semibold text-ink-soft">
          {label}
          {required ? (
            <span className="ms-1 text-red" aria-hidden="true">
              *
            </span>
          ) : null}
        </label>
      ) : null}
      {children}
      {error ? (
        <p id={id ? id + '-error' : undefined} role="alert" className="mt-1.5 text-[11.5px] text-red">
          {error}
        </p>
      ) : helper ? (
        <p id={id ? id + '-help' : undefined} className="mt-1.5 text-[11.5px] text-ink-soft">
          {helper}
        </p>
      ) : null}
    </div>
  );
}

export function Input({ id, label, helper, error, required, wrapperClassName, className, type = 'text', ...rest }) {
  const { fid, describedBy } = useControlIds(id, error, helper);
  return (
    <Field id={fid} label={label} helper={helper} error={error} required={required} wrapperClassName={wrapperClassName}>
      <input
        {...rest}
        id={fid}
        type={type}
        aria-required={required ? 'true' : undefined}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={describedBy}
        className={controlClass(error, className)}
      />
    </Field>
  );
}

export function Select({ id, label, helper, error, required, wrapperClassName, className, children, ...rest }) {
  const { fid, describedBy } = useControlIds(id, error, helper);
  return (
    <Field id={fid} label={label} helper={helper} error={error} required={required} wrapperClassName={wrapperClassName}>
      <select
        {...rest}
        id={fid}
        aria-required={required ? 'true' : undefined}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={describedBy}
        className={controlClass(error, className)}
      >
        {children}
      </select>
    </Field>
  );
}

export function Textarea({ id, label, helper, error, required, wrapperClassName, className, rows = 3, ...rest }) {
  const { fid, describedBy } = useControlIds(id, error, helper);
  return (
    <Field id={fid} label={label} helper={helper} error={error} required={required} wrapperClassName={wrapperClassName}>
      <textarea
        {...rest}
        id={fid}
        rows={rows}
        aria-required={required ? 'true' : undefined}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={describedBy}
        className={controlClass(error, cx('resize-y', className))}
      />
    </Field>
  );
}

const ROW_COLS = {
  2: 'md:grid-cols-2',
  3: 'md:grid-cols-2 lg:grid-cols-3',
};

// One column on mobile, two on tablet, two or three on desktop.
export function FieldRow({ cols = 2, className, children }) {
  return <div className={cx('grid grid-cols-1 gap-x-3.5', ROW_COLS[cols] || ROW_COLS[2], className)}>{children}</div>;
}

export default Input;
