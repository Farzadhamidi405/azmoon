import { useCallback, useEffect, useRef, useState } from 'react';
import { api, API_BASE, getErrorMessage, unwrapList } from '../lib/api.js';
import { UI_TEXT } from '../components/ui/uiText.js';

// Faculties API: GET/POST/PUT/DELETE /api/v1/admin/faculties (real data only, no samples).
const BASE = `${API_BASE}/admin/faculties`;
// The list load shows its error inside the page, so no global toast for it.
const QUIET = { skipErrorToast: true };

// Hook that owns the faculties list and its create/update/delete calls.
// Stale answers are dropped with a sequence counter; aliveRef blocks updates after unmount.
export default function useFaculties({ enabled = true } = {}) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(enabled);
  const [error, setError] = useState(null);
  const aliveRef = useRef(true);
  const seqRef = useRef(0);

  // Loads the list. silent=true (used after a mutation) keeps the table visible and ignores failures.
  const load = useCallback(async (options = {}) => {
    const silent = Boolean(options.silent);
    if (!enabled) return; // No permission: send no request (loading starts as false)
    seqRef.current += 1;
    const seq = seqRef.current;
    if (!silent) {
      setLoading(true);
      setError(null);
    }
    try {
      const res = await api.get(BASE, QUIET);
      if (!aliveRef.current || seq !== seqRef.current) return;
      setItems(unwrapList(res.data).items);
      setError(null);
    } catch (err) {
      if (!aliveRef.current || seq !== seqRef.current) return;
      if (!silent) setError(getErrorMessage(err, UI_TEXT.loadError));
    } finally {
      if (aliveRef.current && seq === seqRef.current) setLoading(false);
    }
  }, [enabled]);

  // Mount/unmount bookkeeping and the first load.
  useEffect(() => {
    aliveRef.current = true;
    load();
    return () => {
      aliveRef.current = false;
    };
  }, [load]);

  // Creates a faculty; errors (422 etc.) are thrown to the caller so the form can show them.
  const create = useCallback(
    async (payload) => {
      const res = await api.post(BASE, payload);
      await load({ silent: true });
      return res.data;
    },
    [load],
  );

  // Updates a faculty (payload holds only the changed fields).
  const update = useCallback(
    async (id, payload) => {
      const res = await api.put(`${BASE}/${id}`, payload);
      await load({ silent: true });
      return res.data;
    },
    [load],
  );

  // Deletes a faculty; the Backend answers 422 when it still has subjects.
  const remove = useCallback(
    async (id) => {
      const res = await api.delete(`${BASE}/${id}`);
      await load({ silent: true });
      return res.data;
    },
    [load],
  );

  return { items, loading, error, reload: load, create, update, remove };
}