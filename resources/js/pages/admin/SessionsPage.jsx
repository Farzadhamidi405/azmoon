import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  DataTable,
  FilterBar,
  FilterInput,
  FilterSelect,
  PageHeader,
  Pagination,
} from '../../components/ui/index.js';
import SessionTransferModal from '../../components/sessions/SessionTransferModal.jsx';
import IncidentModal from '../../components/sessions/IncidentModal.jsx';
import ManualOverrideModal from '../../components/sessions/ManualOverrideModal.jsx';
import useSessions from '../../hooks/useSessions.js';
import useIncidents from '../../hooks/useIncidents.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { notifySuccess } from '../../lib/notify.js';

const STATUS_CONFIG = {
  in_progress: { label: 'در حال آزمون', tone: 'on' },
  paused: { label: 'متوقف شده', tone: 'pending' },
  completed: { label: 'تکمیل‌شده', tone: 'teal' },
  auto_submitted: { label: 'ثبت خودکار', tone: 'gold' },
  disqualified: { label: 'ابطال/محروم', tone: 'bad' },
  disconnected: { label: 'قطع ارتباط', tone: 'bad' },
};

export default function SessionsPage() {
  const { can, hasRole } = useAuth();
  const canManage = can('sessions.manage') || hasRole('Super Admin', 'Exam Administrator', 'Proctor', 'Lab Manager');

  const [filters, setFilters] = useState({ status: '', search: '' });
  const [page, setPage] = useState(1);
  const { items, pagination, loading, reload, transferSession, manualOverride } = useSessions({
    ...filters,
    page,
    per_page: 25,
  });
  const { report: reportIncident } = useIncidents();

  const [activeAttempt, setActiveAttempt] = useState(null);
  const [transferOpen, setTransferOpen] = useState(false);
  const [incidentOpen, setIncidentOpen] = useState(false);
  const [overrideOpen, setOverrideOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  // Stats calculation
  const inProgressCount = items.filter((i) => i.status === 'in_progress').length;
  const completedCount = items.filter((i) => i.status === 'completed' || i.status === 'auto_submitted').length;

  const columns = useMemo(
    () => [
      {
        key: 'candidate',
        title: 'متقاضی / سیستم',
        render: (_, row) => (
          <div>
            <div className="font-bold text-navy">{row.candidate?.name || 'متقاضی'}</div>
            <div className="text-xs text-ink-soft font-mono">
              کد: {row.candidate?.candidate_code || '—'} · سیستم: {row.computer?.computer_code || 'نامشخص'}
            </div>
          </div>
        ),
      },
      {
        key: 'exam',
        title: 'آزمون',
        render: (_, row) => (
          <div>
            <div className="font-medium text-xs">{row.exam?.title || 'آزمون جاری'}</div>
            <div className="text-[11px] text-ink-soft">
              تلاش #{row.id}
            </div>
          </div>
        ),
      },
      {
        key: 'status',
        title: 'وضعیت',
        render: (st) => {
          const cfg = STATUS_CONFIG[st] || { label: st, tone: 'off' };
          return <Badge tone={cfg.tone}>{cfg.label}</Badge>;
        },
      },
      {
        key: 'actions',
        title: 'عملیات نظارتی',
        align: 'end',
        render: (_, row) => (
          <div className="flex items-center justify-end gap-1 flex-wrap">
            {canManage && (
              <>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    setActiveAttempt(row);
                    setTransferOpen(true);
                  }}
                >
                  انتقال سیستم
                </Button>

                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    setActiveAttempt(row);
                    setOverrideOpen(true);
                  }}
                >
                  فرمان دستی
                </Button>

                <Button
                  size="sm"
                  variant="ghost"
                  className="text-red hover:bg-red-soft"
                  onClick={() => {
                    setActiveAttempt(row);
                    setIncidentOpen(true);
                  }}
                >
                  ثبت تخلف/حادثه
                </Button>
              </>
            )}
          </div>
        ),
      },
    ],
    [canManage]
  );

  const handleTransfer = async (attemptId, data) => {
    setBusy(true);
    try {
      await transferSession(attemptId, data);
      notifySuccess('نشست متقاضی با موفقیت به سیستم مقصد منتقل شد.');
    } finally {
      setBusy(false);
    }
  };

  const handleOverride = async (attemptId, data) => {
    setBusy(true);
    try {
      await manualOverride(attemptId, data);
      notifySuccess('فرمان دستی ادمین با موفقیت اعمال شد.');
    } finally {
      setBusy(false);
    }
  };

  const handleReport = async (data) => {
    setBusy(true);
    try {
      await reportIncident(data);
      notifySuccess('گزارش حادثه با موفقیت ثبت شد.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        title="جلسات فعال و مانیتورینگ زنده"
        crumb={['نظارت', 'مانیتورینگ زنده']}
        actions={
          <Button variant="outline" onClick={reload}>
            ⟳ رفرش زنده
          </Button>
        }
      />

      {/* KPI Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div className="card p-3 flex items-center justify-between">
          <div>
            <div className="text-xs text-ink-soft">جلسات در حال اجرا</div>
            <div className="text-2xl font-black text-teal">{inProgressCount}</div>
          </div>
          <span className="size-3 rounded-full bg-teal animate-pulse" />
        </div>
        <div className="card p-3">
          <div className="text-xs text-ink-soft">تکمیل‌شده در این شیفت</div>
          <div className="text-2xl font-black text-navy">{completedCount}</div>
        </div>
        <div className="card p-3">
          <div className="text-xs text-ink-soft">مجموع جلسات لیست</div>
          <div className="text-2xl font-black text-ink">{items.length}</div>
        </div>
        <div className="card p-3">
          <div className="text-xs text-ink-soft">وضعیت سرور مرکزی</div>
          <div className="text-sm font-bold text-teal mt-1">● آنلاین و فعال</div>
        </div>
      </div>

      <FilterBar>
        <FilterInput
          value={filters.search}
          onChange={(e) => setFilters((p) => ({ ...p, search: e.target.value }))}
          placeholder="جستجو در متقاضی یا شماره سیستم..."
        />
        <FilterSelect
          value={filters.status}
          onChange={(e) => setFilters((p) => ({ ...p, status: e.target.value }))}
        >
          <option value="">همه وضعیت‌ها</option>
          {Object.entries(STATUS_CONFIG).map(([k, v]) => (
            <option key={k} value={k}>
              {v.label}
            </option>
          ))}
        </FilterSelect>
      </FilterBar>

      <div className="card">
        <DataTable
          columns={columns}
          data={items}
          loading={loading}
          emptyTitle="در حال حاضر هیچ جلسه امتحانی فعالی در این بخش یافت نشد."
        />
        {pagination && (
          <div className="mt-4">
            <Pagination
              currentPage={pagination.currentPage}
              lastPage={pagination.lastPage}
              onPageChange={setPage}
            />
          </div>
        )}
      </div>

      <SessionTransferModal
        open={transferOpen}
        onClose={() => setTransferOpen(false)}
        onTransfer={handleTransfer}
        attempt={activeAttempt}
        busy={busy}
      />

      <ManualOverrideModal
        open={overrideOpen}
        onClose={() => setOverrideOpen(false)}
        onOverride={handleOverride}
        attempt={activeAttempt}
        busy={busy}
      />

      <IncidentModal
        open={incidentOpen}
        onClose={() => setIncidentOpen(false)}
        onReport={handleReport}
        attempt={activeAttempt}
        busy={busy}
      />
    </>
  );
}
