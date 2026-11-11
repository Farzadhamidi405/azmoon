/**
 * ComputersPage - admin list of computers (server-side pagination, search and filters).
 * Modeled on LabsPage: modals for create/edit and status change, a confirm dialog for delete.
 * Permission flags only hide buttons; the Backend remains the source of truth.
 */
import { useState } from 'react';
import { Badge, Button, ConfirmDialog, DataTable, FilterBar, FilterInput, FilterSelect, PageHeader, Pagination } from '../../components/ui/index.js';
import ComputerFormModal from '../../components/computers/ComputerFormModal.jsx';
import ComputerStatusModal from '../../components/computers/ComputerStatusModal.jsx';
import { useComputers } from '../../hooks/useComputers.js';
import { useLabs } from '../../hooks/useLabs.js';
import { useAuth } from '../../hooks/useAuth.js';
import { getErrorMessage } from '../../lib/api.js';
import { notifySuccess } from '../../lib/notify.js';

// UI texts (Persian, written as unicode escapes).
const TEXT = {
  pageTitle: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631\u0647\u0627', // Computers
  add: '\u0627\u0641\u0632\u0648\u062F\u0646 \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Add computer
  search: '\u062C\u0633\u062A\u062C\u0648', // Search
  searchHint: '\u062C\u0633\u062A\u062C\u0648 (\u06A9\u062F\u060C hostname\u060C IP)', // Search (code, hostname, IP)
  reload: '\u0628\u0627\u0631\u06AF\u0630\u0627\u0631\u06CC \u0645\u062C\u062F\u062F', // Reload
  filters: '\u0641\u06CC\u0644\u062A\u0631 \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631\u0647\u0627', // Computers filters
  allLabs: '\u0647\u0645\u0647 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631\u0647\u0627', // All labs
  allStatuses: '\u0647\u0645\u0647 \u0648\u0636\u0639\u06CC\u062A\u200C\u0647\u0627', // All statuses
  colCode: '\u06A9\u062F \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Computer code
  colLab: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631', // Lab
  colSoftware: '\u0645\u0634\u062E\u0635\u0627\u062A \u0646\u0631\u0645\u200C\u0627\u0641\u0632\u0627\u0631\u06CC', // Software details
  colStatus: '\u0648\u0636\u0639\u06CC\u062A', // Status
  colActions: '\u0639\u0645\u0644\u06CC\u0627\u062A', // Actions
  edit: '\u0648\u06CC\u0631\u0627\u06CC\u0634', // Edit
  changeStatus: '\u062A\u063A\u06CC\u06CC\u0631 \u0648\u0636\u0639\u06CC\u062A', // Change status
  remove: '\u062D\u0630\u0641', // Delete
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
  stOnline: '\u0622\u0646\u0644\u0627\u06CC\u0646', // Online
  stOffline: '\u0622\u0641\u0644\u0627\u06CC\u0646', // Offline
  stExam: '\u062F\u0631 \u0627\u0645\u062A\u062D\u0627\u0646', // In exam
  stMaintenance: '\u062F\u0631 \u062A\u0639\u0645\u06CC\u0631', // Under maintenance
  stLocked: '\u0642\u0641\u0644', // Locked
  empty: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631\u06CC \u062B\u0628\u062A \u0646\u0634\u062F\u0647 \u0627\u0633\u062A', // No computers registered
  noResults: '\u0646\u062A\u06CC\u062C\u0647\u200C\u0627\u06CC \u06CC\u0627\u0641\u062A \u0646\u0634\u062F', // No results found
  deleteTitle: '\u062D\u0630\u0641 \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Delete computer
  deleteAskPrefix: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631 \u00AB', // Computer <<
  deleteAskSuffix: '\u00BB \u062D\u0630\u0641 \u0634\u0648\u062F\u061F', // >> will be deleted?
  deleteFailed: '\u062D\u0630\u0641 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F', // Delete failed
  created: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631 \u0627\u0636\u0627\u0641\u0647 \u0634\u062F', // Computer added
  updated: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631 \u0648\u06CC\u0631\u0627\u06CC\u0634 \u0634\u062F', // Computer updated
  statusChanged: '\u0648\u0636\u0639\u06CC\u062A \u062A\u063A\u06CC\u06CC\u0631 \u06A9\u0631\u062F', // Status changed
  deleted: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631 \u062D\u0630\u0641 \u0634\u062F', // Computer deleted
  noLabs: '\u0627\u0628\u062A\u062F\u0627 \u06CC\u06A9 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631 \u062B\u0628\u062A \u06A9\u0646\u06CC\u062F', // Register a lab first
};

// Status -> label and Badge variant.
const STATUS_META = {
  online: { label: TEXT.stOnline, variant: 'on' },
  offline: { label: TEXT.stOffline, variant: 'off' },
  exam: { label: TEXT.stExam, variant: 'pending' },
  maintenance: { label: TEXT.stMaintenance, variant: 'bad' },
  locked: { label: TEXT.stLocked, variant: 'bad' },
};

// Status filter order.
const STATUS_ORDER = ['online', 'offline', 'exam', 'maintenance', 'locked'];

/** Picks the row object from the arguments DataTable passes to a column render function. */
function rowOf(args) {
  return (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
}

/** Left-to-right value (IP, MAC, host name) inside the RTL layout; empty -> null (the table shows a dash). */
function ltr(value) {
  if (value === null || value === undefined || value === '') return null;
  return <span dir="ltr">{String(value)}</span>;
}

/** Software details cell: OS, browser and agent version on separate lines; none -> null. */
function softwareCell(row) {
  const parts = [];
  if (row.os) parts.push(['OS', row.os]);
  if (row.browser) parts.push(['Browser', row.browser]);
  if (row.agent_version) parts.push(['Agent', row.agent_version]);
  if (parts.length === 0) return null;
  return (
    <span className="block text-xs text-ink-soft" dir="ltr">
      {parts.map((p) => (
        <span key={p[0]} className="block">
          {`${p[0]}: ${p[1]}`}
        </span>
      ))}
    </span>
  );
}

export default function ComputersPage() {
  // Permission flags (hide buttons only).
  const { can } = useAuth();
  const canCreate = can('labs.create');
  const canUpdate = can('labs.update');
  const canDelete = can('labs.delete');

  // Computers (paginated) and labs (for the filter and the form).
  const { items, pagination, query, loading, error, setFilters, setPage, reload, create, update, updateStatus, remove } = useComputers();
  const labsState = useLabs();
  const labs = labsState.items;

  // Search box text (applied on submit), modal targets and delete state.
  const [searchText, setSearchText] = useState('');
  const [form, setForm] = useState(null);
  const [statusTarget, setStatusTarget] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleteError, setDeleteError] = useState(null);
  const [deleting, setDeleting] = useState(false);

  // Derived flags.
  const noLabs = !labsState.loading && !labsState.error && labs.length === 0;
  const addDisabled = labsState.loading || labs.length === 0;
  const filtersActive = !!(query.labId || query.status || query.search);

  /** Applies the search text as a server-side filter. */
  const applySearch = () => {
    setFilters({ search: searchText.trim() });
  };

  /** Create or update, then notify (errors propagate to the modal). */
  const handleSubmitForm = async (payload, computer) => {
    if (computer) await update(computer.id, payload);
    else await create(payload);
    notifySuccess(computer ? TEXT.updated : TEXT.created);
  };

  /** Status change, then notify (errors propagate to the modal). */
  const handleSubmitStatus = async (status, computer) => {
    await updateStatus(computer.id, status);
    notifySuccess(TEXT.statusChanged);
  };

  /** Opens the delete confirmation. */
  const openDelete = (row) => {
    setDeleteError(null);
    setDeleteTarget(row);
  };

  /** Closes the delete confirmation unless a request is running. */
  const closeDelete = () => {
    if (deleting) return;
    setDeleteTarget(null);
    setDeleteError(null);
  };

  /** Deletes the selected computer; Backend messages stay inside the dialog, 404 closes and refreshes. */
  const confirmDelete = async () => {
    if (!deleteTarget || deleting) return;
    setDeleting(true);
    setDeleteError(null);
    try {
      await remove(deleteTarget.id);
      notifySuccess(TEXT.deleted);
      setDeleteTarget(null);
    } catch (err) {
      if (err && err.status === 404) {
        setDeleteTarget(null);
        reload({ silent: true });
      } else {
        setDeleteError(getErrorMessage(err, TEXT.deleteFailed));
      }
    } finally {
      setDeleting(false);
    }
  };

  // Table columns (no hidden columns; the table scrolls horizontally on small screens).
  const columns = [
    {
      key: 'computer_code',
      header: TEXT.colCode,
      primary: true,
      render: (...args) => ltr(rowOf(args).computer_code),
    },
    {
      key: 'lab',
      header: TEXT.colLab,
      render: (...args) => {
        const row = rowOf(args);
        return row.lab ? row.lab.name : null;
      },
    },
    { key: 'hostname', header: 'hostname', render: (...args) => ltr(rowOf(args).hostname) },
    { key: 'ip_address', header: 'IP', render: (...args) => ltr(rowOf(args).ip_address) },
    { key: 'mac_address', header: 'MAC', render: (...args) => ltr(rowOf(args).mac_address) },
    { key: 'software', header: TEXT.colSoftware, render: (...args) => softwareCell(rowOf(args)) },
    {
      key: 'status',
      header: TEXT.colStatus,
      render: (...args) => {
        const row = rowOf(args);
        const meta = STATUS_META[row.status] || { label: String(row.status || ''), variant: 'off' };
        return (
          <Badge variant={meta.variant} dot>
            {meta.label}
          </Badge>
        );
      },
    },
  ];
  if (canUpdate || canDelete) {
    columns.push({
      key: 'actions',
      header: TEXT.colActions,
      actions: true,
      render: (...args) => {
        const row = rowOf(args);
        return (
          <div className="flex flex-wrap items-center gap-1.5">
            {canUpdate ? (
              <Button size="sm" variant="outline" onClick={() => setForm({ computer: row })}>
                {TEXT.edit}
              </Button>
            ) : null}
            {canUpdate && row.status !== 'exam' ? (
              <Button size="sm" variant="outline" onClick={() => setStatusTarget(row)}>
                {TEXT.changeStatus}
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

  // Header actions: add button (permission and labs required).
  const headerActions = canCreate ? (
    <Button variant="primary" onClick={() => setForm({ computer: null })} disabled={addDisabled}>
      {TEXT.add}
    </Button>
  ) : null;

  // Delete dialog body: question plus the Backend message (inline spans, valid inside any text container).
  const deleteBody = deleteTarget ? (
    <span className="block">
      {`${TEXT.deleteAskPrefix}${deleteTarget.computer_code}${TEXT.deleteAskSuffix}`}
      {deleteError ? (
        <span role="alert" className="mt-1.5 block text-[11.5px] text-red">
          {deleteError}
        </span>
      ) : null}
    </span>
  ) : null;

  return (
    <div className="flex flex-col gap-3">
      <PageHeader title={TEXT.pageTitle} actions={headerActions} />
      {noLabs ? <p className="m-0 text-xs text-ink-soft">{TEXT.noLabs}</p> : null}
      <FilterBar
        onSubmit={applySearch}
        label={TEXT.filters}
        actions={
          <>
            <Button variant="outline" onClick={applySearch}>
              {TEXT.search}
            </Button>
            <Button variant="outline" onClick={() => reload()}>
              {TEXT.reload}
            </Button>
          </>
        }
      >
        <FilterInput type="search" aria-label={TEXT.searchHint} placeholder={TEXT.searchHint} maxLength={100} value={searchText} onChange={(e) => setSearchText(e.target.value)} />
        <FilterSelect aria-label={TEXT.colLab} value={query.labId} onChange={(e) => setFilters({ labId: e.target.value })}>
          <option value="">{TEXT.allLabs}</option>
          {labs.map((l) => (
            <option key={l.id} value={String(l.id)}>
              {l.name}
            </option>
          ))}
        </FilterSelect>
        <FilterSelect aria-label={TEXT.colStatus} value={query.status} onChange={(e) => setFilters({ status: e.target.value })}>
          <option value="">{TEXT.allStatuses}</option>
          {STATUS_ORDER.map((s) => (
            <option key={s} value={s}>
              {STATUS_META[s].label}
            </option>
          ))}
        </FilterSelect>
      </FilterBar>
      <DataTable
        columns={columns}
        rows={items} rowKey={(row) => row.id}
        loading={loading}
        error={error}
        onRetry={() => reload()}
        emptyTitle={filtersActive ? TEXT.noResults : TEXT.empty}
        mobile="scroll"
        minWidthClass="min-w-[1000px]"
      />
      <Pagination pagination={pagination} onPageChange={setPage} disabled={loading} />
      {form ? (
        <ComputerFormModal
          key={form.computer ? form.computer.id : 'new'}
          computer={form.computer}
          labs={labs}
          onClose={() => setForm(null)}
          onSubmit={handleSubmitForm}
        />
      ) : null}
      {statusTarget ? (
        <ComputerStatusModal key={statusTarget.id} computer={statusTarget} onClose={() => setStatusTarget(null)} onSubmit={handleSubmitStatus} />
      ) : null}
      {deleteTarget ? (
        <ConfirmDialog open title={TEXT.deleteTitle} message={deleteBody} confirmLabel={TEXT.remove} cancelLabel={TEXT.cancel} onConfirm={confirmDelete} onClose={closeDelete} loading={deleting} />
      ) : null}
    </div>
  );
}