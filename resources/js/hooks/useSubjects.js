import { useCallback, useEffect, useRef, useState } from 'react';
import { api, API_BASE, getErrorMessage, unwrapList } from '../lib/api.js';
import { UI_TEXT } from '../components/ui/uiText.js';

// Subjects API: GET/POST/PUT/DELETE /api/v1/admin/subjects (real data only, no samples).
const BASE = `${API_BASE}/admin/subjects`;
// The list load shows its error inside the page, so no global toast for it.
const QUIET = { skipErrorToast: true };

// Builds the query string. "All" sends nothing: the Backend treats a present is_active as a filter.
function buildParams(facultyId, status) {
  const params = {};
  if (facultyId !== '' && facultyId !== null && facultyId !== undefined) params.faculty_id = facultyId;
  if (status === '1' || status === '0') params.is_active = Number(status);
  return params;
}

// Hook that owns the subjects list (with server-side filters) and its create/update/delete calls.
// Stale answers are dropped with a sequence counter; aliveRef blocks updates after unmount.
export default function useSubjects({ facultyId = '', status = '' } = {}) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const aliveRef = useRef(true);
  const seqRef = useRef(0);

  // Loads the list. silent=true (used after a mutation) keeps the table visible and ignores failures.
  const load = useCallback(
    async (options = {}) => {
      const silent = Boolean(options.silent);
      seqRef.current += 1;
      const seq = seqRef.current;
      if (!silent) {
        setLoading(true);
        setError(null);
      }
      try {
        const res = await api.get(BASE, { ...QUIET, params: buildParams(facultyId, status) });
        if (!aliveRef.current || seq !== seqRef.current) return;
        setItems(unwrapList(res.data).items);
        setError(null);
      } catch (err) {
        if (!aliveRef.current || seq !== seqRef.current) return;
        if (!silent) setError(getErrorMessage(err, UI_TEXT.loadError));
      } finally {
        if (aliveRef.current && seq === seqRef.current) setLoading(false);
      }
    },
    [facultyId, status],
  );

  // First load and reload whenever a filter changes; unmount bookkeeping.
  useEffect(() => {
    aliveRef.current = true;
    load();
    return () => {
      aliveRef.current = false;
    };
  }, [load]);

  // Creates a subject; errors (422 etc.) are thrown to the caller so the form can show them.
  const create = useCallback(
    async (payload) => {
      const res = await api.post(BASE, payload);
      await load({ silent: true });
      return res.data;
    },
    [load],
  );

  // Updates a subject (payload holds only the changed fields).
  const update = useCallback(
    async (id, payload) => {
      const res = await api.put(`${BASE}/${id}`, payload);
      await load({ silent: true });
      return res.data;
    },
    [load],
  );

  // Deletes a subject; the Backend answers 422 when exams, questions or passages depend on it.
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