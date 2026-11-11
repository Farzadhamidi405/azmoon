import { useEffect, useRef, useState } from 'react';
import { Button, Input, Modal } from '../ui/index.js';
import { getErrorMessage, getFieldErrors } from '../../lib/api.js';

// Texts (Persian as unicode escapes, English meaning beside each).
const TEXT = {
  addTitle: '\u0627\u0641\u0632\u0648\u062F\u0646 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Add faculty
  editTitle: '\u0648\u06CC\u0631\u0627\u06CC\u0634 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Edit faculty
  nameLabel: '\u0646\u0627\u0645 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Faculty name
  codeLabel: '\u06A9\u062F \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Faculty code
  nameRequired: '\u0646\u0627\u0645 \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // Name is required.
  codeRequired: '\u06A9\u062F \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // Code is required.
  save: '\u0630\u062E\u06CC\u0631\u0647', // Save
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
};

// Create/Edit modal. onSubmit(payload) must throw on failure; this modal shows 422 field errors.
// Frontend checks are only convenience: the Backend validation is the source of truth.
export default function FacultyFormModal({ open, faculty, onClose, onSubmit }) {
  const isEdit = Boolean(faculty);
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const aliveRef = useRef(true);

  // Unmount guard against state updates after the page is left.
  useEffect(() => {
    aliveRef.current = true;
    return () => {
      aliveRef.current = false;
    };
  }, []);

  // Resets the form each time the modal opens (or switches between create and a given faculty).
  useEffect(() => {
    if (!open) return;
    setName(faculty ? faculty.name ?? '' : '');
    setCode(faculty ? faculty.code ?? '' : '');
    setFieldErrors({});
    setFormError('');
    setSubmitting(false);
  }, [open, faculty]);

  // Closing is blocked while a request is in flight.
  function handleClose() {
    if (!submitting) onClose();
  }

  // Validates, builds the payload (edit sends only changed fields) and submits.
  async function handleSave() {
    if (submitting) return;
    const nextName = name.trim();
    const nextCode = code.trim();

    // Convenience validation (required only; max/unique are enforced by the Backend).
    const errors = {};
    if (!nextName) errors.name = TEXT.nameRequired;
    if (!nextCode) errors.code = TEXT.codeRequired;
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    // Payload: full on create, changed fields only on edit.
    let payload;
    if (isEdit) {
      payload = {};
      if (nextName !== faculty.name) payload.name = nextName;
      if (nextCode !== faculty.code) payload.code = nextCode;
      if (Object.keys(payload).length === 0) {
        onClose(); // Nothing changed: no request.
        return;
      }
    } else {
      payload = { name: nextName, code: nextCode };
    }

    setSubmitting(true);
    setFormError('');
    setFieldErrors({});
    try {
      await onSubmit(payload);
      if (!aliveRef.current) return;
      setSubmitting(false);
      onClose();
    } catch (err) {
      if (!aliveRef.current) return;
      setSubmitting(false);
      // 422: show per-field messages (or the general message when there is no field map).
      const fe = getFieldErrors(err);
      if (Object.keys(fe).length > 0) setFieldErrors(fe);
      else if (err && err.status === 422) setFormError(getErrorMessage(err));
      // Other statuses (403, 5xx, network) already produced a global toast in lib/api.js.
    }
  }

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={isEdit ? TEXT.editTitle : TEXT.addTitle}
      dismissible={!submitting}
      footer={
        <>
          <Button variant="outline" onClick={handleClose} disabled={submitting}>
            {TEXT.cancel}
          </Button>
          <Button onClick={handleSave} loading={submitting}>
            {TEXT.save}
          </Button>
        </>
      }
    >
      {/* Enter key submits through a hidden submit button; the footer buttons use onClick. */}
      <form
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          handleSave();
        }}
      >
        <div className="space-y-4">
          <Input
            label={TEXT.nameLabel}
            value={name}
            onChange={(e) => setName(e.target.value)}
            error={fieldErrors.name}
            maxLength={255}
            required
            autoFocus
          />
          <Input
            label={TEXT.codeLabel}
            value={code}
            onChange={(e) => setCode(e.target.value)}
            error={fieldErrors.code}
            maxLength={50}
            dir="ltr"
            required
          />
          {formError ? (
            <p role="alert" className="text-sm text-red-600">
              {formError}
            </p>
          ) : null}
        </div>
        <button type="submit" className="hidden" tabIndex={-1} aria-hidden="true" />
      </form>
    </Modal>
  );
}