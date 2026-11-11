import { cx } from '../../lib/cx.js';

const TONES = {
  ok: 'bg-ok',
  warn: 'bg-amber',
  bad: 'bg-red',
};

// Default zones are equal thirds. Real thresholds must be supplied by the caller (zones prop);
// `value` (0..100) only positions the marker. First zone = low risk = inline-start (right in RTL).
const DEFAULT_ZONES = [
  { to: 33, tone: 'ok' },
  { to: 66, tone: 'warn' },
  { to: 100, tone: 'bad' },
];

export default function RiskMeter({ value, zones = DEFAULT_ZONES, caption, label, className }) {
  const n = Number(value);
  const valid = value !== null && value !== undefined && value !== '' && Number.isFinite(n);
  const pct = valid ? Math.min(100, Math.max(0, n)) : 0;
  let prev = 0;
  const segments = [];
  zones.forEach((zone, i) => {
    const to = Number(zone.to);
    if (!Number.isFinite(to)) return;
    const end = Math.min(100, Math.max(prev, to));
    segments.push({ key: i, width: end - prev, tone: zone.tone });
    prev = end;
  });
  return (
    <div className={className}>
      <div
        role="meter"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={valid ? pct : undefined}
        aria-label={label}
        className="relative mb-1.5 mt-2.5 h-2.5"
      >
        <div className="flex h-full w-full overflow-hidden rounded-md bg-line-soft">
          {segments.map((seg) => (
            <div key={seg.key} className={TONES[seg.tone] || 'bg-line'} style={{ width: seg.width + '%' }} />
          ))}
        </div>
        {valid ? (
          <span
            aria-hidden="true"
            className="absolute -top-1 h-[18px] w-[3px] rounded-sm bg-navy"
            style={{ insetInlineStart: pct + '%', marginInlineStart: '-1.5px' }}
          />
        ) : null}
      </div>
      {caption ? <div className="text-[11px] text-ink-soft">{caption}</div> : null}
    </div>
  );
}

export { RiskMeter };
