import { cx } from '../../lib/cx.js';

const STATES = {
  done: 'bg-teal-soft font-semibold text-teal',
  current: 'bg-navy font-bold text-white',
  todo: 'bg-line-soft text-ink-soft',
};

// steps: [{ id, label }]; current: id of the active step (earlier steps are shown as done).
export default function Stepper({ steps = [], current, className }) {
  const index = steps.findIndex((s) => s.id === current);
  return (
    <ol className={cx('mb-4 flex flex-wrap items-center gap-1 text-[11px]', className)}>
      {steps.map((step, i) => {
        const state = index < 0 ? 'todo' : i < index ? 'done' : i === index ? 'current' : 'todo';
        return (
          <li key={step.id} aria-current={state === 'current' ? 'step' : undefined} className="flex items-center gap-1">
            <span className={cx('rounded-full px-3 py-1.5', STATES[state])}>{step.label}</span>
            {i < steps.length - 1 ? (
              <span aria-hidden="true" className="text-ink-soft/50">
                {'\u2190'}
              </span>
            ) : null}
          </li>
        );
      })}
    </ol>
  );
}

export { Stepper };
