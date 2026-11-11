import { useCallback, useEffect, useRef, useState } from 'react';
import { api, API_BASE, getErrorMessage, unwrapList } from '../lib/api.js';
import { UI_TEXT } from '../components/ui/uiText.js';

// Subject access API: GET/POST /admin/subjects/{id}/access and DELETE /admin/subjects/{id}/access/{recordId}.
const SUBJECTS_BASE = `${API_BASE}/admin/subjects`;
// Users list, used only to pick who receives access: GET /admin/users.
const USERS_URL = `${API_BASE}/admin/users`;
// Every error is shown inside the modal, so no global toast for these calls.
const QUIET = { skipErrorToast: true };

// Hook that owns the access list of ONE subject and (optionally) the users list for the add form.
// subjectId = null means the modal is closed: nothing is requested and state is reset.
// Stale answers are dropped with sequence counters; aliveRef blocks updates after unmount.
export default function useSubjectAccess(subjectId, { loadUsers = false } = {}) {
  const hasSubject = subjectId !== null && subjectId !== undefined;
  const wantUsers = hasSubject && Boolean(loadUsers);

  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true); // starts true so the first open never flashes "empty"
  const [error, setError] = useState(null);
  const [users, setUsers] = useState([]);
  const [usersLoading, setUsersLoading] = useState(true);
  const [usersError, setUsersError] = useState(null); // { status, message } or null
  const aliveRef = useRef(true);
  const seqRef = useRef(0);
  const usersSeqRef = useRef(0);

  // Unmount bookkeeping (mount-only so it is never toggled by dependency changes).
  useEffect(() => {
    aliveRef.current = true;
    return () => {
      aliveRef.current = false;
    };
  }, []);

  // Loads the access list. silent=true (after a mutation) keeps the table visible and ignores failures.
  const load = useCallback(
    async (options = {}) => {
      if (!hasSubject) return;
      const silent = Boolean(options.silent);
      seqRef.current += 1;
      const seq = seqRef.current;
      if (!silent) {
        setLoading(true);
        setError(null);
        setItems([]);
      }
      try {
        const res = await api.get(`${SUBJECTS_BASE}/${subjectId}/access`, QUIET);
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
    [hasSubject, subjectId],
  );

  // Loads when a subject is opened; resets everything when the modal closes.
  useEffect(() => {
    if (hasSubject) {
      load();
    } else {
      seqRef.current += 1; // drop any in-flight answer
      setItems([]);
      setError(null);
      setLoading(true);
    }
  }, [hasSubject, load]);

  // Loads the users for the add form (only called when the caller may see users).
  const loadUsersList = useCallback(async () => {
    usersSeqRef.current += 1;
    const seq = usersSeqRef.current;
    setUsersLoading(true);
    setUsersError(null);
    try {
      const res = await api.get(USERS_URL, QUIET);
      if (!aliveRef.current || seq !== usersSeqRef.current) return;
      setUsers(unwrapList(res.data).items);
    } catch (err) {
      if (!aliveRef.current || seq !== usersSeqRef.current) return;
      setUsersError({ status: err ? err.status : undefined, message: getErrorMessage(err, UI_TEXT.loadError) });
    } finally {
      if (aliveRef.current && seq === usersSeqRef.current) setUsersLoading(false);
    }
  }, []);

  // The users request is sent only while the modal is open AND the caller asked for it.
  useEffect(() => {
    if (wantUsers) {
      loadUsersList();
    } else {
      usersSeqRef.current += 1;
      setUsers([]);
      setUsersError(null);
      setUsersLoading(true);
    }
  }, [wantUsers, loadUsersList]);

  // Grants access to a user; errors (422, 403, ...) are thrown to the caller.
  const add = useCallback(
    async (userId) => {
      const res = await api.post(`${SUBJECTS_BASE}/${subjectId}/access`, { user_id: userId }, QUIET);
      await load({ silent: true });
      return res.data;
    },
    [subjectId, load],
  );

  // Revokes one access record; the Backend answers 404 when it no longer belongs to this subject.
  const revoke = useCallback(
    async (recordId) => {
      const res = await api.delete(`${SUBJECTS_BASE}/${subjectId}/access/${recordId}`, QUIET);
      await load({ silent: true });
      return res.data;
    },
    [subjectId, load],
  );

  return { items, loading, error, reload: load, add, revoke, users, usersLoading, usersError, reloadUsers: loadUsersList };
}