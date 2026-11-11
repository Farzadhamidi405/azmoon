import { useMemo, useState } from 'react';
import { Button, ConfirmDialog, DataTable, PageHeader } from '../../components/ui/index.js';
import FacultyFormModal from '../../components/faculties/FacultyFormModal.jsx';
import useFaculties from '../../hooks/useFaculties.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { getErrorMessage } from '../../lib/api.js';
import { notifySuccess } from '../../lib/notify.js';

// Texts (Persian as unicode escapes, English meaning beside each).
const TEXT = {
  title: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647\u200C\u0647\u0627', // Faculties
  crumbRoot: '\u067E\u0648\u0647\u0646\u062A\u0648\u0646 \u06A9\u0646\u062F\u0632', // Kunduz University
  crumbPage: '\u0627\u0637\u0644\u0627\u0639\u0627\u062A \u067E\u0627\u06CC\u0647', // Base information
  add: '\u0627\u0641\u0632\u0648\u062F\u0646 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Add faculty
  colName: '\u0646\u0627\u0645', // Name
  colCode: '\u06A9\u062F', // Code
  colSubjects: '\u062A\u0639\u062F\u0627\u062F \u0645\u0636\u0627\u0645\u06CC\u0646', // Subjects count
  colActions: '\u0639\u0645\u0644\u06CC\u0627\u062A', // Actions
  edit: '\u0648\u06CC\u0631\u0627\u06CC\u0634', // Edit
  remove: '\u062D\u0630\u0641', // Delete
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
  emptyTitle: '\u0647\u06CC\u0686 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647\u200C\u0627\u06CC \u062B\u0628\u062A \u0646\u0634\u062F\u0647 \u0627\u0633\u062A.', // No faculty is registered.
  deleteTitle: '\u062D\u0630\u0641 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Delete faculty
  deleteMessage: '\u0622\u06CC\u0627 \u0627\u0632 \u062D\u0630\u0641 \u0627\u06CC\u0646 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647 \u0645\u0637\u0645\u0626\u0646 \u0647\u0633\u062A\u06CC\u062F\u061F', // Are you sure you want to delete this faculty?
  created: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647 \u0627\u06CC\u062C\u0627\u062F \u0634\u062F.', // Faculty created.
  updated: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647 \u0628\u0647\u200C\u0631\u0648\u0632 \u0634\u062F.', // Faculty updated.
  deleted: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647 \u062D\u0630\u0641 \u0634\u062F.', // Faculty deleted.
};

// Finds the row object among the arguments DataTable passes to a column renderer.
function pickRow(args) {
  return args.find((a) => a && typeof a === 'object' && !Array.isArray(a) && 'id' in a) || {};
}

// Faculties management page. Buttons follow permissions; the Backend decides in the end.
export default function FacultiesPage() {
  const { can } = useAuth();
  const { items, loading, error, reload, create, update, remove } = useFaculties();
  const canCreate = can('faculties.create');
  const canUpdate = can('faculties.update');
  const canDelete = can('faculties.delete');

  const [formOpen, setFormOpen] = useState(false); // create/edit modal visibility
  const [editing, setEditing] = useState(null); // faculty being edited (null = create)
  const [toDelete, setToDelete] = useState(null); // faculty awaiting delete confirmation
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');

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

  // Deletes the row. A 422 (faculty has subjects) is shown inside the dialog.
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

  // Table columns; the actions column exists only when the user can edit or delete.
  const columns = useMemo(() => {
    const cols = [
      { key: 'name', header: TEXT.colName, primary: true },
      {
        key: 'code',
        header: TEXT.colCode,
        render: (...args) => <span dir="ltr">{pickRow(args).code}</span>,
      },
      {
        key: 'subjects_count',
        header: TEXT.colSubjects,
        render: (...args) => Number(pickRow(args).subjects_count ?? 0),
      },
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
                <Button size="sm" variant="outline" onClick={() => openEdit(row)}>
                  {TEXT.edit}
                </Button>
              ) : null}
              {canDelete ? (
                <Button size="sm" variant="danger" onClick={() => askDelete(row)}>
                  {TEXT.remove}
                </Button>
              ) : null}
            </div>
          );
        },
      });
    }
    return cols;
    // openEdit/askDelete only call state setters, so they are safe to omit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canUpdate, canDelete]);

  return (
    <>
      <PageHeader
        title={TEXT.title}
        crumb={[TEXT.crumbRoot, TEXT.crumbPage]}
        actions={canCreate ? <Button onClick={openCreate}>{TEXT.add}</Button> : null}
      />

      <DataTable
        columns={columns}
        rows={items}
        rowKey="id"
        loading={loading}
        error={error}
        onRetry={reload}
        emptyTitle={TEXT.emptyTitle}
      />

      <FacultyFormModal
        open={formOpen}
        faculty={editing}
        onClose={() => setFormOpen(false)}
        onSubmit={handleSubmit}
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