import { useCallback, useEffect, useState } from 'react';
import { api, API_BASE, getErrorMessage, unwrapList } from '../lib/api.js';
import { canViewExams, hasPermission } from '../lib/permissions.js';
import { UI_TEXT } from '../components/ui/uiText.js';

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

function failure(message) {
  return { status: 'error', value: null, message };
}

function readCount(body) {
  const inner = body && typeof body === 'object' ? body.data : null;
  const direct = [
    inner && inner.total,
    body && body.total,
    inner && inner.meta && inner.meta.total,
    body && body.meta && body.meta.total,
  ];
  for (const item of direct) {
    const n = asCount(item);
    if (n !== null) return { status: 'ok', value: n, message: '' };
  }
  try {
    const list = unwrapList(body);
    const n = list && list.pagination ? asCount(list.pagination.total) : null;
    return n !== null ? { status: 'ok', value: n, message: '' } : failure(UI_TEXT.loadError);
  } catch (err) {
    return failure(UI_TEXT.loadError);
  }
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

export default function useDashboardData(user) {
  const allowedKey = KEYS.filter((key) => SOURCES[key].allowed(user)).join(',');
  const [state, setState] = useState(() => buildState(allowedKey ? allowedKey.split(',') : []));
  const [round, setRound] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    const active = allowedKey ? allowedKey.split(',') : [];
    setState(buildState(active));

    // ابتدا تلاش برای دریافت یکجای آمار از اندپوینت بهینه‌سازی‌شده
    api
      .get(`${BASE}/dashboard-stats`, { signal: controller.signal, skipErrorToast: true })
      .then((res) => {
        if (controller.signal.aborted) return;
        const d = res.data?.data;
        if (d && typeof d === 'object') {
          setState((prev) => ({
            ...prev,
            published: active.includes('published') ? { status: 'ok', value: d.published_exams ?? 0, message: '' } : prev.published,
            running: active.includes('running') ? { status: 'ok', value: d.running_exams ?? 0, message: '' } : prev.running,
            candidates: active.includes('candidates') ? { status: 'ok', value: d.candidates_count ?? 0, message: '' } : prev.candidates,
            inProgress: active.includes('inProgress') ? { status: 'ok', value: d.in_progress_count ?? 0, message: '' } : prev.inProgress,
            activity: active.includes('activity') && Array.isArray(d.recent_activity) ? { status: 'ok', value: d.recent_activity, message: '' } : prev.activity,
          }));
          return;
        }
        throw new Error('Invalid dashboard payload');
      })
      .catch(() => {
        if (controller.signal.aborted) return;
        // Fallback به دریافت مجزای منابع در صورت بروز هرگونه مشکل
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
      });

    return () => controller.abort();
  }, [allowedKey, round]);

  const reload = useCallback(() => setRound((r) => r + 1), []);
  const busy = Object.values(state).some((item) => item.status === 'loading');

  return { data: state, reload, busy };
}
