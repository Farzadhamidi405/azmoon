/**
 * useShifts - loads and mutates the exam shifts list (admin API).
 * Server-side filters (exam_id, lab_id); conditional exams loading; silent reload after mutations; stale-response guard.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, API_BASE, getErrorMessage } from '../lib/api.js';
import { UI_TEXT } from '../components/ui/uiText.js';

// Shifts endpoint base path.
const BASE = `${API_BASE}/admin/shifts`;

// Exams endpoint path (used only when the current user has role permission to list exams).
const EXAMS_URL = `${API_BASE}/admin/exams`;

// Request config: errors are handled inline by the caller, not as a global toast.
const QUIET = { skipErrorToast: true };

// Page size when fetching exams from the paginated /admin/exams endpoint.
const EXAMS_PER_PAGE = 100;

// Initial server-side filter state.
const INITIAL_QUERY = { examId: '', labId: '' };

/** Builds query parameters for GET /admin/shifts; only non-empty filters are sent. */
function buildParams(query) {
    const params = {};
    if (query.examId) params.exam_id = query.examId;
    if (query.labId) params.lab_id = query.labId;
    return params;
}

/** Extracts an array of records from either a plain array response or a Laravel paginator payload. */
function unwrapRows(payload) {
    if (Array.isArray(payload)) return payload;
    if (payload && typeof payload === 'object' && Array.isArray(payload.data)) return payload.data;
    return [];
}

/** Collects unique exam objects ({ id, title }) attached to shift rows. */
function extractShiftExams(shifts) {
    const out = [];
    if (!Array.isArray(shifts)) return out;
    shifts.forEach((s) => {
        if (s && s.exam && s.exam.id != null) {
            out.push({ id: s.exam.id, title: s.exam.title || `#${s.exam.id}` });
        }
    });
    return out;
}

/**
 * Hook for the shifts resource.
 * @param {{enabled?: boolean, canLoadExams?: boolean}} options - controls initial load and whether /admin/exams is queried.
 * @returns {object} items, exams, examsLoading, query, loading, error, setFilters, reload, create, update, remove.
 */
export function useShifts({ enabled = true, canLoadExams = false } = {}) {
    // Shift rows for the active filter query.
    const [items, setItems] = useState([]);
    // Exams fetched from GET /admin/exams (empty when the user role cannot list exams).
    const [apiExams, setApiExams] = useState([]);
    // Exams discovered on shift rows across loads so filter options stay stable even after filtering.
    const [shiftExams, setShiftExams] = useState([]);
    // True while GET /admin/exams is running.
    const [examsLoading, setExamsLoading] = useState(Boolean(enabled && canLoadExams));
    // Active server-side filters.
    const [query, setQuery] = useState(INITIAL_QUERY);
    // True while a non-silent shifts load is running.
    const [loading, setLoading] = useState(Boolean(enabled));
    // Error message from the latest non-silent shifts load.
    const [error, setError] = useState(null);

    // Sequence counters and unmount guard to ignore stale responses.
    const seqRef = useRef(0);
    const examSeqRef = useRef(0);
    const aliveRef = useRef(true);
    const queryRef = useRef(query);

    // Keeps queryRef synchronized with the latest filter state.
    useEffect(() => {
        queryRef.current = query;
    }, [query]);

    // Unmount guard (re-armed on remount).
    useEffect(() => {
        aliveRef.current = true;
        return () => {
            aliveRef.current = false;
        };
    }, []);

    /**
     * Loads the shifts list from GET /admin/shifts.
     * @param {{silent?: boolean, query?: object}} options - silent keeps current rows and suppresses load errors.
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
            const rows = unwrapRows(res.data ? res.data.data : null);
            setItems(rows);
            // Remember any exam objects present on the loaded shifts.
            const discovered = extractShiftExams(rows);
            if (discovered.length > 0) {
                setShiftExams((prev) => {
                    const map = new Map();
                    prev.forEach((e) => map.set(String(e.id), e));
                    discovered.forEach((e) => map.set(String(e.id), e));
                    return Array.from(map.values());
                });
            }
        } catch (err) {
            if (!aliveRef.current || seq !== seqRef.current) return;
            if (!silent) setError(getErrorMessage(err, UI_TEXT.loadError));
        } finally {
            if (aliveRef.current && seq === seqRef.current) setLoading(false);
        }
    }, []);

    /**
     * Loads the exams list from GET /admin/exams when the user role permits it (ExamPolicy@viewAny).
     * Failures are ignored silently so the shifts page remains usable.
     */
    const loadExams = useCallback(async () => {
        if (!canLoadExams) {
            setApiExams([]);
            setExamsLoading(false);
            return;
        }
        const seq = ++examSeqRef.current;
        setExamsLoading(true);
        try {
            const res = await api.get(EXAMS_URL, { ...QUIET, params: { per_page: EXAMS_PER_PAGE } });
            if (!aliveRef.current || seq !== examSeqRef.current) return;
            const rows = unwrapRows(res.data ? res.data.data : null);
            setApiExams(rows);
        } catch (err) {
            if (!aliveRef.current || seq !== examSeqRef.current) return;
        } finally {
            if (aliveRef.current && seq === examSeqRef.current) setExamsLoading(false);
        }
    }, [canLoadExams]);

    // Reloads shifts whenever enabled or filter query changes.
    useEffect(() => {
        if (enabled) load({ query });
    }, [enabled, query, load]);

    // Loads exams once when enabled and allowed by role.
    useEffect(() => {
        if (enabled) loadExams();
    }, [enabled, loadExams]);

    // Merged, deduplicated list of exams from /admin/exams and from loaded shift relations.
    const exams = useMemo(() => {
        const map = new Map();
        apiExams.forEach((e) => {
            if (e && e.id != null) map.set(String(e.id), e);
        });
        shiftExams.forEach((e) => {
            if (e && e.id != null && !map.has(String(e.id))) map.set(String(e.id), e);
        });
        return Array.from(map.values());
    }, [apiExams, shiftExams]);

    /** Updates the server-side filter query. */
    const setFilters = useCallback((patch) => {
        setQuery((cur) => ({ ...cur, ...patch }));
    }, []);

    /** Creates a shift, then refreshes the list silently. */
    const create = useCallback(
        async (payload) => {
            const res = await api.post(BASE, payload, QUIET);
            await load({ silent: true });
            return res.data;
        },
        [load],
    );

    /** Updates a shift by id, then refreshes the list silently. */
    const update = useCallback(
        async (id, payload) => {
            const res = await api.put(`${BASE}/${id}`, payload, QUIET);
            await load({ silent: true });
            return res.data;
        },
        [load],
    );

    /** Deletes a shift by id, then refreshes the list silently. */
    const remove = useCallback(
        async (id) => {
            const res = await api.delete(`${BASE}/${id}`, QUIET);
            await load({ silent: true });
            return res.data;
        },
        [load],
    );

    return {
        items,
        exams,
        examsLoading,
        query,
        loading,
        error,
        setFilters,
        reload: load,
        create,
        update,
        remove,
    };
}

export default useShifts;