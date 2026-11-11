import { Alert, Badge, Button, Card, EmptyState, Skeleton, UI_TEXT } from '../ui/index.js';
import { formatDateTime } from '../../lib/formatDate.js';

export const ACTIVITY_TEXT = Object.freeze({
  title: '\u0641\u0639\u0627\u0644\u06CC\u062A\u200C\u0647\u0627\u06CC \u0627\u062E\u06CC\u0631', // Recent activity
  empty: '\u0641\u0639\u0627\u0644\u06CC\u062A\u06CC \u062B\u0628\u062A \u0646\u0634\u062F\u0647 \u0627\u0633\u062A.', // No activity recorded
  unknownUser: '\u2014',
  manualOverride: '\u062A\u063A\u06CC\u06CC\u0631 \u062F\u0633\u062A\u06CC', // Manual change
});

// Known audit values only (taken from the Backend writers); anything else is shown unchanged.
const ACTION_TEXT = {
  'Backup:created': '\u0627\u06CC\u062C\u0627\u062F \u067E\u0634\u062A\u06CC\u0628\u0627\u0646', // Backup created
  'Backup:downloaded': '\u062F\u0627\u0646\u0644\u0648\u062F \u067E\u0634\u062A\u06CC\u0628\u0627\u0646', // Backup downloaded
  'Backup:deleted': '\u062D\u0630\u0641 \u067E\u0634\u062A\u06CC\u0628\u0627\u0646', // Backup deleted
  'Backup:restored': '\u0628\u0627\u0632\u06CC\u0627\u0628\u06CC \u067E\u0634\u062A\u06CC\u0628\u0627\u0646', // Backup restored
  'Backup:restore_failed': '\u0628\u0627\u0632\u06CC\u0627\u0628\u06CC \u067E\u0634\u062A\u06CC\u0628\u0627\u0646 \u0646\u0627\u0645\u0648\u0641\u0642', // Backup restore failed
  'Faculty:created': '\u0627\u06CC\u062C\u0627\u062F \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Faculty created
  'Faculty:updated': '\u0648\u06CC\u0631\u0627\u06CC\u0634 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Faculty updated
  'Faculty:deleted': '\u062D\u0630\u0641 \u062F\u0627\u0646\u0634\u06A9\u062F\u0647', // Faculty deleted
  'Subject:created': '\u0627\u06CC\u062C\u0627\u062F \u0645\u0636\u0645\u0648\u0646', // Subject created
  'Subject:updated': '\u0648\u06CC\u0631\u0627\u06CC\u0634 \u0645\u0636\u0645\u0648\u0646', // Subject updated
  'Subject:deleted': '\u062D\u0630\u0641 \u0645\u0636\u0645\u0648\u0646', // Subject deleted
  'SubjectAccess:granted': '\u0627\u0639\u0637\u0627\u06CC \u062F\u0633\u062A\u0631\u0633\u06CC \u0628\u0647 \u0645\u0636\u0645\u0648\u0646', // Subject access granted
  'SubjectAccess:revoked': '\u0644\u063A\u0648 \u062F\u0633\u062A\u0631\u0633\u06CC \u0628\u0647 \u0645\u0636\u0645\u0648\u0646', // Subject access revoked
  'User:created': '\u0627\u06CC\u062C\u0627\u062F \u06A9\u0627\u0631\u0628\u0631', // User created
  'User:updated': '\u0648\u06CC\u0631\u0627\u06CC\u0634 \u06A9\u0627\u0631\u0628\u0631', // User updated
  'User:deactivated': '\u063A\u06CC\u0631\u0641\u0639\u0627\u0644\u200C\u0633\u0627\u0632\u06CC \u06A9\u0627\u0631\u0628\u0631', // User deactivated
  CheckedIn: '\u062B\u0628\u062A \u062D\u0636\u0648\u0631 \u0645\u062A\u0642\u0627\u0636\u06CC', // Candidate checked in
  SessionTransfer: '\u0627\u0646\u062A\u0642\u0627\u0644 \u062C\u0644\u0633\u0647', // Session transfer
  Created: '\u0627\u06CC\u062C\u0627\u062F', // Created (settings)
  Updated: '\u0648\u06CC\u0631\u0627\u06CC\u0634', // Updated (settings)
};

const MODULE_TEXT = {
  backups: '\u067E\u0634\u062A\u06CC\u0628\u0627\u0646\u200C\u06AF\u06CC\u0631\u06CC', // Backups
  attempts: '\u062C\u0644\u0633\u0627\u062A \u0627\u0645\u062A\u062D\u0627\u0646', // Exam sessions
  candidates: '\u0645\u062A\u0642\u0627\u0636\u06CC\u0627\u0646', // Candidates
  faculties: '\u062F\u0627\u0646\u0634\u06A9\u062F\u0647\u200C\u0647\u0627', // Faculties
  settings: '\u062A\u0646\u0638\u06CC\u0645\u0627\u062A', // Settings
  subjects: '\u0645\u0636\u0627\u0645\u06CC\u0646', // Subjects
  users: '\u06A9\u0627\u0631\u0628\u0631\u0627\u0646', // Users
};

function lookup(map, key) {
  return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : null;
}

function describeAction(action) {
  const raw = typeof action === 'string' ? action : '';
  if (!raw) return { text: null, raw: '' };
  const exact = lookup(ACTION_TEXT, raw);
  if (exact) return { text: exact, raw: '' };
  if (raw.startsWith('ManualOverride:')) {
    return { text: ACTIVITY_TEXT.manualOverride, raw: raw.slice('ManualOverride:'.length) };
  }
  return { text: null, raw };
}

function ActivityRow({ row }) {
  const user = row && row.user ? row.user : null;
  const who = (user && (user.name || user.username)) || ACTIVITY_TEXT.unknownUser;
  const action = describeAction(row && row.action);
  const moduleRaw = row && typeof row.module === 'string' ? row.module : '';
  const moduleText = moduleRaw ? lookup(MODULE_TEXT, moduleRaw) : null;
  const when = formatDateTime(row && row.created_at);
  return (
    <li className="flex flex-col gap-1 border-b border-line-soft py-2.5 last:border-b-0 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
      <div className="min-w-0 break-words text-[12.5px] leading-6 text-ink">
        <b className="font-semibold text-navy">{who}</b>
        <span className="text-ink-soft">{' \u00B7 '}</span>
        {action.text ? <span>{action.text}</span> : null}
        {action.raw ? (
          <span dir="ltr" className="mx-1 inline-block font-mono text-[11.5px] text-ink-soft">
            {action.raw}
          </span>
        ) : null}
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {moduleRaw ? (
          <Badge variant="off" dot={false}>
            {moduleText ? moduleText : <span dir="ltr">{moduleRaw}</span>}
          </Badge>
        ) : null}
        {when ? (
          <time dir="ltr" dateTime={row.created_at} className="text-[11px] text-ink-soft">
            {when}
          </time>
        ) : null}
      </div>
    </li>
  );
}

// state: { status: hidden | loading | ok | error | forbidden, value, message } from useDashboardData.
export default function RecentActivity({ state, onRetry, forbiddenText, className }) {
  const status = state ? state.status : 'hidden';
  if (status === 'hidden') return null;
  let body = null;
  if (status === 'loading') {
    body = (
      <div aria-busy="true" className="flex flex-col gap-3">
        {[0, 1, 2, 3].map((i) => (
          <Skeleton key={i} className="h-6 w-full" />
        ))}
      </div>
    );
  } else if (status === 'forbidden') {
    body = <Alert tone="warning">{forbiddenText}</Alert>;
  } else if (status === 'error') {
    body = (
      <Alert
        tone="error"
        action={
          onRetry ? (
            <Button size="sm" variant="outline" onClick={onRetry}>
              {UI_TEXT.retry}
            </Button>
          ) : null
        }
      >
        {state.message || UI_TEXT.loadError}
      </Alert>
    );
  } else if (!Array.isArray(state.value) || state.value.length === 0) {
    body = <EmptyState bare title={ACTIVITY_TEXT.empty} />;
  } else {
    body = (
      <ul className="m-0 list-none p-0">
        {state.value.map((row, i) => (
          <ActivityRow key={row && row.id !== undefined ? row.id : i} row={row} />
        ))}
      </ul>
    );
  }
  return (
    <Card title={ACTIVITY_TEXT.title} className={className}>
      {body}
    </Card>
  );
}

export { RecentActivity };
