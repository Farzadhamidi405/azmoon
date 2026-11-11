import { cx } from '../../lib/cx.js';

const BRAND_TEXT = Object.freeze({
  letter: '\u06A9',
  name: '\u067E\u0648\u0647\u0646\u062A\u0648\u0646 \u06A9\u0646\u062F\u0632',
});

// Brand mark shared by the admin top bar and the candidate header.
export default function Brand({ className }) {
  return (
    <span className={cx('inline-flex min-w-0 items-center gap-2.5', className)}>
      <span
        aria-hidden="true"
        className="flex size-8 shrink-0 items-center justify-center rounded-ds-sm bg-linear-[135deg,#B08A3E,#8A6D2F] text-sm font-extrabold text-[#1B1608]"
      >
        {BRAND_TEXT.letter}
      </span>
      <span className="truncate text-[13px] font-bold text-white">{BRAND_TEXT.name}</span>
    </span>
  );
}

export { Brand };
