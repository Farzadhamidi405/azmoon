import Card from '../ui/Card.jsx';
import { cx } from '../../lib/cx.js';

const BRAND_TEXT = Object.freeze({
  letter: '\u06A9',
  name: '\u067E\u0648\u0647\u0646\u062A\u0648\u0646 \u06A9\u0646\u062F\u0632',
  subtitle: '\u0633\u06CC\u0633\u062A\u0645 \u0645\u062F\u06CC\u0631\u06CC\u062A \u0648 \u0628\u0631\u06AF\u0632\u0627\u0631\u06CC \u0627\u0645\u062A\u062D\u0627\u0646 \u0632\u0628\u0627\u0646',
});

// Shared card for the guest pages (login, forgot password, 2FA notice).
// `brand` shows the logo block (login only); `title` shows a plain heading.
export default function AuthCard({ brand = false, title, centered = false, className, children }) {
  return (
    <Card
      padded={false}
      className={cx('w-full max-w-[400px] px-5 py-8 sm:px-[34px] sm:py-9', centered && 'text-center', className)}
    >
      {brand ? (
        <div className="mb-[26px] text-center">
          <div
            aria-hidden="true"
            className="mx-auto mb-3 flex size-11 items-center justify-center rounded-[11px] bg-linear-[135deg,#B08A3E,#8A6D2F] text-[17px] font-extrabold text-[#1B1608]"
          >
            {BRAND_TEXT.letter}
          </div>
          <h1 className="text-base font-extrabold text-navy">{BRAND_TEXT.name}</h1>
          <p className="mt-0.5 text-[12.5px] text-ink-soft">{BRAND_TEXT.subtitle}</p>
        </div>
      ) : null}
      {title ? <h1 className="mb-1.5 text-base font-extrabold text-navy">{title}</h1> : null}
      {children}
    </Card>
  );
}

export { AuthCard };