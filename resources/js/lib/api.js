import axios from 'axios';
import { notifyError } from './notify.js';

export const API_BASE = '/api/v1';

export const MESSAGES = Object.freeze({
  network: '\u0627\u0631\u062A\u0628\u0627\u0637 \u0628\u0627 \u0633\u0631\u0648\u0631 \u0628\u0631\u0642\u0631\u0627\u0631 \u0646\u0634\u062F.',
  unauthorized: '\u0646\u0634\u0633\u062A \u0634\u0645\u0627 \u0645\u0646\u0642\u0636\u06CC \u0634\u062F\u0647 \u0627\u0633\u062A.',
  forbidden: '\u0634\u0645\u0627 \u0628\u0647 \u0627\u06CC\u0646 \u0639\u0645\u0644\u06CC\u0627\u062A \u062F\u0633\u062A\u0631\u0633\u06CC \u0646\u062F\u0627\u0631\u06CC\u062F.',
  notFound: '\u0645\u0648\u0631\u062F \u062F\u0631\u062E\u0648\u0627\u0633\u062A\u06CC \u06CC\u0627\u0641\u062A \u0646\u0634\u062F.',
  csrf: '\u0646\u0634\u0633\u062A \u0627\u0645\u0646\u06CC\u062A\u06CC \u0645\u0646\u0642\u0636\u06CC \u0634\u062F. \u0635\u0641\u062D\u0647 \u0631\u0627 \u062F\u0648\u0628\u0627\u0631\u0647 \u0628\u0627\u0631\u06AF\u0630\u0627\u0631\u06CC \u06A9\u0646\u06CC\u062F.',
  validation: '\u0627\u0637\u0644\u0627\u0639\u0627\u062A \u0648\u0627\u0631\u062F\u200C\u0634\u062F\u0647 \u0645\u0639\u062A\u0628\u0631 \u0646\u06CC\u0633\u062A.',
  tooMany: '\u062A\u0639\u062F\u0627\u062F \u062F\u0631\u062E\u0648\u0627\u0633\u062A\u200C\u0647\u0627 \u0632\u06CC\u0627\u062F \u0627\u0633\u062A. \u06A9\u0645\u06CC \u0628\u0639\u062F \u062F\u0648\u0628\u0627\u0631\u0647 \u062A\u0644\u0627\u0634 \u06A9\u0646\u06CC\u062F.',
  notImplemented: '\u0627\u06CC\u0646 \u0642\u0627\u0628\u0644\u06CC\u062A \u0647\u0646\u0648\u0632 \u062F\u0631 \u0633\u0631\u0648\u0631 \u0641\u0639\u0627\u0644 \u0646\u0634\u062F\u0647 \u0627\u0633\u062A.',
  server: '\u062E\u0637\u0627\u06CC \u062F\u0627\u062E\u0644\u06CC \u0633\u0631\u0648\u0631 \u0631\u062E \u062F\u0627\u062F.',
  unknown: '\u062E\u0637\u0627\u06CC \u0646\u0627\u0645\u0634\u062E\u0635 \u0631\u062E \u062F\u0627\u062F.',
});

const SAFE_METHODS = ['get', 'head', 'options'];
const TOAST_STATUSES = [403, 419, 429, 501];

// One shared Axios instance for the whole SPA (same-origin, cookie based Sanctum SPA auth).
// CSRF: Axios reads the XSRF-TOKEN cookie and sends X-XSRF-TOKEN (no static meta token).
export const api = axios.create({
  baseURL: '',
  timeout: 30000,
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

function hasXsrfCookie() {
  return (
    typeof document !== 'undefined' &&
    document.cookie.split('; ').some((c) => c.startsWith('XSRF-TOKEN='))
  );
}

let csrfPromise = null;

export function ensureCsrf(force = false) {
  if (!force && hasXsrfCookie()) return Promise.resolve();
  if (!csrfPromise) {
    csrfPromise = api
      .get('/sanctum/csrf-cookie', { skipErrorToast: true, skipAuthHandler: true })
      .then(() => undefined)
      .finally(() => {
        csrfPromise = null;
      });
  }
  return csrfPromise;
}

let unauthorizedHandler = null;

// The handler returns false when there was no signed-in user (no toast is shown then).
export function setUnauthorizedHandler(handler) {
  unauthorizedHandler = typeof handler === 'function' ? handler : null;
}

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const hasNonAscii = (s) => /[^\x00-\x7F]/.test(s);

function extractFieldErrors(data) {
  const out = {};
  if (!isObj(data) || !isObj(data.errors)) return out;
  Object.keys(data.errors).forEach((key) => {
    const value = data.errors[key];
    const first = Array.isArray(value) ? value[0] : value;
    if (typeof first === 'string' && first) out[key] = first;
  });
  return out;
}

function resolveMessage(status, data, fieldErrors) {
  const server = isObj(data) && typeof data.message === 'string' ? data.message.trim() : '';
  if (status === 0) return MESSAGES.network;
  if (status === 422) {
    const first = Object.values(fieldErrors)[0];
    return first || server || MESSAGES.validation;
  }
  if (status === 501) return MESSAGES.notImplemented;
  if (status >= 500) return MESSAGES.server;
  if (server && hasNonAscii(server)) return server;
  if (status === 401) return MESSAGES.unauthorized;
  if (status === 403) return MESSAGES.forbidden;
  if (status === 404) return MESSAGES.notFound;
  if (status === 419) return MESSAGES.csrf;
  if (status === 429) return MESSAGES.tooMany;
  return MESSAGES.unknown;
}

api.interceptors.request.use(async (config) => {
  const method = (config.method || 'get').toLowerCase();
  if (!SAFE_METHODS.includes(method) && !config.skipCsrf) {
    await ensureCsrf();
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (axios.isCancel(error)) return Promise.reject(error);

    const config = error.config || {};
    const status = error.response ? error.response.status : 0;
    const data = error.response ? error.response.data : null;

    // 419: refresh the CSRF cookie and retry exactly once.
    if (status === 419 && !config._csrfRetried) {
      config._csrfRetried = true;
      await ensureCsrf(true);
      return api.request(config);
    }

    error.status = status;
    error.isNetworkError = status === 0;
    error.serverMessage = isObj(data) && typeof data.message === 'string' ? data.message : '';
    error.fieldErrors = extractFieldErrors(data);
    error.apiMessage = resolveMessage(status, data, error.fieldErrors);

    if (status === 401 && !config.skipAuthHandler) {
      const handled = unauthorizedHandler ? unauthorizedHandler(error) : false;
      if (handled !== false && !config.skipErrorToast) notifyError(MESSAGES.unauthorized);
    } else if (
      !config.skipErrorToast &&
      (status === 0 || status >= 500 || TOAST_STATUSES.includes(status))
    ) {
      notifyError(error.apiMessage);
    }
    // 422 never produces a global toast: forms show error.fieldErrors / error.apiMessage.

    return Promise.reject(error);
  },
);

export function getErrorMessage(error, fallback = MESSAGES.unknown) {
  return (error && error.apiMessage) || fallback;
}

export function getFieldErrors(error) {
  return (error && error.fieldErrors) || {};
}

export function toNumber(value, fallback = null) {
  if (value === null || value === undefined || value === '') return fallback;
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

function pickPagination(src) {
  if (!isObj(src) || src.current_page === undefined) return null;
  return {
    currentPage: Number(src.current_page),
    lastPage: Number(src.last_page ?? 1),
    perPage: Number(src.per_page ?? 0),
    total: Number(src.total ?? 0),
    from: src.from ?? null,
    to: src.to ?? null,
  };
}

// Accepts a response BODY (response.data). Handles: plain array, raw paginator
// ({data, current_page, ...}), Resource ({data, links, meta}) and {success, data: <one of those>}.
// Always keeps the original in `raw`; `meta` and `links` are never discarded.
export function unwrapList(payload) {
  if (Array.isArray(payload)) {
    return { items: payload, pagination: null, meta: null, links: null, raw: payload };
  }
  if (isObj(payload) && Array.isArray(payload.data)) {
    const { data: _items, ...rest } = payload;
    const pagination = pickPagination(payload.meta) || pickPagination(payload);
    let meta = null;
    if (isObj(payload.meta)) meta = payload.meta;
    else if (pagination) meta = rest;
    const links = payload.links !== undefined ? payload.links : null;
    return { items: payload.data, pagination, meta, links, raw: payload };
  }
  if (isObj(payload) && isObj(payload.data)) {
    return unwrapList(payload.data);
  }
  return { items: [], pagination: null, meta: null, links: null, raw: payload };
}

// Single record: unwraps a Resource-style {data: {...}} body, otherwise returns the body.
export function unwrapItem(payload) {
  if (isObj(payload) && isObj(payload.data) && payload.id === undefined) return payload.data;
  return payload;
}

export default api;
