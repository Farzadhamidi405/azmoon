
/**
 * ShiftsPage - admin management page for exam shifts.
 * Server-side filters (exam_id, lab_id), client-side text search, create/edit modal, and delete confirmation.
 * Permission flags only hide UI controls; the Backend Policy is the source of truth.
 */
import { useMemo, useState } from 'react';
import {
    Alert,
    Badge,
    Button,
    ConfirmDialog,
    DataTable,
    FilterBar,
    FilterInput,
    FilterSelect,
    PageHeader,
} from '../../components/ui/index.js';
import ShiftFormModal from '../../components/shifts/ShiftFormModal.jsx';
import AutoAssignModal from '../../components/shifts/AutoAssignModal.jsx';
import { useShifts } from '../../hooks/useShifts.js';
import { useLabs } from '../../hooks/useLabs.js';
import { useAuth } from '../../hooks/useAuth.js';
import { getErrorMessage } from '../../lib/api.js';
import { formatDateTime } from '../../lib/formatDate.js';
import { notifySuccess } from '../../lib/notify.js';
import { canViewExams } from '../../lib/permissions.js';

// Auto-Assign row action label (Persian written as Unicode escapes): Auto assign
const AUTO_ASSIGN_LABEL = '\u062A\u062E\u0635\u06CC\u0635 \u062E\u0648\u062F\u06A9\u0627\u0631';

// UI texts (Persian written as Unicode escapes, English meaning beside each).
const TEXT = Object.freeze({
    crumb: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631\u0647\u0627', // Labs
    pageTitle: '\u0634\u0641\u062A\u200C\u0647\u0627', // Shifts
    add: '\u0627\u0641\u0632\u0648\u062F\u0646 \u0634\u0641\u062A', // Add shift
    search: '\u062C\u0633\u062A\u062C\u0648', // Search
    searchHint: '\u062C\u0633\u062A\u062C\u0648 (\u0646\u0627\u0645 \u0634\u0641\u062A\u060C \u0622\u0632\u0645\u0648\u0646\u060C \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631)', // Search (shift name, exam, lab)
    reload: '\u0628\u0627\u0631\u06AF\u0630\u0627\u0631\u06CC \u0645\u062C\u062F\u062F', // Reload
    filters: '\u0641\u06CC\u0644\u062A\u0631 \u0634\u0641\u062A\u200C\u0647\u0627', // Shifts filters
    allExams: '\u0647\u0645\u0647 \u0622\u0632\u0645\u0648\u0646\u200C\u0647\u0627', // All exams
    allLabs: '\u0647\u0645\u0647 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631\u0647\u0627', // All labs
    colName: '\u0646\u0627\u0645 \u0634\u0641\u062A', // Shift name
    colExam: '\u0622\u0632\u0645\u0648\u0646', // Exam
    colLab: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631', // Lab
    colStart: '\u0632\u0645\u0627\u0646 \u0634\u0631\u0648\u0639', // Start time
    colEnd: '\u0632\u0645\u0627\u0646 \u067E\u0627\u06CC\u0627\u0646', // End time
    colCapacity: '\u0638\u0631\u0641\u06CC\u062A', // Capacity
    colActions: '\u0639\u0645\u0644\u06CC\u0627\u062A', // Actions
    edit: '\u0648\u06CC\u0631\u0627\u06CC\u0634', // Edit
    remove: '\u062D\u0630\u0641', // Delete
    cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
    empty: '\u0634\u0641\u062A\u06CC \u062B\u0628\u062A \u0646\u0634\u062F\u0647 \u0627\u0633\u062A', // No shifts registered
    noResults: '\u0646\u062A\u06CC\u062C\u0647\u200C\u0627\u06CC \u06CC\u0627\u0641\u062A \u0646\u0634\u062F', // No results found
    deleteTitle: '\u062D\u0630\u0641 \u0634\u0641\u062A', // Delete shift
    deleteAskPrefix: '\u0634\u0641\u062A \u00AB', // Shift <<
    deleteAskSuffix: '\u00BB \u062D\u0630\u0641 \u0634\u0648\u062F\u061F', // >> will be deleted?
    deleteFailed: '\u062D\u0630\u0641 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F', // Delete failed
    created: '\u0634\u0641\u062A \u0627\u0636\u0627\u0641\u0647 \u0634\u062F', // Shift added
    updated: '\u0634\u0641\u062A \u0648\u06CC\u0631\u0627\u06CC\u0634 \u0634\u062F', // Shift updated
    deleted: '\u0634\u0641\u062A \u062D\u0630\u0641 \u0634\u062F', // Shift deleted
    noLabs: '\u0627\u0628\u062A\u062F\u0627 \u06CC\u06A9 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631 \u062B\u0628\u062A \u06A9\u0646\u06CC\u062F', // Register a lab first
});

/** Extracts the row object from DataTable column render arguments. */
function rowOf(args) {
    return (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
}

/** Formats a shift datetime in LTR layout; returns null when empty so DataTable renders an em dash. */
function dateTimeCell(value) {
    const formatted = formatDateTime(value);
    if (!formatted) return null;
    return (
        <span dir="ltr" className="whitespace-nowrap text-xs text-ink">
            {formatted}
        </span>
    );
}

/** Main admin page for shifts management. */
export default function ShiftsPage() {
    // Permission checks (UI visibility only; Backend enforces ShiftPolicy / ExamPolicy).
    const { user, can } = useAuth();
    const canCreate = can('labs.create');
    const canUpdate = can('labs.update');
    const canDelete = can('labs.delete');
    const examListAllowed = canViewExams(user);

    // Shifts resource and labs list (used by both filters and form).
    const { items, exams, query, loading, error, setFilters, reload, create, update, remove } = useShifts({
        canLoadExams: examListAllowed,
    });
    const labsState = useLabs();
    const labs = labsState.items;

    // Client-side search input, create/edit modal state, and delete dialog state.
    const [searchText, setSearchText] = useState('');
    const [appliedSearch, setAppliedSearch] = useState('');
    const [form, setForm] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleteError, setDeleteError] = useState(null);
    const [deleting, setDeleting] = useState(false);

    // Shift currently opened in the Auto-Assign modal (null when closed).
    const [autoAssignShift, setAutoAssignShift] = useState(null);

    // Derived flags for empty/disabled states.
    const noLabs = !labsState.loading && !labsState.error && labs.length === 0;
    const addDisabled = labsState.loading || labs.length === 0;
    const activeSearch = (searchText || appliedSearch).trim().toLowerCase();
    const filtersActive = Boolean(query.examId || query.labId || activeSearch);

    // Client-side search filtering across shift name, exam title, and lab name.
    const visibleRows = useMemo(() => {
        if (!activeSearch) return items;
        return items.filter((row) => {
            const shiftName = (row && row.name ? String(row.name) : '').toLowerCase();
            const examTitle = (row && row.exam && row.exam.title ? String(row.exam.title) : '').toLowerCase();
            const labName = (row && row.lab && row.lab.name ? String(row.lab.name) : '').toLowerCase();
            return (
                shiftName.includes(activeSearch) ||
                examTitle.includes(activeSearch) ||
                labName.includes(activeSearch)
            );
        });
    }, [items, activeSearch]);

    /** Commits the search text when the filter form is submitted. */
    function applySearch() {
        setAppliedSearch(searchText.trim());
    }

    /** Handles create or update submission from ShiftFormModal. */
    async function handleSubmitForm(payload, shift) {
        if (shift) await update(shift.id, payload);
        else await create(payload);
        notifySuccess(shift ? TEXT.updated : TEXT.created);
    }

    /** Opens the delete confirmation dialog for a row. */
    function openDelete(row) {
        setDeleteError(null);
        setDeleteTarget(row);
    }

    /** Closes the delete confirmation dialog when not busy. */
    function closeDelete() {
        if (deleting) return;
        setDeleteTarget(null);
        setDeleteError(null);
    }

    /** Executes shift deletion and displays 422 integrity errors inside the dialog. */
    async function handleConfirmDelete() {
        if (!deleteTarget || deleting) return;
        setDeleting(true);
        setDeleteError(null);
        try {
            await remove(deleteTarget.id);
            notifySuccess(TEXT.deleted);
            setDeleteTarget(null);
        } catch (err) {
            setDeleteError(getErrorMessage(err, TEXT.deleteFailed));
        } finally {
            setDeleting(false);
        }
    }

    // Table columns definition.
    const columns = [
        {
            key: 'name',
            header: TEXT.colName,
            primary: true,
            render: (...args) => {
                const row = rowOf(args);
                return row.name ? <span className="font-semibold text-navy">{row.name}</span> : null;
            },
        },
        {
            key: 'exam',
            header: TEXT.colExam,
            render: (...args) => {
                const row = rowOf(args);
                if (row.exam && row.exam.title) return row.exam.title;
                return row.exam_id != null ? `#${row.exam_id}` : null;
            },
        },
        {
            key: 'lab',
            header: TEXT.colLab,
            render: (...args) => {
                const row = rowOf(args);
                if (row.lab && row.lab.name) return row.lab.name;
                return row.lab_id != null ? `#${row.lab_id}` : null;
            },
        },
        {
            key: 'start_time',
            header: TEXT.colStart,
            render: (...args) => dateTimeCell(rowOf(args).start_time),
        },
        {
            key: 'end_time',
            header: TEXT.colEnd,
            render: (...args) => dateTimeCell(rowOf(args).end_time),
        },
        {
            key: 'capacity',
            header: TEXT.colCapacity,
            align: 'center',
            render: (...args) => {
                const row = rowOf(args);
                if (row.capacity === null || row.capacity === undefined || row.capacity === '') return null;
                return (
                    <Badge variant="on" dot={false}>
                        <span dir="ltr">{row.capacity}</span>
                    </Badge>
                );
            },
        },
    ];

    // Append actions column only if the user can edit or delete shifts.
    if (canUpdate || canDelete) {
        columns.push({
            key: 'actions',
            header: TEXT.colActions,
            actions: true,
            align: 'end',
            render: (...args) => {
                const row = rowOf(args);
                return (
                    <div className="flex flex-wrap items-center justify-end gap-1.5">
                        {canUpdate ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setForm({ mode: 'edit', shift: row })}
                            >
                                {TEXT.edit}
                            </Button>
                        ) : null}
                        {canUpdate ? (
                          <Button
                            size="sm"
                            variant="teal"
                            onClick={() => setAutoAssignShift(row)}
                          >
                            {AUTO_ASSIGN_LABEL}
                          </Button>
                        ) : null}
                        {canDelete ? (
                            <Button size="sm" variant="danger" onClick={() => openDelete(row)}>
                                {TEXT.remove}
                            </Button>
                        ) : null}
                    </div>
                );
            },
        });
    }

    // Header actions: Add Shift button (requires labs.create and at least one registered lab).
    const headerActions = canCreate ? (
        <div className="flex flex-col items-end gap-1">
            <Button
                icon="plus"
                disabled={addDisabled}
                onClick={() => setForm({ mode: 'create', shift: null })}
            >
                {TEXT.add}
            </Button>
            {noLabs ? <p className="m-0 text-xs text-ink-soft">{TEXT.noLabs}</p> : null}
        </div>
    ) : null;

    return (
        <div className="space-y-4">
            <PageHeader title={TEXT.pageTitle} crumb={TEXT.crumb} actions={headerActions} />

            <FilterBar
                label={TEXT.filters}
                onSubmit={applySearch}
                actions={
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={loading}
                        onClick={() => reload()}
                    >
                        {TEXT.reload}
                    </Button>
                }
            >
                <FilterInput
                    type="search"
                    aria-label={TEXT.search}
                    placeholder={TEXT.searchHint}
                    value={searchText}
                    onChange={(e) => {
                        setSearchText(e.target.value);
                        if (!e.target.value.trim()) setAppliedSearch('');
                    }}
                />
                <Button type="submit" size="sm" variant="outline">
                    {TEXT.search}
                </Button>
                <FilterSelect
                    aria-label={TEXT.colExam}
                    value={query.examId}
                    onChange={(e) => setFilters({ examId: e.target.value })}
                >
                    <option value="">{TEXT.allExams}</option>
                    {exams.map((ex) => (
                        <option key={ex.id} value={String(ex.id)}>
                            {ex.title || `#${ex.id}`}
                        </option>
                    ))}
                </FilterSelect>
                <FilterSelect
                    aria-label={TEXT.colLab}
                    value={query.labId}
                    onChange={(e) => setFilters({ labId: e.target.value })}
                >
                    <option value="">{TEXT.allLabs}</option>
                    {labs.map((l) => (
                        <option key={l.id} value={String(l.id)}>
                            {l.name}
                        </option>
                    ))}
                </FilterSelect>
            </FilterBar>

            <DataTable
                columns={columns}
                rows={visibleRows}
                loading={loading}
                error={error}
                onRetry={() => reload()}
                emptyIcon="clock"
                emptyTitle={filtersActive ? TEXT.noResults : TEXT.empty}
                mobile="scroll"
            />

            {form ? (
                <ShiftFormModal
                    key={form.shift ? `edit-${form.shift.id}` : 'create'}
                    open
                    shift={form.shift}
                    labs={labs}
                    exams={exams}
                    canPickExamList={examListAllowed}
                    onClose={() => setForm(null)}
                    onSubmit={handleSubmitForm}
                />
            ) : null}

            {autoAssignShift ? (
              <AutoAssignModal
                key={`auto-${autoAssignShift.id}`}
                open
                shift={autoAssignShift}
                onClose={() => setAutoAssignShift(null)}
              />
            ) : null}

            <ConfirmDialog
                open={Boolean(deleteTarget)}
                onClose={closeDelete}
                onConfirm={handleConfirmDelete}
                title={TEXT.deleteTitle}
                message={
                    deleteTarget
                        ? `${TEXT.deleteAskPrefix}${deleteTarget.name}${TEXT.deleteAskSuffix}`
                        : ''
                }
                confirmLabel={TEXT.remove}
                cancelLabel={TEXT.cancel}
                tone="danger"
                loading={deleting}
            >
                {deleteError ? <Alert tone="error">{deleteError}</Alert> : null}
            </ConfirmDialog>
        </div>
    );
}
