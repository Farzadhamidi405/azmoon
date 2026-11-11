import { Button, EmptyState, PageHeader, StatCard, StatGrid, UI_TEXT } from '../../components/ui/index.js';
import RecentActivity from '../../components/dashboard/RecentActivity.jsx';
import useDashboardData from '../../hooks/useDashboardData.js';
import { useAuth } from '../../hooks/useAuth.js';

const TEXT = Object.freeze({
  title: '\u062F\u0627\u0634\u0628\u0648\u0631\u062F \u0645\u062F\u06CC\u0631\u06CC\u062A', // Admin dashboard
  crumbRoot: '\u067E\u0648\u0647\u0646\u062A\u0648\u0646 \u06A9\u0646\u062F\u0632', // Kunduz university (as in the prototype)
  crumbPage: '\u062F\u0627\u0634\u0628\u0648\u0631\u062F', // Dashboard
  refresh: '\u062A\u0627\u0632\u0647\u200C\u0633\u0627\u0632\u06CC', // Refresh
  published: '\u0622\u0632\u0645\u0648\u0646\u200C\u0647\u0627\u06CC \u0645\u0646\u062A\u0634\u0631\u0634\u062F\u0647', // Published exams
  running: '\u062F\u0631 \u062D\u0627\u0644 \u0627\u062C\u0631\u0627', // Running
  candidates: '\u0645\u062A\u0642\u0627\u0636\u06CC \u062B\u0628\u062A\u200C\u0634\u062F\u0647', // Registered candidates
  inProgress: '\u0645\u062A\u0642\u0627\u0636\u06CC \u062F\u0631 \u062D\u0627\u0644 \u0627\u0645\u062A\u062D\u0627\u0646 (\u0637\u0628\u0642 \u0648\u0636\u0639\u06CC\u062A \u062B\u0628\u062A\u200C\u0634\u062F\u0647)', // Candidates taking an exam (by recorded status)
  forbidden: '\u062F\u0633\u062A\u0631\u0633\u06CC \u0628\u0647 \u0627\u06CC\u0646 \u0628\u062E\u0634 \u0631\u0627 \u0646\u062F\u0627\u0631\u06CC\u062F.', // You do not have access to this section.
  nothing: '\u0628\u0631\u0627\u06CC \u0646\u0642\u0634 \u0634\u0645\u0627 \u0628\u062E\u0634\u06CC \u062F\u0631 \u0627\u06CC\u0646 \u0635\u0641\u062D\u0647 \u0642\u0627\u0628\u0644 \u0646\u0645\u0627\u06CC\u0634 \u0646\u06CC\u0633\u062A.', // Nothing to show for your role.
});

// A number is only shown when the API answered; otherwise the card shows a dash plus a reason.
function cardProps(source) {
  if (source.status === 'loading') return { loading: true };
  if (source.status === 'ok') return { value: source.value };
  if (source.status === 'forbidden') return { hint: TEXT.forbidden };
  return { hint: source.message || UI_TEXT.loadError };
}

function runningHint(running) {
  if (running.status === 'loading') return null;
  return TEXT.running + ': ' + (running.status === 'ok' ? running.value : '\u2014');
}

export default function DashboardPage() {
  const { user } = useAuth();
  const { data, reload, busy } = useDashboardData(user);
  const { published, running, candidates, inProgress, activity } = data;

  const showExams = published.status !== 'hidden';
  const showCandidates = candidates.status !== 'hidden';
  const showInProgress = inProgress.status !== 'hidden';
  const showActivity = activity.status !== 'hidden';
  const showStats = showExams || showCandidates || showInProgress;

  const publishedProps = cardProps(published);
  if (published.status === 'ok') publishedProps.hint = runningHint(running);

  return (
    <>
      <PageHeader
        title={TEXT.title}
        crumb={[TEXT.crumbRoot, TEXT.crumbPage]}
        actions={
          <Button variant="outline" size="sm" loading={busy} onClick={reload}>
            {TEXT.refresh}
          </Button>
        }
      />
      {showStats || showActivity ? (
        <>
          {showStats ? (
            <StatGrid cols={3}>
              {showExams ? <StatCard label={TEXT.published} accent="gold" {...publishedProps} /> : null}
              {showCandidates ? <StatCard label={TEXT.candidates} accent="navy" {...cardProps(candidates)} /> : null}
              {showInProgress ? <StatCard label={TEXT.inProgress} accent="teal" {...cardProps(inProgress)} /> : null}
            </StatGrid>
          ) : null}
          {showActivity ? (
            <div className="mt-4">
              <RecentActivity state={activity} onRetry={reload} forbiddenText={TEXT.forbidden} />
            </div>
          ) : null}
        </>
      ) : (
        <EmptyState title={TEXT.nothing} />
      )}
    </>
  );
}

export { DashboardPage };
