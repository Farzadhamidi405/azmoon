// useAuth.js
// Re-export از AuthContext برای یکدستی مسیر import در کامپوننت‌ها.
// همه کامپوننت‌ها باید از این فایل import کنند، نه مستقیم از context.

export { useAuth } from '../context/AuthContext';
