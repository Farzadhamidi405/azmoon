/**
 * ShiftFormModal - create and edit modal for an exam shift.
 * Create requires exam_id, lab_id, name, start_time, end_time and capacity.
 * Edit locks exam_id (not accepted by ShiftController@update) and sends only modified fields.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Button, FieldRow, Input, Modal, Select } from '../ui/index.js';
import { getErrorMessage, getFieldErrors } from '../../lib/api.js';

// UI texts (Persian as Unicode escapes, English meaning beside each).
const TEXT = Object.freeze({
    addTitle: '\u0627\u0641\u0632\u0648\u062F\u0646 \u0634\u0641\u062A', // Add shift
    editTitle: '\u0648\u06CC\u0631\u0627\u06CC\u0634 \u0634\u0641\u062A', // Edit shift
    examLabel: '\u0622\u0632\u0645\u0648\u0646', // Exam
    examIdLabel: '\u0634\u0646\u0627\u0633\u0647 \u0622\u0632\u0645\u0648\u0646 (ID)', // Exam ID
    examPlaceholder: '\u0627\u0646\u062A\u062E\u0627\u0628 \u0622\u0632\u0645\u0648\u0646', // Select exam
    examIdPlaceholder: '\u0645\u062B\u0627\u0644: 1', // Example: 1
    manualExamToggle: '\u0648\u0631\u0648\u062F \u0634\u0646\u0627\u0633\u0647 \u0622\u0632\u0645\u0648\u0646', // Enter exam ID
    listExamToggle: '\u0627\u0646\u062A\u062E\u0627\u0628 \u0627\u0632 \u0641\u0647\u0631\u0633\u062A', // Pick from list
    examLockedHelper: '\u0622\u0632\u0645\u0648\u0646 \u0634\u0641\u062A \u067E\u0633 \u0627\u0632 \u0627\u06CC\u062C\u0627\u062F \u0642\u0627\u0628\u0644 \u062A\u063A\u06CC\u06CC\u0631 \u0646\u06CC\u0633\u062A.', // Shift exam cannot be changed after creation.
    labLabel: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631', // Lab
    labPlaceholder: '\u0627\u0646\u062A\u062E\u0627\u0628 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631', // Select lab
    nameLabel: '\u0646\u0627\u0645 \u0634\u0641\u062A', // Shift name
    namePlaceholder: '\u0645\u062B\u0627\u0644: \u0634\u0641\u062A \u0635\u0628\u062D', // Example: Morning shift
    startLabel: '\u0632\u0645\u0627\u0646 \u0634\u0631\u0648\u0639', // Start time
    endLabel: '\u0632\u0645\u0627\u0646 \u067E\u0627\u06CC\u0627\u0646', // End time
    capacityLabel: '\u0638\u0631\u0641\u06CC\u062A', // Capacity
    capacityHelper: '\u0639\u062F\u062F \u0635\u062D\u06CC\u062D \u0628\u06CC\u0646 1 \u062A\u0627 500', // Integer between 1 and 500
    examRequired: '\u0627\u0646\u062A\u062E\u0627\u0628 \u06CC\u0627 \u0648\u0631\u0648\u062F \u0622\u0632\u0645\u0648\u0646 \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // Exam is required.
    labRequired: '\u0627\u0646\u062A\u062E\u0627\u0628 \u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631 \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // Lab is required.
    nameRequired: '\u0646\u0627\u0645 \u0634\u0641\u062A \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // Shift name is required.
    startRequired: '\u0632\u0645\u0627\u0646 \u0634\u0631\u0648\u0639 \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // Start time is required.
    endRequired: '\u0632\u0645\u0627\u0646 \u067E\u0627\u06CC\u0627\u0646 \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // End time is required.
    endAfterStart: '\u0632\u0645\u0627\u0646 \u067E\u0627\u06CC\u0627\u0646 \u0628\u0627\u06CC\u062F \u0628\u0639\u062F \u0627\u0632 \u0632\u0645\u0627\u0646 \u0634\u0631\u0648\u0639 \u0628\u0627\u0634\u062F.', // End time must be after start time.
    capacityRequired: '\u0638\u0631\u0641\u06CC\u062A \u0627\u0644\u0632\u0627\u0645\u06CC \u0627\u0633\u062A.', // Capacity is required.
    capacityRange: '\u0638\u0631\u0641\u06CC\u062A \u0628\u0627\u06CC\u062F \u0639\u062F\u062F \u0635\u062D\u06CC\u062D \u0628\u06CC\u0646 1 \u062A\u0627 500 \u0628\u0627\u0634\u062F.', // Capacity must be an integer between 1 and 500.
    save: '\u0630\u062E\u06CC\u0631\u0647', // Save
    cancel: '\u0627\u0646\u0635\u0631\u0627\u0641', // Cancel
});

/**
 * Converts a backend datetime string into a datetime-local input value (YYYY-MM-DDTHH:mm)
 * without browser timezone shifting.
 */
function toDateTimeLocal(value) {
    if (!value || typeof value !== 'string') return '';
    const trimmed = value.trim();
    const match = trimmed.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
    if (match) return `${match[1]}T${match[2]}`;
    const d = new Date(trimmed);
    if (Number.isNaN(d.getTime())) return '';
    return d.toISOString().slice(0, 16);
}

/** Parses a positive integer ID or returns null when invalid. */
function parsePositiveInt(value) {
    const raw = String(value ?? '').trim();
    if (!/^\d+$/.test(raw)) return null;
    const n = Number(raw);
    return Number.isInteger(n) && n >= 1 ? n : null;
}

/**
 * Modal form for creating or editing a shift.
 * @param {object} props - shift (null for create), labs, exams, canPickExamList, onClose, onSubmit.
 */
export default function ShiftFormModal({
    open = true,
    shift = null,
    labs = [],
    exams = [],
    canPickExamList = false,
    onClose,
    onSubmit,
}) {
    const isEdit = Boolean(shift);

    // Form field values.
    const [examId, setExamId] = useState('');
    const [manualExamMode, setManualExamMode] = useState(false);
    const [labId, setLabId] = useState('');
    const [name, setName] = useState('');
    const [startTime, setStartTime] = useState('');
    const [endTime, setEndTime] = useState('');
    const [capacity, setCapacity] = useState('');

    // Error and submission states.
    const [fieldErrors, setFieldErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const aliveRef = useRef(true);

    // Unmount guard against state updates after unmount.
    useEffect(() => {
        aliveRef.current = true;
        return () => {
            aliveRef.current = false;
        };
    }, []);

    // Lab options: keeps the shift's current lab selectable even if absent from the labs prop.
    const labOptions = useMemo(() => {
        const list = Array.isArray(labs) ? labs : [];
        if (shift && shift.lab && !list.some((l) => String(l.id) === String(shift.lab.id))) {
            return [...list, shift.lab];
        }
        return list;
    }, [labs, shift]);

    // Exam options: keeps the shift's current exam visible even if absent from the exams prop.
    const examOptions = useMemo(() => {
        const list = Array.isArray(exams) ? exams : [];
        if (shift && shift.exam && !list.some((e) => String(e.id) === String(shift.exam.id))) {
            return [...list, shift.exam];
        }
        return list;
    }, [exams, shift]);

    // Resets form state whenever the modal opens or target shift changes.
    useEffect(() => {
        if (!open) return;
        const initialExamId = shift && shift.exam_id != null ? String(shift.exam_id) : '';
        const initialLabId = shift && shift.lab_id != null ? String(shift.lab_id) : '';
        setExamId(initialExamId);
        setManualExamMode(!isEdit && examOptions.length === 0);
        setLabId(initialLabId);
        setName(shift ? shift.name ?? '' : '');
        setStartTime(shift ? toDateTimeLocal(shift.start_time) : '');
        setEndTime(shift ? toDateTimeLocal(shift.end_time) : '');
        setCapacity(shift && shift.capacity != null ? String(shift.capacity) : '');
        setFieldErrors({});
        setFormError('');
        setSubmitting(false);
    }, [open, shift, isEdit, examOptions.length]);

    /** Prevents closing while a request is in flight. */
    function handleClose() {
        if (!submitting && typeof onClose === 'function') onClose();
    }

    /** Validates inputs, builds the create/update payload, and submits to the caller. */
    async function handleSave() {
        if (submitting) return;

        const nextName = name.trim();
        const nextExamInt = parsePositiveInt(examId);
        const nextLabInt = parsePositiveInt(labId);
        const nextStart = startTime.trim();
        const nextEnd = endTime.trim();
        const rawCap = capacity.trim();
        const capNum = rawCap !== '' && /^\d+$/.test(rawCap) ? Number(rawCap) : NaN;

        // Client-side convenience validation (Backend remains source of truth).
        const errors = {};
        if (!isEdit && nextExamInt === null) errors.exam_id = TEXT.examRequired;
        if (nextLabInt === null) errors.lab_id = TEXT.labRequired;
        if (!nextName) errors.name = TEXT.nameRequired;
        if (!nextStart) errors.start_time = TEXT.startRequired;
        if (!nextEnd) {
            errors.end_time = TEXT.endRequired;
        } else if (nextStart && nextEnd <= nextStart) {
            errors.end_time = TEXT.endAfterStart;
        }
        if (rawCap === '') {
            errors.capacity = TEXT.capacityRequired;
        } else if (!Number.isInteger(capNum) || capNum < 1 || capNum > 500) {
            errors.capacity = TEXT.capacityRange;
        }

        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);
            return;
        }

        // Build payload: full on create, only modified fields on edit (exam_id is never sent on edit).
        let payload;
        if (isEdit) {
            payload = {};
            const origName = shift.name ?? '';
            const origLab = Number(shift.lab_id);
            const origStart = toDateTimeLocal(shift.start_time);
            const origEnd = toDateTimeLocal(shift.end_time);
            const origCap = Number(shift.capacity);

            if (nextName !== origName) payload.name = nextName;
            if (nextLabInt !== origLab) payload.lab_id = nextLabInt;
            if (nextStart !== origStart || nextEnd !== origEnd) {
                payload.start_time = nextStart;
                payload.end_time = nextEnd;
            }
            if (capNum !== origCap) payload.capacity = capNum;

            if (Object.keys(payload).length === 0) {
                handleClose();
                return;
            }
        } else {
            payload = {
                exam_id: nextExamInt,
                lab_id: nextLabInt,
                name: nextName,
                start_time: nextStart,
                end_time: nextEnd,
                capacity: capNum,
            };
        }

        setSubmitting(true);
        setFormError('');
        setFieldErrors({});
        try {
            await onSubmit(payload, shift);
            if (!aliveRef.current) return;
            setSubmitting(false);
            if (typeof onClose === 'function') onClose();
        } catch (err) {
            if (!aliveRef.current) return;
            setSubmitting(false);
            const fe = getFieldErrors(err);
            if (Object.keys(fe).length > 0) setFieldErrors(fe);
            if (err && err.status === 422 && Object.keys(fe).length === 0) {
                setFormError(getErrorMessage(err));
            } else if (err && err.status !== 422) {
                setFormError(getErrorMessage(err));
            }
        }
    }

    // Whether to show the numeric exam_id input instead of the exam Select on create.
    const useManualExamInput = !isEdit && (manualExamMode || examOptions.length === 0);
    // Allows switching between known exams dropdown and numeric exam_id entry (useful for Lab Manager).
    const canToggleExamMode = !isEdit && examOptions.length > 0 && !canPickExamList;

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
            <form
                noValidate
                onSubmit={(e) => {
                    e.preventDefault();
                    handleSave();
                }}
            >
                <button type="submit" className="hidden" aria-hidden="true" tabIndex={-1} />

                {formError ? (
                    <div className="mb-4">
                        <Alert tone="error">{formError}</Alert>
                    </div>
                ) : null}

                {/* Exam selection: locked on edit; Select or numeric exam_id input on create. */}
                {useManualExamInput ? (
                    <Input
                        label={TEXT.examIdLabel}
                        type="number"
                        min="1"
                        step="1"
                        dir="ltr"
                        required
                        disabled={submitting}
                        placeholder={TEXT.examIdPlaceholder}
                        value={examId}
                        onChange={(e) => setExamId(e.target.value)}
                        error={fieldErrors.exam_id}
                    />
                ) : (
                    <Select
                        label={TEXT.examLabel}
                        required={!isEdit}
                        disabled={isEdit || submitting}
                        value={examId}
                        onChange={(e) => setExamId(e.target.value)}
                        helper={isEdit ? TEXT.examLockedHelper : undefined}
                        error={fieldErrors.exam_id}
                    >
                        <option value="">{TEXT.examPlaceholder}</option>
                        {examOptions.map((ex) => (
                            <option key={ex.id} value={String(ex.id)}>
                                {ex.title || `#${ex.id}`}
                            </option>
                        ))}
                    </Select>
                )}

                {canToggleExamMode ? (
                    <div className="-mt-2 mb-3 flex justify-end">
                        <button
                            type="button"
                            disabled={submitting}
                            onClick={() => {
                                setManualExamMode((cur) => !cur);
                                setFieldErrors((cur) => ({ ...cur, exam_id: undefined }));
                            }}
                            className="text-xs font-semibold text-teal hover:underline"
                        >
                            {useManualExamInput ? TEXT.listExamToggle : TEXT.manualExamToggle}
                        </button>
                    </div>
                ) : null}

                {/* Lab selection */}
                <Select
                    label={TEXT.labLabel}
                    required
                    disabled={submitting}
                    value={labId}
                    onChange={(e) => setLabId(e.target.value)}
                    error={fieldErrors.lab_id}
                >
                    <option value="">{TEXT.labPlaceholder}</option>
                    {labOptions.map((lab) => (
                        <option key={lab.id} value={String(lab.id)}>
                            {lab.name}
                        </option>
                    ))}
                </Select>

                {/* Shift name and capacity */}
                <FieldRow cols={2}>
                    <Input
                        label={TEXT.nameLabel}
                        required
                        maxLength={100}
                        disabled={submitting}
                        placeholder={TEXT.namePlaceholder}
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        error={fieldErrors.name}
                    />
                    <Input
                        label={TEXT.capacityLabel}
                        type="number"
                        min="1"
                        max="500"
                        step="1"
                        dir="ltr"
                        required
                        disabled={submitting}
                        helper={TEXT.capacityHelper}
                        value={capacity}
                        onChange={(e) => setCapacity(e.target.value)}
                        error={fieldErrors.capacity}
                    />
                </FieldRow>

                {/* Start and end times */}
                <FieldRow cols={2}>
                    <Input
                        label={TEXT.startLabel}
                        type="datetime-local"
                        dir="ltr"
                        required
                        disabled={submitting}
                        value={startTime}
                        onChange={(e) => setStartTime(e.target.value)}
                        error={fieldErrors.start_time}
                    />
                    <Input
                        label={TEXT.endLabel}
                        type="datetime-local"
                        dir="ltr"
                        required
                        disabled={submitting}
                        value={endTime}
                        onChange={(e) => setEndTime(e.target.value)}
                        error={fieldErrors.end_time}
                    />
                </FieldRow>
            </form>
        </Modal>
    );
}








































