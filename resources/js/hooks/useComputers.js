/**
 * useComputers - loads and mutates the computers list (admin API).
 * Server-side pagination, search and filters; silent reload after every mutation; stale-response guard.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { api, API_BASE, getErrorMessage } from '../lib/api.js';
import { UI_TEXT } from '../components/ui/uiText.js';

// Computers endpoint base path.
const BASE = `${API_BASE}/admin/computers`;

// Request config: errors are shown inline by the caller, not as a global toast.
const QUIET = { skipErrorToast: true };

// Rows per page (the Backend allows 1..100).
const PER_PAGE = 20;

// Initial query: first page, no filters.
const INITIAL_QUERY = { page: 1, labId: '', status: '', search: '' };

/** Builds the request params; only non-empty filters are sent. */
function buildParams(query) {
  const params = { page: query.page, per_page: PER_PAGE };
  if (query.labId) params.lab_id = query.labId;
  if (query.status) params.status = query.status;
  if (query.search) params.search = query.search;
  return params;
}

/** Converts a value to an integer >= 1 (fallback when invalid). */
function toPositiveInt(value, fallback) {
  const n = Number(value);
  return Number.isFinite(n) && n >= 1 ? Math.trunc(n) : fallback;
}

/** Reads the Laravel paginator payload: rows plus the pagination summary used by the Pagination component. */
function parsePage(payload) {
  if (Array.isArray(payload)) return { rows: payload, pagination: null };
  if (!payload || typeof payload !== 'object') return { rows: [], pagination: null };
  const rows = Array.isArray(payload.data) ? payload.data : [];
  const pagination = {
    currentPage: toPositiveInt(payload.current_page, 1),
    lastPage: toPositiveInt(payload.last_page, 1),
    total: payload.total === undefined || payload.total === null ? rows.length : payload.total,
  };
  return { rows, pagination };
}

/**
 * Hook for the computers resource.
 * @param {{enabled?: boolean}} options - when false, nothing is loaded automatically.
 * @returns {object} items, pagination, query, loading, error and the actions (setFilters, setPage, reload, create, update, updateStatus, remove).
 */
export function useComputers({ enabled = true } = {}) {
  // Rows of the current page.
  const [items, setItems] = useState([]);
  // Pagination summary of the current page (null when unknown).
  const [pagination, setPagination] = useState(null);
  // Current query: page and filters.
  const [query, setQuery] = useState(INITIAL_QUERY);
  // True while a non-silent load is running.
  const [loading, setLoading] = useState(enabled);
  // Load error message (non-silent loads only).
  const [error, setError] = useState(null);
  // Sequence number: only the latest request may update state.
  const seqRef = useRef(0);
  // False after unmount: prevents state updates on an unmounted component.
  const aliveRef = useRef(true);
  // Latest query and rows, readable inside stable callbacks.
  const queryRef = useRef(query);
  const itemsRef = useRef(items);

  // Keeps the refs in sync with the state.
  useEffect(() => {
    queryRef.current = query;
    itemsRef.current = items;
  }, [query, items]);

  /**
   * Loads one page from the API.
   * @param {{silent?: boolean, query?: object}} options - silent keeps the table and hides errors.
   */
  const load = useCallback(async ({ silent = false, query: override } = {}) => {
    const q = override || queryRef.current;
    const seq = ++seqRef.current;
    if (!silent) {
      setLoading(true);
      setError(null);
    }
    try {
      const res = await api.get(BASE, { ...QUIET, params: buildParams(q) });
      if (!aliveRef.current || seq !== seqRef.current) return;
      const parsed = parsePage(res.data ? res.data.data : null);
      // The requested page no longer exists (rows were deleted): step back, the query effect reloads.
      if (parsed.pagination && q.page > parsed.pagination.lastPage) {
        const last = parsed.pagination.lastPage;
        setQuery((cur) => (cur.page > last ? { ...cur, page: last } : cur));
        return;
      }
      setItems(parsed.rows);
      setPagination(parsed.pagination);
    } catch (err) {
      if (!aliveRef.current || seq !== seqRef.current) return;
      if (!silent) setError(getErrorMessage(err, UI_TEXT.loadError));
    } finally {
      if (aliveRef.current && seq === seqRef.current) setLoading(false);
    }
  }, []);

  // Unmount guard (re-armed on remount).
  useEffect(() => {
    aliveRef.current = true;
    return () => {
      aliveRef.current = false;
    };
  }, []);

  // Loads whenever the query changes.
  useEffect(() => {
    if (enabled) load({ query });
  }, [enabled, query, load]);

  /** Applies filter changes and returns to the first page. */
  const setFilters = useCallback((patch) => {
    setQuery((cur) => ({ ...cur, ...patch, page: 1 }));
  }, []);

  /** Moves to another page. */
  const setPage = useCallback((page) => {
    setQuery((cur) => ({ ...cur, page: toPositiveInt(page, 1) }));
  }, []);

  /** Creates a computer, then refreshes the current page. */
  const create = useCallback(
    async (payload) => {
      const res = await api.post(BASE, payload, QUIET);
      await load({ silent: true });
      return res.data;
    },
    [load]
  );

  /** Updates a computer by id, then refreshes the current page. */
  const update = useCallback(
    async (id, payload) => {
      const res = await api.put(`${BASE}/${id}`, payload, QUIET);
      await load({ silent: true });
      return res.data;
    },
    [load]
  );

  /** Changes the status of a computer (separate endpoint), then refreshes. */
  const updateStatus = useCallback(
    async (id, status) => {
      const res = await api.patch(`${BASE}/${id}/status`, { status }, QUIET);
      await load({ silent: true });
      return res.data;
    },
    [load]
  );

  /** Deletes a computer; when the last row of a later page goes away, steps back one page. */
  const remove = useCallback(
    async (id) => {
      const res = await api.delete(`${BASE}/${id}`, QUIET);
      if (itemsRef.current.length <= 1 && queryRef.current.page > 1) {
        setQuery((cur) => ({ ...cur, page: Math.max(1, cur.page - 1) }));
      } else {
        await load({ silent: true });
      }
      return res.data;
    },
    [load]
  );

  return { items, pagination, query, loading, error, setFilters, setPage, reload: load, create, update, updateStatus, remove };
}