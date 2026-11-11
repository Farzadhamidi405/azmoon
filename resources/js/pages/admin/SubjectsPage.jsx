import { useMemo, useState } from 'react';
import { Badge, Button, ConfirmDialog, DataTable, FilterBar, FilterSelect, PageHeader } from '../../components/ui/index.js';
import SubjectFormModal from '../../components/subjects/SubjectFormModal.jsx';
import SubjectAccessModal from '../../components/subjects/SubjectAccessModal.jsx';
import useSubjects from '../../hooks/useSubjects.js';
import useFaculties from '../../hooks/useFaculties.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { getErrorMessage } from '../../lib/api.js';
import { notifySuccess } from '../../lib/notify.js';

// Texts (Persian as unicode escapes, English meaning beside each).
const TEXT = {
  title: '\u0645\u0636\u0627\u0645\u06CC\u0646', // Subjects
  crumbRoot: '\u067E\u0648\u0647\u0646\u062A\u0648\u0646 \u06A9\u0646\u062F\u0632', // Kunduz University
  crumbPage: '\u0627\u0637\u0644\u0627\u0639\u0627\u062A \u067E\u0627\u06CC\u0647', // Base information
  add: '\u0627\u0641\u0632\u0648\u062F\u0646 \u0645\u0636\u0645\u0648\u0646', // Add subject
  colName: '\u0646\u0627\u0645', // Name
  colCode: '\u06A9\u062F', // Code
  colFaculty: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Faculty
  colStatus: '\u0648\u0636\u0639\u06CC\u062A', // Status
  colActions: '\u0639\u0645\u0644\u06CC\u0627\u062A', // Actions
  active: '\u0641\u0639\u0627\u0644', // Active
  inactive: '\u063A\u06CC\u0631\u0641\u0639\u0627\u0644', // Inactive
  filterFaculty: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Faculty (filter label)
  filterStatus: '\u0648\u0636\u0639\u06CC\u062A', // Status (filter label)
  allFaculties: '\u0647\u0645\u0647 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647\u200C\u0647\u0627', // All faculties
  allStatuses: '\u0647\u0645\u0647 \u0648\u0636\u0639\u06CC\u062A\u200C\u0647\u0627', // All statuses
  edit: '\u0648\u06CC\u0631\u0627\u06CC\u0634', // Edit
  remove: '\u062D\u0630\u0641', // Delete
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
  access: '\u062F\u0633\u062A\u0631\u0633\u06CC', // Access (opens the access modal)
  emptyTitle: '\u0647\u06CC\u0686 \u0645\u0636\u0645\u0648\u0646\u06CC \u062B\u0628\u062A \u0646\u0634\u062F\u0647 \u0627\u0633\u062A.', // No subject is registered.
  emptyFiltered: '\u0645\u0648\u0631\u062F\u06CC \u06CC\u0627\u0641\u062A \u0646\u0634\u062F.', // Nothing found.
  deleteTitle: '\u062D\u0630\u0641 \u0645\u0636\u0645\u0648\u0646', // Delete subject
  deleteMessage: '\u0622\u06CC\u0627 \u0627\u0632 \u062D\u0630\u0641 \u0627\u06CC\u0646 \u0645\u0636\u0645\u0648\u0646 \u0645\u0637\u0645\u0626\u0646 \u0647\u0633\u062A\u06CC\u062F\u061F', // Are you sure you want to delete this subject?
  created: '\u0645\u0636\u0645\u0648\u0646 \u0627\u06CC\u062C\u0627\u062F \u0634\u062F.', // Subject created.
  updated: '\u0645\u0636\u0645\u0648\u0646 \u0628\u0647\u200C\u0631\u0648\u0632 \u0634\u062F.', // Subject updated.
  deleted: '\u0645\u0636\u0645\u0648\u0646 \u062D\u0630\u0641 \u0634\u062F.', // Subject deleted.
};

// Subjects management page. Buttons follow permissions; the Backend decides in the end.
// Non-admin roles only receive the subjects assigned to them: that filtering is done by the Backend.
export default function SubjectsPage() {
  const { can } = useAuth();
  const canCreate = can('subjects.create');
  const canUpdate = can('subjects.update');
  const canDelete = can('subjects.delete');
  const canViewFaculties = can('faculties.view');
  const canAssignAccess = can('subjects.assign_access');
  const canListUsers = can('users.view'); // lets the access modal load the users list

  const [facultyFilter, setFacultyFilter] = useState(''); // '' = all faculties
  const [statusFilter, setStatusFilter] = useState(''); // '' = all, '1' = active, '0' = inactive

  const { items, loading, error, reload, create, update, remove } = useSubjects({
    facultyId: facultyFilter,
    status: statusFilter,
  });
  // Faculties feed the filter and the form; without faculties.view no request is sent.
  const faculties = useFaculties({ enabled: canViewFaculties });

  const [formOpen, setFormOpen] = useState(false); // create/edit modal visibility
  const [editing, setEditing] = useState(null); // subject being edited (null = create)
  const [toDelete, setToDelete] = useState(null); // subject awaiting delete confirmation
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');
  const [accessOpen, setAccessOpen] = useState(false); // access modal visibility
  const [accessSubject, setAccessSubject] = useState(null); // subject whose access is managed

  // Opens the modal in create mode.
  function openCreate() {
    setEditing(null);
    setFormOpen(true);
  }

  // Opens the modal in edit mode for a row.
  function openEdit(row) {
    setEditing(row);
    setFormOpen(true);
  }

  // Sends create or update depending on the mode; failures are thrown to the modal.
  async function handleSubmit(payload) {
    if (editing) {
      await update(editing.id, payload);
      notifySuccess(TEXT.updated);
    } else {
      await create(payload);
      notifySuccess(TEXT.created);
    }
  }

  // Opens the delete confirmation for a row.
  function askDelete(row) {
    setDeleteError('');
    setToDelete(row);
  }

  // Closes the confirmation unless a delete is running.
  function closeDelete() {
    if (!deleting) setToDelete(null);
  }

  // Deletes the row. A 422 (exams/questions/passages exist) is shown inside the dialog.
  async function confirmDelete() {
    if (!toDelete || deleting) return;
    setDeleting(true);
    setDeleteError('');
    try {
      await remove(toDelete.id);
      setToDelete(null);
      notifySuccess(TEXT.deleted);
    } catch (err) {
      if (err && err.status === 404) {
        setToDelete(null); // Already gone: just refresh the list.
        reload({ silent: true });
      } else {
        setDeleteError(getErrorMessage(err));
      }
    } finally {
      setDeleting(false);
    }
  }

  // Opens the access modal for a row.
  function openAccess(row) {
    setAccessSubject(row);
    setAccessOpen(true);
  }

  // Table columns; the actions column exists only when the user can edit, delete or manage access.
  const columns = useMemo(() => {
    const cols = [
      { key: 'name', header: TEXT.colName, primary: true },
      {
        key: 'code',
        header: TEXT.colCode,
        render: (row) => <span dir="ltr">{row.code}</span>,
      },
      {
        key: 'faculty',
        header: TEXT.colFaculty,
        render: (row) => (row.faculty ? row.faculty.name : null), // null shows a dash
      },
      {
        key: 'is_active',
        header: TEXT.colStatus,
        render: (row) => (
          <Badge variant={row.is_active ? 'on' : 'off'}>{row.is_active ? TEXT.active : TEXT.inactive}</Badge>
        ),
      },
    ];
    if (canUpdate || canDelete || canAssignAccess) {
      cols.push({
        key: 'actions',
        header: TEXT.colActions,
        actions: true,
        render: (row) => (
          <div className="flex items-center gap-2">
            {canUpdate ? (
              <Button size="sm" variant="outline" onClick={() => openEdit(row)}>
                {TEXT.edit}
              </Button>
            ) : null}
            {canAssignAccess ? (
              <Button size="sm" variant="outline" onClick={() => openAccess(row)}>
                {TEXT.access}
              </Button>
            ) : null}
            {canDelete ? (
              <Button size="sm" variant="danger" onClick={() => askDelete(row)}>
                {TEXT.remove}
              </Button>
            ) : null}
          </div>
        ),
      });
    }
    return cols;
    // openEdit/askDelete/openAccess only call state setters, so they are safe to omit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canUpdate, canDelete, canAssignAccess]);

  const filtered = facultyFilter !== '' || statusFilter !== '';

  return (
    <>
      <PageHeader
        title={TEXT.title}
        crumb={[TEXT.crumbRoot, TEXT.crumbPage]}
        actions={canCreate ? <Button onClick={openCreate}>{TEXT.add}</Button> : null}
      />

      <FilterBar label={TEXT.title} className="mb-4">
        {canViewFaculties ? (
          <FilterSelect
            aria-label={TEXT.filterFaculty}
            value={facultyFilter}
            onChange={(e) => setFacultyFilter(e.target.value)}
          >
            <option value="">{TEXT.allFaculties}</option>
            {faculties.items.map((f) => (
              <option key={f.id} value={String(f.id)}>
                {f.name}
              </option>
            ))}
          </FilterSelect>
        ) : null}
        <FilterSelect
          aria-label={TEXT.filterStatus}
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
        >
          <option value="">{TEXT.allStatuses}</option>
          <option value="1">{TEXT.active}</option>
          <option value="0">{TEXT.inactive}</option>
        </FilterSelect>
      </FilterBar>

      <DataTable
        columns={columns}
        rows={items}
        rowKey="id"
        loading={loading}
        error={error}
        onRetry={reload}
        emptyTitle={filtered ? TEXT.emptyFiltered : TEXT.emptyTitle}
      />

      <SubjectFormModal
        open={formOpen}
        subject={editing}
        faculties={faculties.items}
        canPickFaculty={canViewFaculties}
        onClose={() => setFormOpen(false)}
        onSubmit={handleSubmit}
      />

      <SubjectAccessModal
        open={accessOpen}
        subject={accessSubject}
        canAssign={canAssignAccess}
        canListUsers={canListUsers}
        onClose={() => setAccessOpen(false)}
      />

      <ConfirmDialog
        open={Boolean(toDelete)}
        onClose={closeDelete}
        onConfirm={confirmDelete}
        title={TEXT.deleteTitle}
        message={TEXT.deleteMessage}
        confirmLabel={TEXT.remove}
        cancelLabel={TEXT.cancel}
        tone="danger"
        loading={deleting}
      >
        {toDelete ? <p className="font-semibold">{toDelete.name}</p> : null}
        {deleteError ? (
          <p role="alert" className="mt-2 text-sm text-red-600">
            {deleteError}
          </p>
        ) : null}
      </ConfirmDialog>
    </>
  );
}