/**
 * AutoAssignModal - preview and confirm automatic assignment of candidates to computers for one shift.
 * Opens with a read-only preview (confirmed=false); the Confirm button is the only action that saves.
 * Permission flags only hide the entry button; the Backend Policy (labs.update) is the source of truth.
 */
import { Alert, Button, Modal, UI_TEXT } from '../ui/index.js';
import { useAutoAssign } from '../../hooks/useAutoAssign.js';

// Em dash shown for empty cells.
const DASH = '\u2014';

// UI texts (Persian written as Unicode escapes, English meaning beside each).
const TEXT = Object.freeze({
  title: '\u062A\u062E\u0635\u06CC\u0635 \u062E\u0648\u062F\u06A9\u0627\u0631 \u062F\u0627\u0648\u0637\u0644\u0628\u0627\u0646', // Auto-assign candidates
  description: '\u067E\u06CC\u0634\u200C\u0646\u0645\u0627\u06CC\u0634: \u062A\u0627 \u0632\u0645\u0627\u0646\u06CC \u06A9\u0647 \u062A\u0623\u06CC\u06CC\u062F \u0646\u06A9\u0646\u06CC\u062F\u060C \u0686\u06CC\u0632\u06CC \u0630\u062E\u06CC\u0631\u0647 \u0646\u0645\u06CC\u200C\u0634\u0648\u062F.', // Preview: nothing is saved until you confirm.
  shift: '\u0634\u06CC\u0641\u062A', // Shift
  lab: '\u0644\u0627\u0628\u0631\u0627\u062A\u0648\u0627\u0631', // Lab
  capacity: '\u0638\u0631\u0641\u06CC\u062A', // Capacity
  tileTotal: '\u062F\u0627\u0648\u0637\u0644\u0628\u0627\u0646 \u0628\u062F\u0648\u0646 \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Candidates without a computer
  tileComputers: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631\u0647\u0627\u06CC \u0622\u0646\u0644\u0627\u06CC\u0646', // Online computers
  tileCapacity: '\u0638\u0631\u0641\u06CC\u062A \u0628\u0627\u0642\u06CC\u200C\u0645\u0627\u0646\u062F\u0647', // Remaining capacity
  tileWill: '\u062A\u062E\u0635\u06CC\u0635 \u062F\u0627\u062F\u0647 \u0645\u06CC\u200C\u0634\u0648\u0646\u062F', // Will be assigned
  tileRemain: '\u0628\u062F\u0648\u0646 \u062A\u062E\u0635\u06CC\u0635 \u0645\u06CC\u200C\u0645\u0627\u0646\u0646\u062F', // Will stay unassigned
  plannedTitle: '\u062A\u062E\u0635\u06CC\u0635\u200C\u0647\u0627\u06CC \u067E\u06CC\u0634\u0646\u0647\u0627\u062F\u06CC', // Proposed assignments
  unassignedTitle: '\u062F\u0627\u0648\u0637\u0644\u0628\u0627\u0646 \u062A\u062E\u0635\u06CC\u0635\u200C\u0646\u06CC\u0627\u0641\u062A\u0647', // Unassigned candidates
  colCandidate: '\u062F\u0627\u0648\u0637\u0644\u0628', // Candidate
  colNationalId: '\u06A9\u062F \u0645\u0644\u06CC', // National ID
  colComputer: '\u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631', // Computer
  colIp: '\u0622\u062F\u0631\u0633 IP', // IP address
  colReason: '\u062F\u0644\u06CC\u0644', // Reason
  confirm: '\u062A\u0623\u06CC\u06CC\u062F \u0648 \u062A\u062E\u0635\u06CC\u0635', // Confirm and assign
  nothingToAssign: '\u0647\u06CC\u0686 \u062F\u0627\u0648\u0637\u0644\u0628\u06CC \u0642\u0627\u0628\u0644 \u062A\u062E\u0635\u06CC\u0635 \u0646\u06CC\u0633\u062A.', // No candidate can be assigned.
  noUnassigned: '\u062F\u0627\u0648\u0637\u0644\u0628 \u0628\u062F\u0648\u0646 \u06A9\u0627\u0645\u067E\u06CC\u0648\u062A\u0631\u06CC \u062F\u0631 \u0627\u06CC\u0646 \u0634\u06CC\u0641\u062A \u0648\u062C\u0648\u062F \u0646\u062F\u0627\u0631\u062F.', // No candidate without a computer in this shift.
  done: '\u062A\u062E\u0635\u06CC\u0635 \u0627\u0646\u062C\u0627\u0645 \u0634\u062F.', // Assignment completed.
});

/** Candidate display: name, then national id, then #id. */
function candidateLabel(row) {
  if (row.candidate_name) return row.candidate_name;
  if (row.national_id) return row.national_id;
  return `#${row.candidate_id}`;
}

/** One summary tile (label + number). */
function SummaryTile({ label, value }) {
  return (
    <div className="rounded-ds border border-line bg-white px-3 py-2">
      <div className="text-xs text-ink-soft">{label}</div>
      <div dir="ltr" className="text-lg font-bold text-navy">
        {value}
      </div>
    </div>
  );
}

/** Table cell that renders an em dash when empty. */
function Cell({ value, ltr = false }) {
  const empty = value === null || value === undefined || value === '';
  if (empty) return <td className="px-3 py-2 text-ink">{DASH}</td>;
  return <td className="px-3 py-2 text-ink">{ltr ? <span dir="ltr">{value}</span> : value}</td>;
}

/** Simple titled table used for both plan lists. */
function PlanTable({ title, headers, children }) {
  return (
    <section>
      <h3 className="m-0 mb-2 text-[13px] font-bold text-navy">{title}</h3>
      <div className="overflow-x-auto rounded-ds border border-line">
        <table className="w-full text-start text-[13px]">
          <thead className="bg-chip text-xs text-ink-soft">
            <tr>
              {headers.map((h) => (
                <th key={h} className="px-3 py-2 text-start font-semibold">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>{children}</tbody>
        </table>
      </div>
    </section>
  );
}

/**
 * Modal for the auto-assign flow of one shift.
 * @param {object} props - open, shift (row from the shifts table), onClose.
 */
export default function AutoAssignModal({ open = true, shift = null, onClose }) {
  const shiftId = shift ? shift.id : null;
  const { preview, loading, error, confirming, confirmError, result, reload, confirm } = useAutoAssign(shiftId);

  // Derived preview data (defensive: every part may be missing).
  const summary = preview && preview.summary ? preview.summary : null;
  const assignments = preview && Array.isArray(preview.assignments) ? preview.assignments : [];
  const unassigned = preview && Array.isArray(preview.unassigned) ? preview.unassigned : [];
  const willAssign = summary ? Number(summary.will_be_assigned) || 0 : 0;
  const totalUnassigned = summary ? Number(summary.total_unassigned) || 0 : 0;
  const labName =
    (preview && preview.shift && preview.shift.lab_name) || (shift && shift.lab && shift.lab.name) || '';

  // Confirm is possible only with a loaded preview, something to assign and no request in flight.
  const busy = loading || confirming;
  const canConfirm = Boolean(summary) && willAssign > 0 && !busy && !result;

  /** Closes the modal unless a confirm request is running. */
  function handleClose() {
    if (!confirming && typeof onClose === 'function') onClose();
  }

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={TEXT.title}
      description={result ? undefined : TEXT.description}
      size="xl"
      dismissible={!confirming}
      footer={
        <>
          <Button variant="outline" onClick={handleClose} disabled={confirming}>
            {UI_TEXT.close}
          </Button>
          {!result ? (
            <Button onClick={confirm} loading={confirming} disabled={!canConfirm}>
              {TEXT.confirm}
            </Button>
          ) : null}
        </>
      }
    >
      <div className="space-y-4">
        {/* Shift info taken from the table row. */}
        {shift ? (
          <div className="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-ink">
            <span>
              <span className="font-bold text-navy">{TEXT.shift}: </span>
              {shift.name}
            </span>
            {labName ? (
              <span>
                <span className="font-bold text-navy">{TEXT.lab}: </span>
                {labName}
              </span>
            ) : null}
            {shift.capacity != null ? (
              <span>
                <span className="font-bold text-navy">{TEXT.capacity}: </span>
                <span dir="ltr">{shift.capacity}</span>
              </span>
            ) : null}
          </div>
        ) : null}

        {loading ? <Alert tone="info">{UI_TEXT.loading}</Alert> : null}

        {error ? (
          <Alert
            tone="error"
            action={
              <Button size="sm" variant="outline" onClick={() => reload()}>
                {UI_TEXT.retry}
              </Button>
            }
          >
            {error}
          </Alert>
        ) : null}

        {confirmError ? <Alert tone="error">{confirmError}</Alert> : null}

        {result ? <Alert tone="success">{result.message || TEXT.done}</Alert> : null}

        {summary && !result ? (
          <>
            <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
              <SummaryTile label={TEXT.tileTotal} value={summary.total_unassigned} />
              <SummaryTile label={TEXT.tileComputers} value={summary.available_computers} />
              <SummaryTile label={TEXT.tileCapacity} value={summary.remaining_capacity} />
              <SummaryTile label={TEXT.tileWill} value={summary.will_be_assigned} />
              <SummaryTile label={TEXT.tileRemain} value={summary.will_remain} />
            </div>

            {totalUnassigned === 0 ? <Alert tone="info">{TEXT.noUnassigned}</Alert> : null}
            {totalUnassigned > 0 && willAssign === 0 ? <Alert tone="warning">{TEXT.nothingToAssign}</Alert> : null}

            {assignments.length > 0 ? (
              <PlanTable
                title={TEXT.plannedTitle}
                headers={[TEXT.colCandidate, TEXT.colNationalId, TEXT.colComputer, TEXT.colIp]}
              >
                {assignments.map((row) => (
                  <tr key={row.assignment_id} className="border-t border-line-soft">
                    <Cell value={candidateLabel(row)} />
                    <Cell value={row.national_id} ltr />
                    <Cell value={row.computer_code} ltr />
                    <Cell value={row.ip_address} ltr />
                  </tr>
                ))}
              </PlanTable>
            ) : null}

            {unassigned.length > 0 ? (
              <PlanTable
                title={TEXT.unassignedTitle}
                headers={[TEXT.colCandidate, TEXT.colNationalId, TEXT.colReason]}
              >
                {unassigned.map((row) => (
                  <tr key={row.assignment_id} className="border-t border-line-soft">
                    <Cell value={candidateLabel(row)} />
                    <Cell value={row.national_id} ltr />
                    <Cell value={row.reason} />
                  </tr>
                ))}
              </PlanTable>
            ) : null}
          </>
        ) : null}
      </div>
    </Modal>
  );
}
