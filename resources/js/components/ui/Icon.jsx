// Icon paths are extracted from the approved UI/UX prototype (ui-ux-prototype-v3-2.html, ICON map).
// They are static trusted constants (no user input), injected as SVG markup.
const PATHS = {
  dash: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
  doc: '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  layers: '<path d="M12 2 3 7l9 5 9-5-9-5Z"/><path d="M3 12l9 5 9-5"/>',
  book: '<path d="M12 2 3 7l9 5 9-5-9-5Z"/><path d="M3 12l9 5 9-5M3 17l9 5 9-5"/>',
  users: '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="18" cy="8" r="2.4"/><path d="M16 14.3c2.7.4 4.5 2.4 4.5 5.7"/>',
  upload: '<path d="M12 3v12m0 0-4-4m4 4 4-4M4 19h16"/>',
  card: '<rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20M7 15h4"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
  lab: '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
  chart: '<path d="M4 20V10M12 20V4M20 20v-7"/>',
  usercheck: '<path d="M3 3v18h18M7 15l4-5 3 3 5-7"/>',
  trend: '<path d="M3 3v18h18M7 15l4-5 3 3 5-7"/>',
  shield: '<path d="M12 2 3 7v6c0 5 4 8 9 9 5-1 9-4 9-9V7l-9-5Z"/>',
  log: '<path d="M9 2h6l1 3h4v3l-2 12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L4 8V5h4l1-3Z"/>',
  alert: '<path d="M12 9v4M12 17h.01M10.3 3.9 2.7 17a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
  gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
  // bell and menu are outside the prototype ICON map (the bell path is copied from the prototype top bar; menu is generic).
  bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
  menu: '<path d="M3 6h18M3 12h18M3 18h18"/>',
};

export const ICON_NAMES = Object.freeze(Object.keys(PATHS));

export default function Icon({ name, size = 17, className, title }) {
  const markup = PATHS[name];
  if (!markup) return null;
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      className={className}
      role={title ? 'img' : undefined}
      aria-label={title || undefined}
      aria-hidden={title ? undefined : 'true'}
      focusable="false"
      dangerouslySetInnerHTML={{ __html: markup }}
    />
  );
}

export { Icon };
