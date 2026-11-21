import { cx } from '../../lib/cx.js';

const BRAND_TEXT = Object.freeze({
  letter: '\u06A9',
  name: '\u067E\u0648\u0647\u0646\u062A\u0648\u0646 \u06A9\u0646\u062F\u0632',
  subtitle: '\u0633\u06CC\u0633\u062A\u0645 \u0627\u0645\u062A\u062D\u0627\u0646 \u0632\u0628\u0627\u0646',
});

// Brand mark exactly matched with the approved UI/UX prototype (v3 / v4).
export default function Brand({ className }) {
  return (
    <span className={cx('sb-brand inline-flex min-w-0 items-center gap-2.5', className)}>
      <span
        aria-hidden="true"
        className="sb-crest flex size-9 shrink-0 items-center justify-center rounded-[9px] bg-linear-[135deg,#B08A3E,#8A6D2F] text-[15px] font-extrabold text-[#1B1608]"
      >
        {BRAND_TEXT.letter}
      </span>
      <span className="sb-brand-txt flex flex-col text-right">
        <span className="t1 text-[13.5px] font-bold text-white leading-[1.3]">{BRAND_TEXT.name}</span>
        <span className="t2 text-[10.5px] text-[#8B96B3] leading-[1.2]">{BRAND_TEXT.subtitle}</span>
      </span>
    </span>
  );
}

export { Brand };
