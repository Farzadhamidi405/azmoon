/**
 * ComputerStatusModal - changes the status of one computer (PATCH status endpoint).
 * `onSubmit(status, computer)` performs the request and throws on failure.
 * The exam status is system-controlled, so it is neither offered nor changeable here.
 */
import { useState } from 'react';
import { Button, Modal, Select } from '../ui/index.js';
import { getErrorMessage } from '../../lib/api.js';

// UI texts (Persian, written as unicode escapes).
const TEXT = {
  title: '\u062A\u063A\u06CC\u06CC\u0631 \u0648\u0636\u0639\u06CC\u062A \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Change computer status
  computer: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Computer
  newStatus: '\u0648\u0636\u0639\u06CC\u062A \u062C\u062F\u06CC\u062F', // New status
  online: '\u0622\u0646\u0644\u0627\u06CC\u0646', // Online
  offline: '\u0622\u0641\u0644\u0627\u06CC\u0646', // Offline
  maintenance: '\u062F\u0631 \u062A\u0639\u0645\u06CC\u0631', // Under maintenance
  locked: '\u0642\u0641\u0644', // Locked
  save: '\u0630\u062E\u06CC\u0631\u0647', // Save
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
  saveError: '\u0630\u062E\u06CC\u0631\u0647 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F', // Save failed
};

// Statuses the Backend accepts for a manual change.
const OPTIONS = [
  ['online', TEXT.online],
  ['offline', TEXT.offline],
  ['maintenance', TEXT.maintenance],
  ['locked', TEXT.locked],
];

export default function ComputerStatusModal({ computer, onClose, onSubmit }) {
  // Selected status (starts at the current one when it is changeable).
  const [status, setStatus] = useState(OPTIONS.some((o) => o[0] === computer.status) ? computer.status : 'offline');
  // Error message from the Backend and the saving flag.
  const [formError, setFormError] = useState(null);
  const [saving, setSaving] = useState(false);

  /** Closes the modal unless a request is running. */
  const handleClose = () => {
    if (!saving) onClose();
  };

  /** Submits the new status; an unchanged status just closes the modal. */
  const submit = async () => {
    if (saving) return;
    if (status === computer.status) {
      onClose();
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      await onSubmit(status, computer);
      onClose();
    } catch (err) {
      setFormError(getErrorMessage(err, TEXT.saveError));
    } finally {
      setSaving(false);
    }
  };

  /** Form submit (Enter key): same as pressing Save. */
  const handleFormSubmit = (event) => {
    event.preventDefault();
    submit();
  };

  const title = TEXT.title;

  // Footer buttons.
  const footer = (
    <>
      <Button variant="outline" onClick={handleClose} disabled={saving}>
        {TEXT.cancel}
      </Button>
      <Button variant="primary" onClick={submit} disabled={saving}>
        {TEXT.save}
      </Button>
    </>
  );

  // Form body; the hidden submit button lets Enter submit the form.
  const content = (
    <form onSubmit={handleFormSubmit} noValidate>
      <p className="m-0 mb-4 text-xs text-ink-soft">
        {`${TEXT.computer}: `}
        <span dir="ltr">{computer.computer_code}</span>
      </p>
      <Select id="computer-new-status" label={TEXT.newStatus} value={status} onChange={(e) => setStatus(e.target.value)} disabled={saving}>
        {OPTIONS.map((o) => (
          <option key={o[0]} value={o[0]}>
            {o[1]}
          </option>
        ))}
      </Select>
      {formError ? (
        <p role="alert" className="mt-1.5 text-[11.5px] text-red">
          {formError}
        </p>
      ) : null}
      <button type="submit" hidden aria-hidden="true" tabIndex={-1} />
    </form>
  );

  return (
    <Modal open title={title} onClose={handleClose} footer={footer}>{content}</Modal>
  );
}