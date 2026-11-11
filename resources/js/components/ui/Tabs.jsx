import { useRef } from 'react';
import { cx } from '../../lib/cx.js';

const TAB_BASE =
  '-mb-px min-h-11 shrink-0 whitespace-nowrap border-b-2 px-4 py-2 text-[12.5px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 md:min-h-10';
const ACTIVE = 'border-gold text-navy';
const IDLE = 'border-transparent text-ink-soft hover:text-navy';

// tabs: [{ id, label, badge?, disabled? }]. Keyboard: arrows (RTL aware), Home, End.
// On narrow screens the tab strip scrolls horizontally instead of wrapping.
export default function Tabs({ tabs = [], value, onChange, idPrefix = 'tabs', className }) {
  const refs = useRef({});
  const enabled = tabs.filter((t) => !t.disabled);

  function handleKeyDown(event, id) {
    const rtl = document.documentElement.dir === 'rtl';
    const pos = enabled.findIndex((t) => t.id === id);
    let next;
    if (event.key === 'ArrowRight') next = enabled[pos + (rtl ? -1 : 1)];
    else if (event.key === 'ArrowLeft') next = enabled[pos + (rtl ? 1 : -1)];
    else if (event.key === 'Home') next = enabled[0];
    else if (event.key === 'End') next = enabled[enabled.length - 1];
    else return;
    event.preventDefault();
    if (next) {
      if (onChange) onChange(next.id);
      const el = refs.current[next.id];
      if (el) el.focus();
    }
  }

  return (
    <div
      role="tablist"
      className={cx('mb-4 flex gap-1 overflow-x-auto border-b-[1.5px] border-line scrollbar-none', className)}
    >
      {tabs.map((tab) => {
        const active = tab.id === value;
        return (
          <button
            key={tab.id}
            ref={(el) => {
              refs.current[tab.id] = el;
            }}
            type="button"
            role="tab"
            id={idPrefix + '-tab-' + tab.id}
            aria-selected={active}
            aria-controls={idPrefix + '-panel-' + tab.id}
            tabIndex={active ? 0 : -1}
            disabled={tab.disabled}
            onClick={() => onChange && onChange(tab.id)}
            onKeyDown={(event) => handleKeyDown(event, tab.id)}
            className={cx(TAB_BASE, active ? ACTIVE : IDLE)}
          >
            {tab.label}
            {tab.badge !== undefined && tab.badge !== null ? (
              <span className="ms-1.5 rounded-full bg-chip px-1.5 py-0.5 text-[10px] text-ink-soft">{tab.badge}</span>
            ) : null}
          </button>
        );
      })}
    </div>
  );
}

export function TabPanel({ id, value, idPrefix = 'tabs', className, children }) {
  if (id !== value) return null;
  return (
    <div
      role="tabpanel"
      id={idPrefix + '-panel-' + id}
      aria-labelledby={idPrefix + '-tab-' + id}
      className={className}
    >
      {children}
    </div>
  );
}

export { Tabs };
