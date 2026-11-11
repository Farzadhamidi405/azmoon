import {
  ROLES,
  hasPermission,
  hasRole,
  canViewExams,
  canCreateExam,
  canViewQuestions,
} from '../lib/permissions.js';

// Admin menu source of truth. UI convenience only: the Backend still authorizes every request.
// Rules:
//  - an item without permission is hidden; a group without visible items is hidden;
//  - a group left with a single visible item becomes a direct link labelled with that item;
//  - shifts, computers and labs share labs.view and statistics uses results.view
//    (UI convention: the permission matrix has no shifts, computers or statistics module);
//  - Backups is shown to Super Admin only (no backups permission exists);
//  - roles and anti-cheat have no menu item in this phase.
// Persian labels are \u escapes; the English note beside each is for readers only.

const needs = (permission) => (user) => hasPermission(user, permission);
const anyStaff = (user) => Boolean(user);
const superAdminOnly = (user) => hasRole(user, ROLES.SUPER_ADMIN);

const NAV_CONFIG = [
  {
    id: 'dashboard',
    label: '\u062F\u0627\u0634\u0628\u0648\u0631\u062F', // Dashboard
    to: '/admin/dashboard',
    visible: anyStaff,
  },
  {
    id: 'exams-group',
    label: '\u0627\u0645\u062A\u062D\u0627\u0646\u0627\u062A', // Exams (group)
    items: [
      { id: 'exams-list', label: '\u0644\u06CC\u0633\u062A \u0622\u0632\u0645\u0648\u0646\u200C\u0647\u0627', to: '/admin/exams', visible: canViewExams },
      { id: 'exams-create', label: '\u0627\u06CC\u062C\u0627\u062F \u0622\u0632\u0645\u0648\u0646', to: '/admin/exams/create', visible: canCreateExam },
    ],
  },
  {
    id: 'questions',
    label: '\u0628\u0627\u0646\u06A9 \u0633\u0648\u0627\u0644\u0627\u062A', // Question bank
    to: '/admin/questions',
    visible: canViewQuestions,
  },
  {
    id: 'candidates',
    label: '\u0645\u062A\u0642\u0627\u0636\u06CC\u0627\u0646', // Candidates
    to: '/admin/candidates',
    visible: needs('candidates.view'),
  },
  {
    id: 'base-group',
    label: '\u0627\u0637\u0644\u0627\u0639\u0627\u062A \u067E\u0627\u06CC\u0647', // Base Information (group)
    items: [
      { id: 'faculties', label: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647\u200C\u0647\u0627', to: '/admin/faculties', visible: needs('faculties.view') }, // Faculties
      { id: 'subjects', label: '\u0645\u0636\u0627\u0645\u06CC\u0646', to: '/admin/subjects', visible: needs('subjects.view') }, // Subjects
      { id: 'labs', label: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631\u0647\u0627', to: '/admin/labs', visible: needs('labs.view') }, // Labs
      { id: 'computers', label: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631\u0647\u0627', to: '/admin/computers', visible: needs('labs.view') }, // Computers
      { id: 'shifts', label: '\u0634\u0641\u062A\u200C\u0647\u0627', to: '/admin/shifts', visible: needs('labs.view') }, // Shifts
    ],
  },
  {
    id: 'sessions',
    label: '\u062C\u0644\u0633\u0627\u062A \u0627\u0645\u062A\u062D\u0627\u0646', // Exam sessions
    to: '/admin/sessions',
    visible: needs('sessions.view'),
  },
  {
    id: 'results-group',
    label: '\u0646\u062A\u0627\u06CC\u062C', // Results (group)
    items: [
      { id: 'results', label: '\u0646\u062A\u0627\u06CC\u062C \u0622\u0632\u0645\u0648\u0646', to: '/admin/results', visible: needs('results.view') }, // Exam results
      { id: 'statistics', label: '\u06AF\u0632\u0627\u0631\u0634 \u0622\u0645\u0627\u0631\u06CC', to: '/admin/statistics', visible: needs('results.view') }, // Statistical report
    ],
  },
  {
    id: 'system-group',
    label: '\u0633\u06CC\u0633\u062A\u0645', // System (group)
    items: [
      { id: 'users', label: '\u06A9\u0627\u0631\u0628\u0631\u0627\u0646 \u0633\u06CC\u0633\u062A\u0645', to: '/admin/users', visible: needs('users.view') }, // System users
      { id: 'audit-logs', label: '\u0644\u0627\u06AF \u0641\u0639\u0627\u0644\u06CC\u062A\u200C\u0647\u0627', to: '/admin/audit-logs', visible: needs('audit.view') }, // Activity log
      { id: 'settings', label: '\u062A\u0646\u0638\u06CC\u0645\u0627\u062A', to: '/admin/settings', visible: needs('settings.view') }, // Settings
      { id: 'backups', label: '\u0646\u0633\u062E\u0647\u200C\u0647\u0627\u06CC \u067E\u0634\u062A\u06CC\u0628\u0627\u0646', to: '/admin/backups', visible: superAdminOnly }, // Backups
    ],
  },
];

function toLeaf(item) {
  return { id: item.id, label: item.label, to: item.to };
}

// Returns the menu for a staff user: [{type:'link', id, label, to} | {type:'group', id, label, items:[...]}].
export function buildNavigation(user) {
  if (!user) return [];
  const result = [];
  NAV_CONFIG.forEach((entry) => {
    if (entry.items) {
      const items = entry.items.filter((item) => item.visible(user)).map(toLeaf);
      if (items.length === 0) return;
      if (items.length === 1) {
        result.push({ type: 'link', ...items[0] });
        return;
      }
      result.push({ type: 'group', id: entry.id, label: entry.label, items });
      return;
    }
    if (entry.visible(user)) result.push({ type: 'link', ...toLeaf(entry) });
  });
  return result;
}

// Segment boundary match: '/admin/exams' matches '/admin/exams/5' but not '/admin/examsX'.
export function isPathActive(pathname, to) {
  if (typeof pathname !== 'string' || typeof to !== 'string') return false;
  const path = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;
  return path === to || path.startsWith(to + '/');
}

// The longest matching path wins, so only one item is active (for example exams/create, not exams).
export function findActiveItem(entries, pathname) {
  let best = null;
  entries.forEach((entry) => {
    const leaves = entry.type === 'group' ? entry.items.map((item) => ({ item, groupId: entry.id })) : [{ item: entry, groupId: null }];
    leaves.forEach(({ item, groupId }) => {
      if (!isPathActive(pathname, item.to)) return;
      if (!best || item.to.length > best.length) best = { itemId: item.id, groupId, length: item.to.length };
    });
  });
  return best ? { itemId: best.itemId, groupId: best.groupId } : { itemId: null, groupId: null };
}
