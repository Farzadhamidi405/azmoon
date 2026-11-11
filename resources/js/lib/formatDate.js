// Persian (Jalali) calendar with Latin digits; falls back to an ISO-like string when Intl cannot do it.
let formatter = null;
let formatterReady = false;

function getFormatter() {
  if (!formatterReady) {
    formatterReady = true;
    try {
      formatter = new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-latn', { dateStyle: 'short', timeStyle: 'short' });
    } catch (err) {
      formatter = null;
    }
  }
  return formatter;
}

export function formatDateTime(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  const fmt = getFormatter();
  if (fmt) {
    try {
      return fmt.format(date);
    } catch (err) {
      // fall through to the ISO-like string
    }
  }
  return date.toISOString().replace('T', ' ').slice(0, 16);
}

export default formatDateTime;
