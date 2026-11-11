import { useCallback, useEffect, useState } from 'react';
import { api, API_BASE, getErrorMessage, unwrapList } from '../lib/api.js';
import { canViewExams, hasPermission } from '../lib/permissions.js';
import { UI_TEXT } from '../components/ui/uiText.js';

// Real data only: GET requests to the existing admin API, no polling, no sample values.
// Every source has its own state: hidden | loading | ok | error | forbidden.
const BASE = `${API_BASE}/admin`;
const ACTIVITY_LIMIT = 8;

function asCount(value) {
  if (typeof value === 'number') return Number.isFinite(value) && value >= 0 ? value : null;
  if (typeof value === 'string' && value.trim() !== '') {
    const n = Number(value);
    return Number.isFinite(n) && n >= 0 ? n : null;
  }
  return null;
}

// Only the total is kept; the single row returned with per_page=1 is discarded on purpose.
function readTotal(body) {
  const inner = body && typeof body === 'object' ? body.data : null;
  const direct = [
    inner && inner.total,
    body && body.total,
    inner && inner.meta && inner.meta.total,
    body && body.meta && body.meta.total,
  ];
  for (const item of direct) {
    const n = asCount(item);
    if (n !== null) return n;
  }
  try {
    const list = unwrapList(body);
    return list && list.pagination ? asCount(list.pagination.total) : null;
  } catch (err) {
    return null;
  }
}

function failure(message) {
  return { status: 'error', value: null, message };
}

function readCount(body) {
  const total = readTotal(body);
  return total === null ? failure(UI_TEXT.loadError) : { status: 'ok', value: total, message: '' };
}

function readActivity(body) {
  let items = null;
  try {
    items = unwrapList(body).items;
  } catch (err) {
    items = null;
  }
  if (!Array.isArray(items)) return failure(UI_TEXT.loadError);
  return { status: 'ok', value: items.slice(0, ACTIVITY_LIMIT), message: '' };
}

const SOURCES = {
  published: {
    path: 'exams',
    params: { status: 'published', per_page: 1 },
    allowed: (user) => canViewExams(user),
    read: readCount,
  },
  running: {
    path: 'exams',
    params: { status: 'running', per_page: 1 },
    allowed: (user) => canViewExams(user),
    read: readCount,
  },
  candidates: {
    path: 'candidates',
    params: { per_page: 1 },
    allowed: (user) => hasPermission(user, 'candidates.view'),
    read: readCount,
  },
  inProgress: {
    path: 'candidates',
    params: { status: 'in_progress', per_page: 1 },
    allowed: (user) => hasPermission(user, 'candidates.view'),
    read: readCount,
  },
  activity: {
    path: 'audit-logs',
    params: { per_page: ACTIVITY_LIMIT },
    allowed: (user) => hasPermission(user, 'audit.view'),
    read: readActivity,
  },
};

const KEYS = Object.keys(SOURCES);
const HIDDEN = { status: 'hidden', value: null, message: '' };
const LOADING = { status: 'loading', value: null, message: '' };

function buildState(active) {
  const next = {};
  KEYS.forEach((key) => {
    next[key] = active.includes(key) ? LOADING : HIDDEN;
  });
  return next;
}

function fromError(err) {
  if (err && err.status === 403) return { status: 'forbidden', value: null, message: '' };
  return failure(getErrorMessage(err, UI_TEXT.loadError));
}

// A source the role may not use is never requested (status stays "hidden").
// Errors never raise a global toast; the page shows them inside the card.
export default function useDashboardData(user) {
  const allowedKey = KEYS.filter((key) => SOURCES[key].allowed(user)).join(',');
  const [state, setState] = useState(() => buildState(allowedKey ? allowedKey.split(',') : []));
  const [round, setRound] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    const active = allowedKey ? allowedKey.split(',') : [];
    setState(buildState(active));
    active.forEach((key) => {
      const source = SOURCES[key];
      api
        .get(`${BASE}/${source.path}`, { params: source.params, signal: controller.signal, skipErrorToast: true })
        .then((res) => {
          if (controller.signal.aborted) return;
          setState((prev) => ({ ...prev, [key]: source.read(res.data) }));
        })
        .catch((err) => {
          if (controller.signal.aborted) return;
          setState((prev) => ({ ...prev, [key]: fromError(err) }));
        });
    });
    return () => controller.abort();
  }, [allowedKey, round]);

  const reload = useCallback(() => setRound((n) => n + 1), []);
  const busy = KEYS.some((key) => state[key].status === 'loading');
  return { data: state, reload, busy };
}

export { useDashboardData };
