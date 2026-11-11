/**
 * ComputerFormModal - create / edit form for one computer.
 * `computer` is null for create. `onSubmit(payload, computer)` performs the request and throws on failure.
 * On edit only the changed fields are sent; nothing changed means the modal just closes.
 */
import { useState } from 'react';
import { Button, FieldRow, Input, Modal, Select } from '../ui/index.js';
import { getErrorMessage, getFieldErrors } from '../../lib/api.js';

// UI texts (Persian, written as unicode escapes).
const TEXT = {
  titleCreate: '\u0627\u0641\u0632\u0648\u062F\u0646 \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Add computer
  titleEdit: '\u0648\u06CC\u0631\u0627\u06CC\u0634 \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Edit computer
  lab: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631', // Lab
  pickLab: '\u0627\u0646\u062A\u062E\u0627\u0628 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631', // Choose a lab
  code: '\u06A9\u062F \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Computer code
  os: '\u0633\u06CC\u0633\u062A\u0645\u200C\u0639\u0627\u0645\u0644', // Operating system
  browser: '\u0645\u0631\u0648\u0631\u06AF\u0631', // Browser
  agent: '\u0646\u0633\u062E\u0647 Agent', // Agent version
  save: '\u0630\u062E\u06CC\u0631\u0647', // Save
  cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
  required: '\u0627\u06CC\u0646 \u0641\u06CC\u0644\u062F \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A', // This field is required
  saveError: '\u0630\u062E\u06CC\u0631\u0647 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F', // Save failed
};

// Text fields: payload key, state key, max length (equal to the Backend rules), required flag.
const FIELDS = [
  { key: 'computer_code', state: 'computerCode', max: 50, required: true },
  { key: 'ip_address', state: 'ipAddress', max: 45, required: true },
  { key: 'hostname', state: 'hostname', max: 100, required: false },
  { key: 'mac_address', state: 'macAddress', max: 17, required: false },
  { key: 'os', state: 'os', max: 100, required: false },
  { key: 'browser', state: 'browser', max: 100, required: false },
  { key: 'agent_version', state: 'agentVersion', max: 20, required: false },
];

/** Normalizes one value for the payload: trimmed text, or null for an empty optional field. */
function normalize(field, raw) {
  const text = String(raw === null || raw === undefined ? '' : raw).trim();
  if (text === '' && !field.required) return null;
  return text;
}

/** Builds the initial form values (empty for create). */
function initialValues(computer) {
  const hasLab = computer && computer.lab_id !== undefined && computer.lab_id !== null;
  const values = { labId: hasLab ? String(computer.lab_id) : '' };
  FIELDS.forEach((f) => {
    const raw = computer ? computer[f.key] : '';
    values[f.state] = raw === null || raw === undefined ? '' : String(raw);
  });
  return values;
}

/** Flattens field errors (string or array per field) into key -> first message. */
function flattenErrors(raw) {
  const out = {};
  if (!raw || typeof raw !== 'object') return out;
  Object.keys(raw).forEach((k) => {
    const v = raw[k];
    const msg = Array.isArray(v) ? v[0] : v;
    if (msg) out[k] = String(msg);
  });
  return out;
}

export default function ComputerFormModal({ computer, labs, onClose, onSubmit }) {
  // Edit mode when a computer is given.
  const isEdit = !!computer;
  // Form values, field errors, form-level error and the saving flag.
  const [values, setValues] = useState(() => initialValues(computer));
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [saving, setSaving] = useState(false);

  /** Updates one value and clears that field's error. */
  const setValue = (stateKey, errorKey, value) => {
    setValues((cur) => ({ ...cur, [stateKey]: value }));
    setFieldErrors((cur) => {
      if (!cur[errorKey]) return cur;
      const next = { ...cur };
      delete next[errorKey];
      return next;
    });
  };

  /** Closes the modal unless a request is running. */
  const handleClose = () => {
    if (!saving) onClose();
  };

  /** Validates, builds the payload and submits. */
  const submit = async () => {
    if (saving) return;

    // Client-side required checks (the Backend stays the source of truth).
    const errors = {};
    if (!values.labId) errors.lab_id = TEXT.required;
    FIELDS.forEach((f) => {
      if (f.required && normalize(f, values[f.state]) === '') errors[f.key] = TEXT.required;
    });
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      setFormError(null);
      return;
    }

    // Current normalized values.
    const current = { lab_id: Number(values.labId) };
    FIELDS.forEach((f) => {
      current[f.key] = normalize(f, values[f.state]);
    });

    // Payload: edit sends only changed fields; create sends the non-empty ones.
    let payload = {};
    if (isEdit) {
      const original = { lab_id: Number(computer.lab_id) };
      FIELDS.forEach((f) => {
        original[f.key] = normalize(f, computer[f.key]);
      });
      Object.keys(current).forEach((k) => {
        if (current[k] !== original[k]) payload[k] = current[k];
      });
      if (Object.keys(payload).length === 0) {
        onClose();
        return;
      }
    } else {
      payload = { lab_id: current.lab_id };
      FIELDS.forEach((f) => {
        if (current[f.key] !== null) payload[f.key] = current[f.key];
      });
    }

    setSaving(true);
    setFormError(null);
    setFieldErrors({});
    try {
      await onSubmit(payload, computer || null);
      onClose();
    } catch (err) {
      // 422 field errors go under their fields; unknown keys and other errors go to the form-level message.
      const flat = flattenErrors(getFieldErrors(err) || (err && err.fieldErrors));
      const known = new Set(['lab_id', ...FIELDS.map((f) => f.key)]);
      const shown = {};
      const extra = [];
      Object.keys(flat).forEach((k) => {
        if (known.has(k)) shown[k] = flat[k];
        else extra.push(flat[k]);
      });
      if (Object.keys(shown).length > 0 || extra.length > 0) {
        setFieldErrors(shown);
        setFormError(extra.length > 0 ? extra.join(' ') : null);
      } else {
        setFormError(getErrorMessage(err, TEXT.saveError));
      }
    } finally {
      setSaving(false);
    }
  };

  /** Form submit (Enter key): same as pressing Save. */
  const handleFormSubmit = (event) => {
    event.preventDefault();
    submit();
  };

  // Lab options; keeps the computer's current lab selectable even if the labs list lacks it.
  const labOptions =
    computer && computer.lab && !labs.some((l) => String(l.id) === String(computer.lab.id)) ? [...labs, computer.lab] : labs;

  // Text input helper: shared props for one descriptor field.
  const field = (f, label, extra) => (
    <Input
      id={`computer-${f.key}`}
      label={label}
      required={f.required}
      maxLength={f.max}
      value={values[f.state]}
      onChange={(e) => setValue(f.state, f.key, e.target.value)}
      error={fieldErrors[f.key]}
      disabled={saving}
      autoComplete="off"
      {...extra}
    />
  );

  const title = isEdit ? TEXT.titleEdit : TEXT.titleCreate;

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
      <Select
        id="computer-lab"
        label={TEXT.lab}
        required
        value={values.labId}
        onChange={(e) => setValue('labId', 'lab_id', e.target.value)}
        error={fieldErrors.lab_id}
        disabled={saving}
      >
        <option value="">{TEXT.pickLab}</option>
        {labOptions.map((l) => (
          <option key={l.id} value={String(l.id)}>
            {l.name}
          </option>
        ))}
      </Select>
      {field(FIELDS[0], TEXT.code, { dir: 'ltr' })}
      <FieldRow cols={2}>
        {field(FIELDS[1], 'IP', { dir: 'ltr' })}
        {field(FIELDS[3], 'MAC', { dir: 'ltr' })}
      </FieldRow>
      {field(FIELDS[2], 'hostname', { dir: 'ltr' })}
      <FieldRow cols={2}>
        {field(FIELDS[4], TEXT.os)}
        {field(FIELDS[5], TEXT.browser)}
      </FieldRow>
      {field(FIELDS[6], TEXT.agent, { dir: 'ltr' })}
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