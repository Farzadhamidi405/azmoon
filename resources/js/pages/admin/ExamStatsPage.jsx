import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  DataTable,
  FilterSelect,
  PageHeader,
} from '../../components/ui/index.js';
import useExams from '../../hooks/useExams.js';
import useResults from '../../hooks/useResults.js';

export default function ExamStatsPage() {
  const { items: exams } = useExams();
  const [selectedExamId, setSelectedExamId] = useState('');

  const activeExamId = selectedExamId || (exams.length > 0 ? exams[0].id : '');
  const { statistics, analytics, loading } = useResults(activeExamId);

  const columns = useMemo(
    () => [
      {
        key: 'question_id',
        title: 'شماره / متن سوال',
        render: (_, row) => (
          <div className="max-w-[450px]">
            <div className="font-semibold text-ink line-clamp-2">
              {row.question_text || `سوال #${row.question_id}`}
            </div>
            <div className="text-xs text-ink-soft">
              موضوع: {row.topic || row.section || 'عمومی'}
            </div>
          </div>
        ),
      },
      {
        key: 'facility_index',
        title: 'ضریب آسانی (p-value)',
        render: (p) => {
          const val = Number(p || 0);
          let label = 'متوسط';
          let tone = 'gold';
          if (val > 0.75) {
            label = 'بسیار آسان';
            tone = 'teal';
          } else if (val < 0.25) {
            label = 'بسیار دشوار';
            tone = 'bad';
          }
          return (
            <div>
              <span className="font-mono font-bold text-xs">{val.toFixed(2)}</span>
              <div className="mt-0.5">
                <Badge tone={tone}>{label}</Badge>
              </div>
            </div>
          );
        },
      },
      {
        key: 'discrimination_index',
        title: 'ضریب تمیز (Discrimination)',
        render: (d) => {
          const val = Number(d || 0);
          let tone = 'off';
          let label = 'ضعیف';
          if (val >= 0.4) {
            tone = 'teal';
            label = 'عالی';
          } else if (val >= 0.2) {
            tone = 'gold';
            label = 'قابل قبول';
          }
          return (
            <div>
              <span className="font-mono font-bold text-xs">{val.toFixed(2)}</span>
              <div className="mt-0.5">
                <Badge tone={tone}>{label}</Badge>
              </div>
            </div>
          );
        },
      },
      {
        key: 'response_count',
        title: 'تعداد پاسخ‌ها',
        render: (c) => <span className="text-xs font-mono">{c ?? '—'}</span>,
      },
    ],
    []
  );

  return (
    <>
      <PageHeader
        title="آمار تحلیلی و روان‌سنجی آزمون"
        crumb={['نتایج', 'تحلیل سوالات و روان‌سنجی']}
        actions={
          <Button variant="outline" onClick={() => window.print()}>
            🖨️ چاپ تحلیل آماری
          </Button>
        }
      />

      {/* Select Exam Bar */}
      <div className="card mb-4 p-4 flex flex-col md:flex-row items-center justify-between gap-3 bg-panel">
        <div className="w-full md:w-96">
          <label className="text-xs font-bold text-navy block mb-1">انتخاب آزمون برای تحلیل روان‌سنجی:</label>
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

        <div className="text-xs text-ink-soft leading-relaxed max-w-md">
          تحلیل روان‌سنجی بر اساس نظریه کلاسیک آزمون (CTT): ضرایب دشواری و تمیز سوالات برای سنجش روایی و پایایی سوالات محاسبه می‌شوند.
        </div>
      </div>

      {/* KPI Cards */}
      {statistics && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
          <div className="card p-3">
            <div className="text-xs text-ink-soft">میانگین نمرات (Mean)</div>
            <div className="text-2xl font-black text-navy">{statistics.average_score ?? '—'}</div>
          </div>
          <div className="card p-3">
            <div className="text-xs text-ink-soft">نرخ قبولی (Pass Rate)</div>
            <div className="text-2xl font-black text-teal">
              {statistics.pass_rate !== undefined ? `${statistics.pass_rate}%` : '—'}
            </div>
          </div>
          <div className="card p-3">
            <div className="text-xs text-ink-soft">بالاترین / پایین‌ترین نمره</div>
            <div className="text-sm font-bold text-ink mt-1">
              ماکسیمم: <b className="text-teal">{statistics.highest_score ?? '—'}</b> | مینیمم: <b className="text-red">{statistics.lowest_score ?? '—'}</b>
            </div>
          </div>
          <div className="card p-3">
            <div className="text-xs text-ink-soft">انحراف معیار (Std Dev)</div>
            <div className="text-2xl font-black text-gold">{statistics.standard_deviation ?? '—'}</div>
          </div>
        </div>
      )}

      {/* Question Item Analysis Table */}
      <div className="card">
        <div className="p-3 border-b border-line flex justify-between items-center">
          <h3 className="font-bold text-navy text-sm">تحلیل تک‌تک سوالات (Item Analysis & Discrimination)</h3>
          <span className="text-xs text-ink-soft">تعداد سوالات تحلیل‌شده: {analytics.length}</span>
        </div>
        <DataTable
          columns={columns}
          data={analytics}
          loading={loading}
          emptyTitle="داده‌های تحلیلی برای سوالات این آزمون هنوز آماده نشده است."
        />
      </div>
    </>
  );
}
