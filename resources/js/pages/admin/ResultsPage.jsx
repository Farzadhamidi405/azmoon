import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  DataTable,
  FilterBar,
  FilterInput,
  FilterSelect,
  PageHeader,
} from '../../components/ui/index.js';
import CandidateResultModal from '../../components/results/CandidateResultModal.jsx';
import useExams from '../../hooks/useExams.js';
import useResults from '../../hooks/useResults.js';
import formatDate from '../../lib/formatDate.js';

export default function ResultsPage() {
  const { items: exams } = useExams();
  const [selectedExamId, setSelectedExamId] = useState('');

  // Default to first exam once loaded
  const activeExamId = selectedExamId || (exams.length > 0 ? exams[0].id : '');
  const { results, statistics, loading, getAttemptResult } = useResults(activeExamId);

  const [search, setSearch] = useState('');
  const [viewAttemptId, setViewAttemptId] = useState(null);

  const filteredResults = useMemo(() => {
    if (!search) return results;
    const s = search.toLowerCase();
    return results.filter(
      (r) =>
        r.candidate?.name?.toLowerCase().includes(s) ||
        r.candidate?.code?.toLowerCase().includes(s)
    );
  }, [results, search]);

  const columns = useMemo(
    () => [
      {
        key: 'candidate',
        title: 'متقاضی',
        render: (_, row) => (
          <div>
            <div className="font-bold text-navy">{row.candidate?.name || 'متقاضی'}</div>
            <div className="text-xs text-ink-soft font-mono">کد: {row.candidate?.code}</div>
          </div>
        ),
      },
      {
        key: 'score',
        title: 'نمره کل',
        render: (_, row) => (
          <span className="font-bold text-navy">
            {row.result?.total_score ?? '—'} <span className="text-xs text-ink-soft font-normal">/ {row.result?.max_score ?? 100}</span>
          </span>
        ),
      },
      {
        key: 'percentage',
        title: 'درصد',
        render: (_, row) => (
          <span className="font-mono font-bold text-xs">
            {row.result?.percentage !== undefined ? `${Math.round(row.result.percentage)}%` : '—'}
          </span>
        ),
      },
      {
        key: 'status',
        title: 'نتیجه آزمون',
        render: (_, row) => {
          const isPassed = row.result?.is_passed;
          return (
            <Badge tone={isPassed ? 'teal' : 'bad'}>
              {isPassed ? 'قبول' : 'مردود'}
            </Badge>
          );
        },
      },
      {
        key: 'submitted_at',
        title: 'زمان تحویل',
        render: (d) => <span className="text-xs text-ink-soft">{d ? formatDate(d) : '—'}</span>,
      },
      {
        key: 'actions',
        title: 'عملیات',
        align: 'end',
        render: (_, row) => (
          <Button
            size="sm"
            variant="outline"
            onClick={() => setViewAttemptId(row.attempt_id)}
          >
            مشاهده کارنامه
          </Button>
        ),
      },
    ],
    []
  );

  return (
    <>
      <PageHeader
        title="نتایج و نمرات آزمون"
        crumb={['نتایج', 'کارنامه متقاضیان']}
        actions={
          <Button variant="outline" onClick={() => window.print()}>
            🖨️ چاپ گزارش
          </Button>
        }
      />

      {/* Select Exam Bar */}
      <div className="card mb-4 p-4 flex flex-col md:flex-row items-center justify-between gap-3 bg-panel">
        <div className="w-full md:w-96">
          <label className="text-xs font-bold text-navy block mb-1">انتخاب آزمون برای مشاهده نتایج:</label>
          <FilterSelect
            value={activeExamId}
            onChange={(e) => setSelectedExamId(e.target.value)}
          >
            {exams.map((ex) => (
              <option key={ex.id} value={ex.id}>
                {ex.title}
              </option>
            ))}
          </FilterSelect>
        </div>

        {statistics && (
          <div className="flex gap-4 text-xs">
            <div className="text-center">
              <span className="text-ink-soft block">تعداد شرکت‌کنندگان:</span>
              <span className="font-bold text-sm text-navy">{statistics.total_candidates ?? results.length}</span>
            </div>
            <div className="text-center">
              <span className="text-ink-soft block">میانگین نمره:</span>
              <span className="font-bold text-sm text-gold">{statistics.average_score ?? '—'}</span>
            </div>
            <div className="text-center">
              <span className="text-ink-soft block">نرخ قبولی:</span>
              <span className="font-bold text-sm text-teal">{statistics.pass_rate !== undefined ? `${statistics.pass_rate}%` : '—'}</span>
            </div>
          </div>
        )}
      </div>

      <FilterBar>
        <FilterInput
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="جستجو در نام یا کد متقاضی..."
        />
      </FilterBar>

      <div className="card">
        <DataTable
          columns={columns}
          data={filteredResults}
          loading={loading}
          emptyTitle="هیچ نتیجه‌ای برای این آزمون ثبت نشده است."
        />
      </div>

      <CandidateResultModal
        open={!!viewAttemptId}
        onClose={() => setViewAttemptId(null)}
        attemptId={viewAttemptId}
        onGetResult={getAttemptResult}
      />
    </>
  );
}
