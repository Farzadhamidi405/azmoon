// UI helpers only. The Backend remains the single source of truth for authorization.
export const ROLES = Object.freeze({
  SUPER_ADMIN: 'Super Admin',
  EXAM_ADMIN: 'Exam Administrator',
  QUESTION_MANAGER: 'Question Manager',
  REVIEWER: 'Reviewer',
  INVIGILATOR: 'Invigilator',
  RESULT_REVIEWER: 'Result Reviewer',
  LAB_MANAGER: 'Lab Manager',
});

const EDITABLE_STATUSES = ['draft', 'under_review'];

export function roleName(user) {
  const role = user ? user.role : null;
  if (typeof role === 'string') return role;
  if (role && typeof role.name === 'string') return role.name;
  return null;
}

export function hasRole(user, ...roles) {
  const name = roleName(user);
  return !!name && roles.includes(name);
}

export function hasPermission(user, permission) {
  const list = user ? user.permissions : null;
  if (!Array.isArray(list)) return false;
  return list.includes('*') || list.includes(permission);
}

export function hasAnyPermission(user, permissions) {
  return permissions.some((p) => hasPermission(user, p));
}

const isFullAccess = (user) => hasRole(user, ROLES.SUPER_ADMIN, ROLES.EXAM_ADMIN);
const isOwner = (user, record) =>
  !!user && !!record && record.created_by !== undefined && record.created_by === user.id;

// ---- Exam (mirrors ExamPolicy, which is role based) ----
export function canViewExams(user) {
  return hasRole(user, ROLES.SUPER_ADMIN, ROLES.EXAM_ADMIN, ROLES.QUESTION_MANAGER);
}
export function canCreateExam(user) {
  return isFullAccess(user);
}
export function canChangeExamStatus(user) {
  return isFullAccess(user);
}
export function canEditExam(user, exam) {
  if (!exam || !EDITABLE_STATUSES.includes(exam.status)) return false;
  return isFullAccess(user) || isOwner(user, exam);
}
export function canDeleteExam(user, exam) {
  if (!exam || exam.status !== 'draft') return false;
  return isFullAccess(user) || isOwner(user, exam);
}

// ---- Question (mirrors QuestionPolicy) ----
export function canViewQuestions(user) {
  return hasRole(user, ROLES.SUPER_ADMIN, ROLES.EXAM_ADMIN, ROLES.QUESTION_MANAGER, ROLES.REVIEWER);
}
export function canCreateQuestion(user) {
  return hasRole(user, ROLES.SUPER_ADMIN, ROLES.EXAM_ADMIN, ROLES.QUESTION_MANAGER);
}
export function canEditQuestion(user, question) {
  if (isFullAccess(user)) return true;
  if (!question || !hasRole(user, ROLES.QUESTION_MANAGER)) return false;
  return isOwner(user, question) && EDITABLE_STATUSES.includes(question.status);
}
export function canChangeQuestionStatus(user, question) {
  if (isFullAccess(user)) return true;
  if (!question) return false;
  if (hasRole(user, ROLES.QUESTION_MANAGER)) {
    return isOwner(user, question) && question.status === 'draft';
  }
  if (hasRole(user, ROLES.REVIEWER)) return question.status === 'under_review';
  return false;
}
export function canDeleteQuestion(user) {
  return isFullAccess(user);
}

// ---- Permission based (SettingPolicy, notifications) ----
export function canViewSettings(user) {
  return hasPermission(user, 'settings.view');
}
export function canUpdateSettings(user) {
  return hasPermission(user, 'settings.update');
}
export function canSeeNotifications(user) {
  return hasPermission(user, 'notifications.view');
}

// Subject access cannot be evaluated on the client; the Backend decides (403 / empty list).
