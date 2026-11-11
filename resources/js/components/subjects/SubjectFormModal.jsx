import { useEffect, useRef, useState } from 'react';
import { Button, Input, Modal, Select } from '../ui/index.js';
import { getErrorMessage, getFieldErrors } from '../../lib/api.js';

// Texts (Persian as unicode escapes, English meaning beside each).
const TEXT = {
  addTitle: '\u0627\u0641\u0632\u0648\u062F\u0646 \u0645\u0636\u0645\u0648\u0646', // Add subject
  editTitle: '\u0648\u06CC\u0631\u0627\u06CC\u0634 \u0645\u0636\u0645\u0648\u0646', // Edit subject
  nameLabel: '\u0646\u0627\u0645 \u0645\u0636\u0645\u0648\u0646', // Subject name
  codeLabel: '\u06A9\u062F \u0645\u0636\u0645\u0648\u0646', // Subject code
  facultyLabel: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Faculty
  noFaculty: '\u0628\u062F\u0648\u0646 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // No faculty
  activeLabel: '\u0641\u0639\u0627\u0644', // Active
  nameRequired: '\u0646\u0627\u0645 \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // Name is required.
  codeRequired: '\u06A9\u062F \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // Code is required.
  save: '\u0630\u062E\u06CC\u0631\u0647', // Save
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
};

// Create/Edit modal. onSubmit(payload) must throw on failure; this modal shows 422 field errors.
// Frontend checks are only convenience: the Backend validation is the source of truth.
// canPickFaculty=false (no faculties.view) locks the faculty select, so the list is not needed.
export default function SubjectFormModal({ open, subject, faculties, canPickFaculty, onClose, onSubmit }) {
  const isEdit = Boolean(subject);
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [facultyId, setFacultyId] = useState(''); // '' = no faculty
  const [isActive, setIsActive] = useState(true);
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

  // Resets the form each time the modal opens (or switches to another subject).
  useEffect(() => {
    if (!open) return;
    setName(subject ? subject.name ?? '' : '');
    setCode(subject ? subject.code ?? '' : '');
    setFacultyId(subject && subject.faculty_id != null ? String(subject.faculty_id) : '');
    setIsActive(subject ? Boolean(subject.is_active) : true);
    setFieldErrors({});
    setFormError('');
    setSubmitting(false);
  }, [open, subject]);

  // Closing is blocked while a request is in flight.
  function handleClose() {
    if (!submitting) onClose();
  }

  // Validates, builds the payload (edit sends only changed fields) and submits.
  async function handleSave() {
    if (submitting) return;
    const nextName = name.trim();
    const nextCode = code.trim();

    // Convenience validation (required only; max/unique/exists are enforced by the Backend).
    const errors = {};
    if (!nextName) errors.name = TEXT.nameRequired;
    if (!nextCode) errors.code = TEXT.codeRequired;
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    const nextFaculty = facultyId === '' ? null : Number(facultyId);

    // Payload: full on create, changed fields only on edit.
    let payload;
    if (isEdit) {
      payload = {};
      if (nextName !== subject.name) payload.name = nextName;
      if (nextCode !== subject.code) payload.code = nextCode;
      if (nextFaculty !== (subject.faculty_id ?? null)) payload.faculty_id = nextFaculty;
      if (isActive !== Boolean(subject.is_active)) payload.is_active = isActive;
      if (Object.keys(payload).length === 0) {
        onClose(); // Nothing changed: no request.
        return;
      }
    } else {
      payload = { name: nextName, code: nextCode, faculty_id: nextFaculty, is_active: isActive };
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

  // Faculty options; the subject's current faculty is kept visible even if it is not in the list.
  const options = Array.isArray(faculties) ? faculties : [];
  const currentMissing = facultyId !== '' && !options.some((f) => String(f.id) === facultyId);

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
          <Select
            label={TEXT.facultyLabel}
            value={facultyId}
            onChange={(e) => setFacultyId(e.target.value)}
            error={fieldErrors.faculty_id}
            disabled={!canPickFaculty}
          >
            <option value="">{TEXT.noFaculty}</option>
            {currentMissing ? (
              <option value={facultyId}>{subject && subject.faculty ? subject.faculty.name : facultyId}</option>
            ) : null}
            {options.map((f) => (
              <option key={f.id} value={String(f.id)}>
                {f.name}
              </option>
            ))}
          </Select>
          <div>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
              <span>{TEXT.activeLabel}</span>
            </label>
            {fieldErrors.is_active ? (
              <p role="alert" className="mt-1 text-sm text-red-600">
                {fieldErrors.is_active}
              </p>
            ) : null}
          </div>
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