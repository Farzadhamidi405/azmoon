import { useEffect, useMemo, useRef, useState } from 'react';
import { Button, DataTable, Modal, Select } from '../ui/index.js';
import { UI_TEXT } from '../ui/uiText.js';
import useSubjectAccess from '../../hooks/useSubjectAccess.js';
import { getErrorMessage, getFieldErrors } from '../../lib/api.js';
import { notifySuccess } from '../../lib/notify.js';
import { formatDateTime } from '../../lib/formatDate.js';

// Texts (Persian as unicode escapes, English meaning beside each).
const TEXT = {
  title: '\u062F\u0633\u062A\u0631\u0633\u06CC \u0645\u0636\u0645\u0648\u0646', // Subject access
  colName: '\u0646\u0627\u0645', // Name
  colUsername: '\u0646\u0627\u0645 \u06A9\u0627\u0631\u0628\u0631\u06CC', // Username
  colGrantedBy: '\u062B\u0628\u062A\u200C\u06A9\u0646\u0646\u062F\u0647', // Granted by
  colDate: '\u062A\u0627\u0631\u06CC\u062E', // Date
  colActions: '\u0639\u0645\u0644\u06CC\u0627\u062A', // Actions
  revoke: '\u0644\u063A\u0648 \u062F\u0633\u062A\u0631\u0633\u06CC', // Revoke access
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
  empty: '\u0647\u06CC\u0686 \u06A9\u0627\u0631\u0628\u0631\u06CC \u0628\u0647 \u0627\u06CC\u0646 \u0645\u0636\u0645\u0648\u0646 \u062F\u0633\u062A\u0631\u0633\u06CC \u0646\u062F\u0627\u0631\u062F.', // No user has access to this subject.
  userLabel: '\u06A9\u0627\u0631\u0628\u0631', // User
  pickPlaceholder: '\u0627\u0646\u062A\u062E\u0627\u0628 \u06A9\u0627\u0631\u0628\u0631', // Select a user
  add: '\u0627\u0641\u0632\u0648\u062F\u0646 \u062F\u0633\u062A\u0631\u0633\u06CC', // Add access
  pickRequired: '\u06CC\u06A9 \u06A9\u0627\u0631\u0628\u0631 \u0631\u0627 \u0627\u0646\u062A\u062E\u0627\u0628 \u06A9\u0646\u06CC\u062F.', // Select a user.
  usersLoading: '\u062F\u0631 \u062D\u0627\u0644 \u0628\u0627\u0631\u06AF\u0630\u0627\u0631\u06CC \u06A9\u0627\u0631\u0628\u0631\u0627\u0646\u2026', // Loading users...
  noCandidates: '\u06A9\u0627\u0631\u0628\u0631 \u0641\u0639\u0627\u0644\u06CC \u0628\u0627 \u0646\u0642\u0634 Question Manager \u06CC\u0627 Reviewer \u06A9\u0647 \u0647\u0646\u0648\u0632 \u062F\u0633\u062A\u0631\u0633\u06CC \u0646\u062F\u0627\u0634\u062A\u0647 \u0628\u0627\u0634\u062F \u0648\u062C\u0648\u062F \u0646\u062F\u0627\u0631\u062F.', // No active Question Manager/Reviewer without access exists.
  noUsersView: '\u0628\u0631\u0627\u06CC \u0627\u0641\u0632\u0648\u062F\u0646 \u062F\u0633\u062A\u0631\u0633\u06CC\u060C \u0645\u062C\u0648\u0632 \u0645\u0634\u0627\u0647\u062F\u0647 \u06A9\u0627\u0631\u0628\u0631\u0627\u0646 \u0644\u0627\u0632\u0645 \u0627\u0633\u062A.', // Adding access needs the permission to view users.
  usersForbidden: '\u062F\u0633\u062A\u0631\u0633\u06CC \u0628\u0647 \u0641\u0647\u0631\u0633\u062A \u06A9\u0627\u0631\u0628\u0631\u0627\u0646 \u0628\u0631\u0627\u06CC \u0634\u0645\u0627 \u0645\u062C\u0627\u0632 \u0646\u06CC\u0633\u062A.', // Access to the users list is not allowed for you.
  revokeMessage: '\u0622\u06CC\u0627 \u0627\u0632 \u0644\u063A\u0648 \u062F\u0633\u062A\u0631\u0633\u06CC \u0627\u06CC\u0646 \u06A9\u0627\u0631\u0628\u0631 \u0628\u0647 \u0645\u0636\u0645\u0648\u0646 \u0645\u0637\u0645\u0626\u0646 \u0647\u0633\u062A\u06CC\u062F\u061F', // Are you sure you want to revoke this user's access to the subject?
  granted: '\u062F\u0633\u062A\u0631\u0633\u06CC \u0627\u0641\u0632\u0648\u062F\u0647 \u0634\u062F.', // Access added.
  revoked: '\u062F\u0633\u062A\u0631\u0633\u06CC \u0644\u063A\u0648 \u0634\u062F.', // Access revoked.
};

// Roles the Backend accepts for subject access (the Backend still validates in the end).
const ASSIGNABLE_ROLES = ['Question Manager', 'Reviewer'];

// Name of the user who granted the access; the relation may arrive as granted_by (object) or grantedBy.
function grantorName(row) {
  const g = row.grantedBy ?? row.granted_by;
  return g && typeof g === 'object' ? g.name ?? null : null; // null shows a dash
}

// True when a user may be offered in the add form: active, allowed role, no access yet.
function isAssignable(user, takenIds) {
  if (!user || user.is_active === false || user.is_active === 0) return false;
  const roleName = user.role && user.role.name;
  if (!ASSIGNABLE_ROLES.includes(roleName)) return false;
  return !takenIds.has(Number(user.id));
}

// Access modal for one subject: list, add (needs users.view) and revoke (inline confirmation panel).
// Buttons follow permissions (canAssign / canListUsers); the Backend decides in the end.
export default function SubjectAccessModal({ open, subject, canAssign, canListUsers, onClose }) {
  const subjectId = open && subject ? subject.id : null;
  const showForm = Boolean(canAssign && canListUsers);
  const access = useSubjectAccess(subjectId, { loadUsers: showForm });

  const [selectedId, setSelectedId] = useState(''); // '' = nothing selected
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState('');
  const [revokeTarget, setRevokeTarget] = useState(null); // record awaiting revoke confirmation
  const [revoking, setRevoking] = useState(false);
  const [revokeError, setRevokeError] = useState('');
  const aliveRef = useRef(true);

  // Unmount guard against state updates after the page is left.
  useEffect(() => {
    aliveRef.current = true;
    return () => {
      aliveRef.current = false;
    };
  }, []);

  // Resets local state each time the modal opens (or switches to another subject).
  useEffect(() => {
    if (!open) return;
    setSelectedId('');
    setAdding(false);
    setAddError('');
    setRevokeTarget(null);
    setRevoking(false);
    setRevokeError('');
  }, [open, subject]);

  const busy = adding || revoking;

  // Closing is blocked while a request is in flight.
  function handleClose() {
    if (!busy) onClose();
  }

  // Users that can still be added: filtered from the users list and the current access list.
  const candidates = useMemo(() => {
    const taken = new Set(access.items.map((r) => Number(r.user_id)));
    return access.users.filter((u) => isAssignable(u, taken));
  }, [access.items, access.users]);

  // Grants access to the selected user; Backend errors are shown inside the modal.
  async function handleAdd() {
    if (adding) return;
    if (selectedId === '') {
      setAddError(TEXT.pickRequired);
      return;
    }
    setAdding(true);
    setAddError('');
    try {
      await access.add(Number(selectedId));
      if (!aliveRef.current) return;
      setSelectedId('');
      notifySuccess(TEXT.granted);
    } catch (err) {
      if (!aliveRef.current) return;
      const fe = getFieldErrors(err);
      setAddError(fe.user_id || getErrorMessage(err));
      if (err && err.status === 422) access.reload({ silent: true }); // list may have changed elsewhere
    } finally {
      if (aliveRef.current) setAdding(false);
    }
  }

  // Opens the inline revoke confirmation for a row.
  function askRevoke(row) {
    setRevokeError('');
    setRevokeTarget(row);
  }

  // Leaves the confirmation panel unless a revoke is running.
  function cancelRevoke() {
    if (!revoking) setRevokeTarget(null);
  }

  // Revokes the access. A 404 means it is already gone, so the list is just refreshed.
  async function confirmRevoke() {
    if (!revokeTarget || revoking) return;
    setRevoking(true);
    setRevokeError('');
    try {
      await access.revoke(revokeTarget.id);
      if (!aliveRef.current) return;
      setRevokeTarget(null);
      notifySuccess(TEXT.revoked);
    } catch (err) {
      if (!aliveRef.current) return;
      if (err && err.status === 404) {
        setRevokeTarget(null);
        access.reload({ silent: true });
      } else {
        setRevokeError(getErrorMessage(err));
      }
    } finally {
      if (aliveRef.current) setRevoking(false);
    }
  }

  // Table columns; the actions column exists only for users who may assign access.
  const columns = [
    { key: 'name', header: TEXT.colName, primary: true, render: (row) => (row.user ? row.user.name : null) },
    {
      key: 'username',
      header: TEXT.colUsername,
      render: (row) => (row.user ? <span dir="ltr">{row.user.username}</span> : null),
    },
    { key: 'granted_by', header: TEXT.colGrantedBy, render: (row) => grantorName(row) },
    {
      key: 'granted_at',
      header: TEXT.colDate,
      render: (row) => (row.granted_at ? formatDateTime(row.granted_at) : null),
    },
  ];
  if (canAssign) {
    columns.push({
      key: 'actions',
      header: TEXT.colActions,
      actions: true,
      render: (row) => (
        <Button size="sm" variant="danger" onClick={() => askRevoke(row)}>
          {TEXT.revoke}
        </Button>
      ),
    });
  }

  // Add section: a note without users.view; otherwise the user picker with loading/error/empty states.
  let addSection = null;
  if (canAssign && !canListUsers) {
    addSection = <p className="text-sm text-gray-600">{TEXT.noUsersView}</p>;
  } else if (showForm) {
    if (access.usersLoading) {
      addSection = <p className="text-sm text-gray-600">{TEXT.usersLoading}</p>;
    } else if (access.usersError) {
      const forbidden = access.usersError.status === 403;
      addSection = (
        <div className="space-y-2">
          <p role="alert" className="text-sm text-red-600">
            {forbidden ? TEXT.usersForbidden : access.usersError.message}
          </p>
          {forbidden ? null : (
            <Button size="sm" variant="outline" onClick={() => access.reloadUsers()}>
              {UI_TEXT.retry}
            </Button>
          )}
        </div>
      );
    } else if (candidates.length === 0) {
      addSection = <p className="text-sm text-gray-600">{TEXT.noCandidates}</p>;
    } else {
      addSection = (
        <div className="space-y-3">
          <Select
            label={TEXT.userLabel}
            value={selectedId}
            onChange={(e) => {
              setSelectedId(e.target.value);
              setAddError('');
            }}
            error={addError}
            disabled={adding}
          >
            <option value="">{TEXT.pickPlaceholder}</option>
            {candidates.map((u) => (
              <option key={u.id} value={String(u.id)}>
                {u.name + ' (' + u.username + ')'}
              </option>
            ))}
          </Select>
          <Button onClick={handleAdd} loading={adding}>
            {TEXT.add}
          </Button>
        </div>
      );
    }
  }

  // Footer follows the mode: confirmation buttons while revoking, otherwise a single close button.
  const footer = revokeTarget ? (
    <>
      <Button variant="outline" onClick={cancelRevoke} disabled={revoking}>
        {TEXT.cancel}
      </Button>
      <Button variant="danger" onClick={confirmRevoke} loading={revoking}>
        {TEXT.revoke}
      </Button>
    </>
  ) : (
    <Button variant="outline" onClick={handleClose} disabled={busy}>
      {UI_TEXT.close}
    </Button>
  );

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={TEXT.title}
      description={subject ? subject.name : undefined}
      size="lg"
      dismissible={!busy}
      footer={footer}
    >
      {revokeTarget ? (
        <div className="space-y-3">
          <p>{TEXT.revokeMessage}</p>
          <p className="font-semibold">{revokeTarget.user ? revokeTarget.user.name : ''}</p>
          {revokeError ? (
            <p role="alert" className="text-sm text-red-600">
              {revokeError}
            </p>
          ) : null}
        </div>
      ) : (
        <div className="space-y-5">
          <DataTable
            columns={columns}
            rows={access.items}
            rowKey="id"
            loading={access.loading}
            error={access.error}
            onRetry={() => access.reload()}
            emptyTitle={TEXT.empty}
          />
          {!access.loading && !access.error ? addSection : null}
        </div>
      )}
    </Modal>
  );
}