/**
 * LabFormModal - create / edit form for a lab (name + location).
 * The parent supplies onSubmit(payload); it must throw on failure so errors show here.
 */
import { useEffect, useRef, useState } from 'react';
import { Button, Input, Modal } from '../ui/index.js';
import { getErrorMessage, getFieldErrors } from '../../lib/api.js';

// UI texts (Persian as unicode escapes).
const TEXT = {
  // Add lab
  createTitle: '\u0627\u0641\u0632\u0648\u062F\u0646 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631',
  // Edit lab
  editTitle: '\u0648\u06CC\u0631\u0627\u06CC\u0634 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631',
  // Lab name
  nameLabel: '\u0646\u0627\u0645 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631',
  // Location (optional)
  locationLabel: '\u0645\u0648\u0642\u0639\u06CC\u062A (\u0627\u062E\u062A\u06CC\u0627\u0631\u06CC)',
  // Save
  save: '\u0630\u062E\u06CC\u0631\u0647',
  // Cancel
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641',
  // Lab name is required
  nameRequired: '\u0646\u0627\u0645 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631 \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A',
  // Save failed
  saveFailed: '\u0630\u062E\u06CC\u0631\u0647 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F',
};

/** Returns the first message of a field error (string or array of strings). */
function firstMessage(value) {
  if (Array.isArray(value)) return value[0] || '';
  return value || '';
}

/**
 * Modal form for creating or editing a lab.
 * @param {{open: boolean, lab: (object|null), onClose: Function, onSubmit: Function}} props
 */
export default function LabFormModal({ open, lab, onClose, onSubmit }) {
  // Edit mode when a lab object is supplied.
  const isEdit = Boolean(lab);
  const [name, setName] = useState('');
  const [location, setLocation] = useState('');
  // Per-field server/validation errors, e.g. { name: '...' }.
  const [fieldErrors, setFieldErrors] = useState({});
  // Form-level error (422 without field map, or any other failure).
  const [formError, setFormError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  // False after unmount: prevents state updates on an unmounted component.
  const aliveRef = useRef(true);

  // Unmount guard.
  useEffect(() => {
    aliveRef.current = true;
    return () => {
      aliveRef.current = false;
    };
  }, []);

  // Reset the form each time it opens or the edited lab changes.
  useEffect(() => {
    if (!open) return;
    setName(lab ? lab.name || '' : '');
    setLocation(lab ? lab.location || '' : '');
    setFieldErrors({});
    setFormError('');
    setSubmitting(false);
  }, [open, lab]);

  /** Closing is blocked while a request is running. */
  const handleClose = () => {
    if (submitting) return;
    onClose();
  };

  /** Validates, builds the payload and submits. */
  const handleSave = async () => {
    if (submitting) return;
    const nextName = name.trim();
    const nextLocation = location.trim();

    // Convenience validation only (the backend remains the source of truth).
    if (!nextName) {
      setFieldErrors({ name: TEXT.nameRequired });
      setFormError('');
      return;
    }

    // Payload: full on create; only changed fields on edit.
    let payload;
    if (isEdit) {
      payload = {};
      if (nextName !== String(lab.name || '').trim()) payload.name = nextName;
      if (nextLocation !== String(lab.location || '').trim()) {
        // An emptied location is sent as null.
        payload.location = nextLocation === '' ? null : nextLocation;
      }
      // Nothing changed: close without a request.
      if (Object.keys(payload).length === 0) {
        onClose();
        return;
      }
    } else {
      payload = { name: nextName, location: nextLocation === '' ? null : nextLocation };
    }

    setSubmitting(true);
    setFieldErrors({});
    setFormError('');
    try {
      await onSubmit(payload);
    } catch (err) {
      if (!aliveRef.current) return;
      const fe = getFieldErrors(err);
      if (fe && Object.keys(fe).length > 0) {
        // 422 with a field map: show under the fields.
        setFieldErrors(fe);
      } else {
        // 422 without a field map, or any other failure: show inside the modal.
        setFormError(getErrorMessage(err, TEXT.saveFailed));
      }
    } finally {
      if (aliveRef.current) setSubmitting(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={isEdit ? TEXT.editTitle : TEXT.createTitle}
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
            error={firstMessage(fieldErrors.name) || undefined}
            maxLength={100}
            required
            autoFocus
            disabled={submitting}
          />
          <Input
            label={TEXT.locationLabel}
            value={location}
            onChange={(e) => setLocation(e.target.value)}
            error={firstMessage(fieldErrors.location) || undefined}
            maxLength={255}
            disabled={submitting}
          />
          {formError ? (
            <p role="alert" className="text-sm text-red-600">
              {formError}
            </p>
          ) : null}
        </div>
        {/* Hidden submit button so Enter submits the form */}
        <button type="submit" className="hidden" tabIndex={-1} aria-hidden="true" />
      </form>
    </Modal>
  );
}
