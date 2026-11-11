/**
 * useAutoAssign - preview and confirm automatic computer assignment for one shift (admin API).
 * Preview is read-only (confirmed=false); confirm persists the plan (confirmed=true).
 * Endpoint: POST /api/v1/admin/labs/shifts/{id}/auto-assign (ShiftPolicy@autoAssign, labs.update).
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { api, API_BASE, getErrorMessage } from '../lib/api.js';
import { UI_TEXT } from '../components/ui/uiText.js';

// Request config: errors are handled inline by the caller, not as a global toast.
const QUIET = { skipErrorToast: true };

// Fallback message when confirm fails without a usable server message.
const CONFIRM_FAILED = '\u062A\u062E\u0635\u06CC\u0635 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F.'; // Assignment failed.

/**
 * Hook for one shift's auto-assign flow.
 * @param {number|string|null} shiftId - target shift id (null disables requests).
 * @returns {object} preview, loading, error, confirming, confirmError, result, reload, confirm.
 */
export function useAutoAssign(shiftId) {
  // Preview payload (data object of the API response) and its loading/error state.
  const [preview, setPreview] = useState(null);
  const [loading, setLoading] = useState(shiftId != null);
  const [error, setError] = useState(null);
  // Confirm state: in-flight flag, inline error and the final result.
  const [confirming, setConfirming] = useState(false);
  const [confirmError, setConfirmError] = useState(null);
  const [result, setResult] = useState(null);

  // Stale-response guard, unmount guard and double-click guard.
  const seqRef = useRef(0);
  const aliveRef = useRef(true);
  const busyRef = useRef(false);

  const url = shiftId != null ? `${API_BASE}/admin/labs/shifts/${shiftId}/auto-assign` : null;

  // Unmount guard (re-armed on remount).
  useEffect(() => {
    aliveRef.current = true;
    return () => {
      aliveRef.current = false;
    };
  }, []);

  /** Loads the read-only preview (confirmed=false). Nothing is saved by this call. */
  const loadPreview = useCallback(async () => {
    if (!url) return;
    const seq = ++seqRef.current;
    setLoading(true);
    setError(null);
    setConfirmError(null);
    try {
      const res = await api.post(url, { confirmed: false }, QUIET);
      if (!aliveRef.current || seq !== seqRef.current) return;
      const body = res.data || {};
      setPreview(body.data || null);
    } catch (err) {
      if (!aliveRef.current || seq !== seqRef.current) return;
      setPreview(null);
      setError(getErrorMessage(err, UI_TEXT.loadError));
    } finally {
      if (aliveRef.current && seq === seqRef.current) setLoading(false);
    }
  }, [url]);

  // Loads the preview when the target shift changes.
  useEffect(() => {
    loadPreview();
  }, [loadPreview]);

  /** Confirms the plan (confirmed=true). Runs at most once per successful result. */
  const confirm = useCallback(async () => {
    if (!url || busyRef.current || result) return;
    busyRef.current = true;
    setConfirming(true);
    setConfirmError(null);
    try {
      const res = await api.post(url, { confirmed: true }, QUIET);
      if (!aliveRef.current) return;
      const body = res.data || {};
      setResult({
        assigned: Number(body.assigned) || 0,
        message: typeof body.message === 'string' ? body.message : '',
      });
    } catch (err) {
      if (!aliveRef.current) return;
      setConfirmError(getErrorMessage(err, CONFIRM_FAILED));
    } finally {
      busyRef.current = false;
      if (aliveRef.current) setConfirming(false);
    }
  }, [url, result]);

  return { preview, loading, error, confirming, confirmError, result, reload: loadPreview, confirm };
}

export default useAutoAssign;
