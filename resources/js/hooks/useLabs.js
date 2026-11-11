/**
 * useLabs - loads and mutates the labs list (admin API).
 * Modeled on useFaculties: silent reload after every mutation, stale-response guard.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { api, API_BASE, getErrorMessage, unwrapList } from '../lib/api.js';
import { UI_TEXT } from '../components/ui/uiText.js';

// Labs endpoint base path.
const BASE = `${API_BASE}/admin/labs`;

// Request config: errors are shown inline by the caller, not as a global toast.
const QUIET = { skipErrorToast: true };

/**
 * Hook for the labs resource.
 * @param {{enabled?: boolean}} options - when false, nothing is loaded automatically.
 * @returns {{items: Array, loading: boolean, error: (string|null), reload: Function, create: Function, update: Function, remove: Function}}
 */
export function useLabs({ enabled = true } = {}) {
  // Labs list (each item carries the computer counters from the API).
  const [items, setItems] = useState([]);
  // True while the first (non-silent) load is running.
  const [loading, setLoading] = useState(enabled);
  // Load error message (only for non-silent loads).
  const [error, setError] = useState(null);
  // Sequence number: only the latest request may update state.
  const seqRef = useRef(0);
  // False after unmount: prevents state updates on an unmounted component.
  const aliveRef = useRef(true);

  /**
   * Loads the list from the API.
   * @param {{silent?: boolean}} options - silent keeps the current table and does not show errors.
   */
  const load = useCallback(async ({ silent = false } = {}) => {
    const seq = ++seqRef.current;
    if (!silent) {
      setLoading(true);
      setError(null);
    }
    try {
      const res = await api.get(BASE, QUIET);
      if (!aliveRef.current || seq !== seqRef.current) return;
      setItems(unwrapList(res.data).items);
    } catch (err) {
      if (!aliveRef.current || seq !== seqRef.current) return;
      if (!silent) setError(getErrorMessage(err, UI_TEXT.loadError));
    } finally {
      if (aliveRef.current && seq === seqRef.current) setLoading(false);
    }
  }, []);

  // Initial load and unmount guard.
  useEffect(() => {
    aliveRef.current = true;
    if (enabled) load();
    return () => {
      aliveRef.current = false;
    };
  }, [enabled, load]);

  /** Creates a lab, then refreshes the list (counters come only from the list endpoint). */
  const create = useCallback(
    async (payload) => {
      const res = await api.post(BASE, payload, QUIET);
      await load({ silent: true });
      return res.data;
    },
    [load]
  );

  /** Updates a lab by id, then refreshes the list. */
  const update = useCallback(
    async (id, payload) => {
      const res = await api.put(`${BASE}/${id}`, payload, QUIET);
      await load({ silent: true });
      return res.data;
    },
    [load]
  );

  /** Deletes a lab by id, then refreshes the list. Errors are thrown to the caller. */
  const remove = useCallback(
    async (id) => {
      const res = await api.delete(`${BASE}/${id}`, QUIET);
      await load({ silent: true });
      return res.data;
    },
    [load]
  );

  return { items, loading, error, reload: load, create, update, remove };
}
