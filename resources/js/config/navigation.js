// navigation.js
// Admin Navigation Configuration matched 100% with the approved UI/UX Prototype (v3 / v4).

export const NAV_CONFIG = [
  {
    id: 'main-group',
    label: '\u0627\u0635\u0644\u06CC', // اصلی
    items: [
      { id: 'dash', label: '\u062F\u0627\u0634\u0628\u0648\u0631\u062F', to: '/admin/dashboard', icon: 'dash' },
    ],
  },
  {
    id: 'exams-group',
    label: '\u0627\u0645\u062A\u062D\u0627\u0646\u0627\u062A', // امتحانات
    items: [
      { id: 'exams-list', label: '\u0644\u06CC\u0633\u062A \u0622\u0632\u0645\u0648\u0646\u200C\u0647\u0627', to: '/admin/exams', icon: 'doc', pill: '\u06F3' },
      { id: 'exam-create', label: '\u0627\u06CC\u062C\u0627\u062F / \u0648\u06CC\u0631\u0627\u06CC\u0634 \u0622\u0632\u0645\u0648\u0646', to: '/admin/exams/create', icon: 'plus' },
      { id: 'pool', label: 'Question Pool', to: '/admin/exams/pool', icon: 'layers' },
      { id: 'subjects', label: '\u0645\u0636\u0627\u0645\u06CC\u0646', to: '/admin/subjects', icon: 'book' },
      { id: 'faculties', label: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647\u200C\u0647\u0627', to: '/admin/faculties', icon: 'doc' },
    ],
  },
  {
    id: 'questions-group',
    label: '\u0628\u0627\u0646\u06A9 \u0633\u0648\u0627\u0644\u0627\u062A', // بانک سوالات
    items: [
      { id: 'questions', label: '\u0647\u0645\u0647\u0654 \u0633\u0648\u0627\u0644\u0627\u062A', to: '/admin/questions', icon: 'book', pill: '\u06F8\u06F6' },
      { id: 'q-approval', label: '\u062A\u0623\u06CC\u06CC\u062F \u0633\u0648\u0627\u0644\u0627\u062A (Approval)', to: '/admin/questions/approval', icon: 'shield' },
    ],
  },
  {
    id: 'candidates-group',
    label: '\u0645\u062A\u0642\u0627\u0636\u06CC\u0627\u0646', // متقاضیان
    items: [
      { id: 'candidates', label: '\u0647\u0645\u0647\u0654 \u0645\u062A\u0642\u0627\u0636\u06CC\u0627\u0646', to: '/admin/candidates', icon: 'users', pill: '\u06F1\u06F2\u06F4' },
      { id: 'checkin', label: 'Check-in \u0648 \u062A\u0623\u06CC\u06CC\u062F \u0647\u0648\u06CC\u062A', to: '/admin/candidates/checkin', icon: 'usercheck' },
      { id: 'import', label: 'Import \u0627\u0632 Excel', to: '/admin/candidates/import', icon: 'upload' },
      { id: 'cards', label: '\u06A9\u0627\u0631\u062A\u200C\u0647\u0627\u06CC \u0648\u0631\u0648\u062F', to: '/admin/candidates/cards', icon: 'card' },
    ],
  },
  {
    id: 'sessions-group',
    label: '\u062C\u0644\u0633\u0627\u062A \u0648 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631', // جلسات و لابراتوار
    items: [
      { id: 'sessions', label: '\u062C\u0644\u0633\u0627\u062A \u0627\u0645\u062A\u062D\u0627\u0646', to: '/admin/sessions', icon: 'clock', pill: '\u06F1\u06F8' },
      { id: 'labs', label: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631\u0647\u0627', to: '/admin/labs', icon: 'lab' },
      { id: 'computers', label: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631\u0647\u0627', to: '/admin/computers', icon: 'lab' },
      { id: 'shifts', label: '\u0634\u0641\u062A\u200C\u0647\u0627', to: '/admin/shifts', icon: 'clock' },
      { id: 'incidents', label: '\u062D\u0648\u0627\u062F\u062B \u0627\u0645\u062A\u062D\u0627\u0646', to: '/admin/incidents', icon: 'alert' },
      { id: 'override', label: 'Manual Override', to: '/admin/override', icon: 'shield' },
    ],
  },
  {
    id: 'results-group',
    label: '\u0646\u062A\u0627\u06CC\u062C', // نتایج
    items: [
      { id: 'results', label: '\u0646\u062A\u0627\u06CC\u062C \u0622\u0632\u0645\u0648\u0646', to: '/admin/results', icon: 'chart' },
      { id: 'stats', label: '\u06AF\u0632\u0627\u0631\u0634 \u0622\u0645\u0627\u0631\u06CC', to: '/admin/statistics', icon: 'trend' },
    ],
  },
  {
    id: 'users-group',
    label: '\u06A9\u0627\u0631\u0628\u0631\u0627\u0646 \u0648 \u0646\u0638\u0627\u0631\u062A', // کاربران و نظارت
    items: [
      { id: 'users', label: '\u06A9\u0627\u0631\u0628\u0631\u0627\u0646 \u0633\u06CC\u0633\u062A\u0645', to: '/admin/users', icon: 'users', pill: '\u06F6' },
      { id: 'roles', label: '\u0646\u0642\u0634\u200C\u0647\u0627 \u0648 \u062F\u0633\u062A\u0631\u0633\u06CC\u200C\u0647\u0627', to: '/admin/roles', icon: 'shield' },
      { id: 'audit', label: '\u0644\u0627\u06AF \u0641\u0639\u0627\u0644\u06CC\u062A\u200C\u0647\u0627', to: '/admin/audit-logs', icon: 'log' },
      { id: 'anticheat', label: '\u0631\u0648\u06CC\u062F\u0627\u062F\u0647\u0627\u06CC \u0636\u062F\u062A\u0642\u0644\u0628', to: '/admin/anti-cheat', icon: 'alert' },
      { id: 'notifications', label: '\u0627\u0639\u0644\u0627\u0646\u200C\u0647\u0627', to: '/admin/notifications', icon: 'alert' },
    ],
  },
  {
    id: 'system-group',
    label: '\u0633\u06CC\u0633\u062A\u0645', // سیستم
    items: [
      { id: 'settings', label: '\u062A\u0646\u0638\u06CC\u0645\u0627\u062A', to: '/admin/settings', icon: 'gear' },
      { id: 'backups', label: '\u0646\u0633\u062E\u0647\u200C\u0647\u0627\u06CC \u067E\u0634\u062A\u06CC\u0628\u0627\u0646', to: '/admin/backups', icon: 'shield' },
    ],
  },
];

export function buildNavigation() {
  return NAV_CONFIG.map((group) => {
    if (group.items.length === 1) {
      return {
        type: 'link',
        id: group.items[0].id,
        label: group.items[0].label,
        to: group.items[0].to,
        icon: group.items[0].icon,
        pill: group.items[0].pill,
      };
    }
    return {
      type: 'group',
      id: group.id,
      label: group.label,
      items: group.items,
    };
  });
}

export function isPathActive(pathname, to) {
  if (typeof pathname !== 'string' || typeof to !== 'string') return false;
  const path = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;
  return path === to || path.startsWith(to + '/');
}

export function findActiveItem(entries, pathname) {
  if (!Array.isArray(entries)) return { groupId: null, itemId: null };
  for (const entry of entries) {
    if (entry.type === 'link') {
      if (isPathActive(pathname, entry.to)) {
        return { groupId: null, itemId: entry.id };
      }
    } else if (entry.type === 'group' && Array.isArray(entry.items)) {
      for (const item of entry.items) {
        if (isPathActive(pathname, item.to)) {
          return { groupId: entry.id, itemId: item.id };
        }
      }
    }
  }
  return { groupId: null, itemId: null };
}
