/**
 * LabsPage - admin list of labs with computer counters, search and CRUD.
 * Data comes only from the real API (GET /admin/labs); permissions only hide buttons,
 * the backend remains the source of truth.
 */
import { useMemo, useState } from 'react';
import { Badge, Button, ConfirmDialog, DataTable, FilterBar, FilterInput, PageHeader } from '../../components/ui/index.js';
import LabFormModal from '../../components/labs/LabFormModal.jsx';
import { useLabs } from '../../hooks/useLabs.js';
import { useAuth } from '../../hooks/useAuth.js';
import { getErrorMessage } from '../../lib/api.js';
import { notifySuccess } from '../../lib/notify.js';

// UI texts (Persian as unicode escapes).
const TEXT = {
  // Labs
  title: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631\u0647\u0627',
  // Kunduz University
  crumbRoot: '\u067E\u0648\u06BE\u0646\u062A\u0648\u0646 \u06A9\u0646\u062F\u0632',
  // Base information
  crumbPage: '\u0627\u0637\u0644\u0627\u0639\u0627\u062A \u067E\u0627\u06CC\u0647',
  // Add lab
  add: '\u0627\u0641\u0632\u0648\u062F\u0646 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631',
  // Name
  colName: '\u0646\u0627\u0645',
  // Location
  colLocation: '\u0645\u0648\u0642\u0639\u06CC\u062A',
  // Total computers
  colTotal: '\u06A9\u0644 \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631\u0647\u0627',
  // Online
  colOnline: '\u0622\u0646\u0644\u0627\u06CC\u0646',
  // Offline
  colOffline: '\u0622\u0641\u0644\u0627\u06CC\u0646',
  // In exam
  colExam: '\u062F\u0631 \u0627\u0645\u062A\u062D\u0627\u0646',
  // Under maintenance
  colMaintenance: '\u062F\u0631 \u062A\u0639\u0645\u06CC\u0631',
  // Locked
  colLocked: '\u0642\u0641\u0644\u200C\u0634\u062F\u0647',
  // Actions
  colActions: '\u0639\u0645\u0644\u06CC\u0627\u062A',
  // Edit
  edit: '\u0648\u06CC\u0631\u0627\u06CC\u0634',
  // Delete
  remove: '\u062D\u0630\u0641',
  // Cancel
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641',
  // No labs registered yet
  emptyTitle: '\u0647\u0646\u0648\u0632 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631\u06CC \u062B\u0628\u062A \u0646\u0634\u062F\u0647 \u0627\u0633\u062A',
  // No results found
  noResults: '\u0646\u062A\u06CC\u062C\u0647\u200C\u0627\u06CC \u06CC\u0627\u0641\u062A \u0646\u0634\u062F',
  // Change the search phrase
  noResultsHint: '\u0639\u0628\u0627\u0631\u062A \u062C\u0633\u062A\u062C\u0648 \u0631\u0627 \u062A\u063A\u06CC\u06CC\u0631 \u062F\u0647\u06CC\u062F',
  // Search by name or location
  searchPlaceholder: '\u062C\u0633\u062A\u062C\u0648\u06CC \u0646\u0627\u0645 \u06CC\u0627 \u0645\u0648\u0642\u0639\u06CC\u062A',
  // Reload
  reload: '\u0628\u0627\u0631\u06AF\u0630\u0627\u0631\u06CC \u0645\u062C\u062F\u062F',
  // Delete lab
  deleteTitle: '\u062D\u0630\u0641 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631',
  // Are you sure you want to delete this lab?
  deleteMessage: '\u0622\u06CC\u0627 \u0627\u0632 \u062D\u0630\u0641 \u0627\u06CC\u0646 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631 \u0645\u0637\u0645\u0626\u0646 \u0647\u0633\u062A\u06CC\u062F\u061F',
  // Deleting the lab failed
  deleteFailed: '\u062D\u0630\u0641 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F',
  // Lab created
  created: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631 \u0627\u06CC\u062C\u0627\u062F \u0634\u062F',
  // Lab updated
  updated: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631 \u0648\u06CC\u0631\u0627\u06CC\u0634 \u0634\u062F',
  // Lab deleted
  deleted: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631 \u062D\u0630\u0641 \u0634\u062F',
};

/**
 * Normalizes text for searching: unifies Arabic/Persian yeh and kaf, trims, lowercases.
 * (No shared normalize helper exists in the project.)
 */
function normalizeText(value) {
  return String(value == null ? '' : value)
    .replace(/\u064A/g, '\u06CC') // Arabic yeh -> Persian yeh
    .replace(/\u0643/g, '\u06A9') // Arabic kaf -> Persian kaf
    .trim()
    .toLowerCase();
}

/** Finds the row object among the arguments DataTable passes to a column renderer. */
function pickRow(args) {
  return args.find((a) => a && typeof a === 'object' && !Array.isArray(a) && 'id' in a) || {};
}

/** Converts an API counter to a safe number. */
function toCount(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/**
 * Counter cell: no variant = plain number; zero = dim plain number; otherwise a colored Badge.
 */
function CountCell({ value, variant }) {
  const n = toCount(value);
  if (!variant) return <span>{n}</span>;
  if (n === 0) return <span className="text-slate-400">0</span>;
  return (
    <Badge variant={variant} dot={false}>
      {n}
    </Badge>
  );
}

/** Labs management page. */
export default function LabsPage() {
  const { can } = useAuth();
  const { items, loading, error, reload, create, update, remove } = useLabs();

  // Permission flags (hide buttons only; the backend enforces access).
  const canCreate = can('labs.create');
  const canUpdate = can('labs.update');
  const canDelete = can('labs.delete');

  // Search text.
  const [query, setQuery] = useState('');
  // Create/edit modal state.
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  // Delete dialog state.
  const [target, setTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');

  // Client-side filter on name and location.
  const filtered = useMemo(() => {
    const q = normalizeText(query);
    if (!q) return items;
    return items.filter((lab) => normalizeText(lab.name).includes(q) || normalizeText(lab.location).includes(q));
  }, [items, query]);

  /** Opens the modal in create mode. */
  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  /** Closes the create/edit modal. */
  const closeForm = () => {
    setFormOpen(false);
    setEditing(null);
  };

  /** Submit handler for the modal: throws on failure so the modal shows the error. */
  const handleSubmit = async (payload) => {
    if (editing) {
      await update(editing.id, payload);
      notifySuccess(TEXT.updated);
    } else {
      await create(payload);
      notifySuccess(TEXT.created);
    }
    closeForm();
  };

  /** Closes the delete dialog (blocked while deleting). */
  const closeDelete = () => {
    if (deleting) return;
    setTarget(null);
    setDeleteError('');
  };

  /** Confirms deletion; 422 messages are shown inside the dialog, 404 closes it and reloads. */
  const confirmDelete = async () => {
    if (!target || deleting) return;
    setDeleting(true);
    setDeleteError('');
    try {
      await remove(target.id);
      notifySuccess(TEXT.deleted);
      setTarget(null);
    } catch (err) {
      if (err && err.status === 404) {
        // Already deleted elsewhere: close and refresh the list.
        setTarget(null);
        reload({ silent: true });
      } else {
        setDeleteError(getErrorMessage(err, TEXT.deleteFailed));
      }
    } finally {
      setDeleting(false);
    }
  };

  // Table columns (actions column only when the user may edit or delete).
  const columns = useMemo(() => {
    const cols = [
      { key: 'name', header: TEXT.colName, primary: true, render: (...args) => pickRow(args).name },
      { key: 'location', header: TEXT.colLocation, render: (...args) => pickRow(args).location || null },
      { key: 'computers_count', header: TEXT.colTotal, align: 'center', render: (...args) => <CountCell value={pickRow(args).computers_count} /> },
      { key: 'online_count', header: TEXT.colOnline, align: 'center', render: (...args) => <CountCell value={pickRow(args).online_count} variant="on" /> },
      { key: 'offline_count', header: TEXT.colOffline, align: 'center', render: (...args) => <CountCell value={pickRow(args).offline_count} variant="off" /> },
      { key: 'exam_count', header: TEXT.colExam, align: 'center', render: (...args) => <CountCell value={pickRow(args).exam_count} variant="pending" /> },
      { key: 'maintenance_count', header: TEXT.colMaintenance, align: 'center', render: (...args) => <CountCell value={pickRow(args).maintenance_count} variant="bad" /> },
      { key: 'locked_count', header: TEXT.colLocked, align: 'center', render: (...args) => <CountCell value={pickRow(args).locked_count} variant="bad" /> },
    ];
    if (canUpdate || canDelete) {
      cols.push({
        key: 'actions',
        header: TEXT.colActions,
        actions: true,
        render: (...args) => {
          const row = pickRow(args);
          return (
            <div className="flex items-center gap-2">
              {canUpdate ? (
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    setEditing(row);
                    setFormOpen(true);
                  }}
                >
                  {TEXT.edit}
                </Button>
              ) : null}
              {canDelete ? (
                <Button
                  size="sm"
                  variant="danger"
                  onClick={() => {
                    setDeleteError('');
                    setTarget(row);
                  }}
                >
                  {TEXT.remove}
                </Button>
              ) : null}
            </div>
          );
        },
      });
    }
    return cols;
  }, [canUpdate, canDelete]);

  // Empty-state text depends on whether a search is active.
  const searching = normalizeText(query) !== '';

  return (
    <div className="space-y-4">
      <PageHeader
        title={TEXT.title}
        crumb={[TEXT.crumbRoot, TEXT.crumbPage]}
        actions={canCreate ? <Button onClick={openCreate}>{TEXT.add}</Button> : null}
      />

      <FilterBar
        onSubmit={(e) => {
          if (e && e.preventDefault) e.preventDefault();
        }}
        actions={
          <Button type="button" variant="outline" onClick={() => reload()} disabled={loading}>
            {TEXT.reload}
          </Button>
        }
      >
        <FilterInput
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={TEXT.searchPlaceholder}
          aria-label={TEXT.searchPlaceholder}
        />
      </FilterBar>

      <DataTable
        columns={columns}
        rows={filtered}
        rowKey="id"
        loading={loading}
        error={error}
        onRetry={() => reload()}
        mobile="scroll"
        minWidthClass="min-w-[880px]"
        emptyTitle={searching ? TEXT.noResults : TEXT.emptyTitle}
        emptyDescription={searching ? TEXT.noResultsHint : undefined}
      />

      <LabFormModal open={formOpen} lab={editing} onClose={closeForm} onSubmit={handleSubmit} />

      <ConfirmDialog
        open={target !== null}
        onClose={closeDelete}
        onConfirm={confirmDelete}
        title={TEXT.deleteTitle}
        message={TEXT.deleteMessage}
        confirmLabel={TEXT.remove}
        cancelLabel={TEXT.cancel}
        tone="danger"
        loading={deleting}
      >
        {target ? <p className="font-semibold">{target.name}</p> : null}
        {deleteError ? (
          <p role="alert" className="mt-2 text-sm text-red-600">
            {deleteError}
          </p>
        ) : null}
      </ConfirmDialog>
    </div>
  );
}
