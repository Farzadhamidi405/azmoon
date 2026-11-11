import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  DataTable,
  FilterBar,
  FilterInput,
  FilterSelect,
  PageHeader,
  Spinner,
} from '../../components/ui/index.js';
import useIncidents from '../../hooks/useIncidents.js';
import formatDate from '../../lib/formatDate.js';
import { getErrorMessage } from '../../lib/api.js';

const SEVERITY_CONFIG = {
  low: { label: 'پایین', tone: 'off' },
  medium: { label: 'متوسط', tone: 'gold' },
  high: { label: 'بالا', tone: 'bad' },
  critical: { label: 'بحرانی', tone: 'bad' },
};

export default function AntiCheatPage() {
  const [severityFilter, setSeverityFilter] = useState('');
  const [search, setSearch] = useState('');
  const { items, loading, error, reload } = useIncidents({
    incident_type: 'anti_cheat',
  });

  const filteredItems = useMemo(() => {
    return items.filter((item) => {
      const matchSev = !severityFilter || item.severity === severityFilter;
      const matchSearch =
        !search ||
        item.title?.toLowerCase().includes(search.toLowerCase()) ||
        item.candidate?.name?.toLowerCase().includes(search.toLowerCase());
      return matchSev && matchSearch;
    });
  }, [items, severityFilter, search]);

  // Real-time metric calculations from actual incidents data (only valid when no error)
  const totalEvents = items.length;
  const criticalCount = items.filter((i) => i.severity === 'critical' || i.severity === 'high').length;
  const pendingCount = items.filter((i) => i.status !== 'resolved').length;
  const resolvedCount = items.filter((i) => i.status === 'resolved').length;

  const columns = useMemo(
    () => [
      {
        key: 'created_at',
        title: 'زمان وقوع',
        render: (d) => <span className="font-mono text-xs text-ink-soft">{d ? formatDate(d) : '—'}</span>,
      },
      {
        key: 'candidate',
        title: 'متقاضی / سیستم',
        render: (_, row) => (
          <div>
            <div className="font-bold text-navy text-xs">{row.candidate?.name || 'متقاضی'}</div>
            <div className="text-[11px] text-ink-soft font-mono">
              کد: {row.candidate?.candidate_code || '—'} · سیستم: {row.computer?.computer_code || '—'}
            </div>
          </div>
        ),
      },
      {
        key: 'title',
        title: 'نوع تخلف یا رویداد ثبت‌شده',
        render: (_, row) => (
          <div>
            <div className="font-bold text-ink text-xs">{row.title}</div>
            <div className="text-[11px] text-ink-soft line-clamp-1">{row.description}</div>
          </div>
        ),
      },
      {
        key: 'severity',
        title: 'شدت خطر',
        render: (sev) => {
          const cfg = SEVERITY_CONFIG[sev] || { label: sev, tone: 'off' };
          return <Badge tone={cfg.tone}>{cfg.label}</Badge>;
        },
      },
      {
        key: 'status',
        title: 'وضعیت بررسی',
        render: (st) => (
          <Badge tone={st === 'resolved' ? 'teal' : 'bad'}>
            {st === 'resolved' ? 'بررسی‌شده' : 'نیازمند بررسی'}
          </Badge>
        ),
      },
    ],
    []
  );

  return (
    <>
      <PageHeader
        title="مرکز نظارت بر تقلب و امنیت کلاینت (Anti-Cheat)"
        crumb={['امنیت', 'هشدارهای ضدتقلب']}
        actions={
          <Button variant="outline" onClick={reload} disabled={loading}>
            ⟳ بروزرسانی زنده
          </Button>
        }
      />

      {/* Error State: Displayed inline within the page instead of toast/fake zeros */}
      {error && !loading && (
        <div className="card mb-6 p-6 border-red/30 bg-red-soft/20 text-right space-y-3">
          <div className="flex items-center gap-2 text-red font-bold text-sm">
            <span>⚠️</span>
            <span>خطا در دریافت اطلاعات ضدتقلب</span>
          </div>
          <div className="text-xs text-ink leading-relaxed">
            {error.status === 403
              ? 'شما مجوز دسترسی به رویدادهای ضدتقلب و حوادث آزمون را ندارید.'
              : error.status === 0 || error.isNetworkError
              ? 'عدم امکان برقراری ارتباط با سرور. لطفاً اتصال شبکه خود را بررسی نمایید.'
              : error.status >= 500
              ? 'خطای داخلی در سرور آزمون هنگام واکشی رویدادها رخ داده است.'
              : getErrorMessage(error, 'خطا در بارگذاری اطلاعات رویدادهای ضدتقلب.')}
          </div>
          <div className="pt-2">
            <Button size="sm" variant="outline" onClick={reload}>
              تلاش مجدد
            </Button>
          </div>
        </div>
      )}

      {/* Loading Indicator */}
      {loading && !error && (
        <div className="card p-12 text-center text-ink-soft flex items-center justify-center gap-2 mb-4">
          <Spinner size="sm" />
          <span>در حال بارگذاری وقایع و اطلاعات ضدتقلب...</span>
        </div>
      )}

      {/* Real Metric Summary Cards: Shown ONLY when request is successful and no error */}
      {!error && !loading && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
          <div className="card p-3">
            <div className="text-xs text-ink-soft">مجموع وقایع ثبت‌شده</div>
            <div className="text-xl font-black text-navy">{totalEvents} مورد</div>
          </div>
          <div className="card p-3">
            <div className="text-xs text-ink-soft">موارد با فوریت بالا/بحرانی</div>
            <div className={`text-xl font-black ${criticalCount > 0 ? 'text-red' : 'text-teal'}`}>
              {criticalCount} مورد
            </div>
          </div>
          <div className="card p-3">
            <div className="text-xs text-ink-soft">در انتظار اقدام ناظر</div>
            <div className="text-xl font-black text-gold">{pendingCount} مورد</div>
          </div>
          <div className="card p-3">
            <div className="text-xs text-ink-soft">بررسی و مختومه‌شده</div>
            <div className="text-xl font-black text-teal">{resolvedCount} مورد</div>
          </div>
        </div>
      )}

      {/* Data Table: Shown only when no error */}
      {!error && (
        <>
          <FilterBar>
            <FilterInput
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="جستجو در متقاضی یا نوع رویداد..."
            />
            <FilterSelect
              value={severityFilter}
              onChange={(e) => setSeverityFilter(e.target.value)}
            >
              <option value="">همه سطوح خطر</option>
              <option value="low">کم (Low)</option>
              <option value="medium">متوسط (Medium)</option>
              <option value="high">بالا (High)</option>
              <option value="critical">بحرانی (Critical)</option>
            </FilterSelect>
          </FilterBar>

          <div className="card">
            <DataTable
              columns={columns}
              data={filteredItems}
              loading={loading}
              emptyTitle="هیچ هشدار تقلبی ثبت نشده است. سلامت جلسه تأیید است."
            />
          </div>
        </>
      )}
    </>
  );
}
