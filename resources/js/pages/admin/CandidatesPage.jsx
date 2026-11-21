import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  FilterBar,
  FilterInput,
  FilterSelect,
  Modal,
  PageHeader,
  Pagination,
} from '../../components/ui/index.js';
import CandidateFormModal from '../../components/candidates/CandidateFormModal.jsx';
import CandidateBulkGenerateModal from '../../components/candidates/CandidateBulkGenerateModal.jsx';
import useCandidates from '../../hooks/useCandidates.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { notifyError, notifySuccess } from '../../lib/notify.js';

// نگاشت وضعیت‌های واقعی جدول candidates (Attendance)
const STATUS_CONFIG = {
  present: { label: 'حاضر', tone: 'teal' },
  absent: { label: 'غایب', tone: 'bad' },
  late: { label: 'با تأخیر', tone: 'pending' },
  disqualified: { label: 'محروم', tone: 'bad' },
};

export default function CandidatesPage() {
  const { can, hasRole } = useAuth();
  const canManage = can('candidates.create') || hasRole('Super Admin', 'Exam Administrator', 'Lab Manager');
  const canCheckIn = can('candidates.update') || hasRole('Super Admin', 'Exam Administrator', 'Invigilator');

  const [filters, setFilters] = useState({ search: '', status: '' });
  const [page, setPage] = useState(1);

  const { items, pagination, loading, reload, create, update, remove, bulkGenerate, checkIn, getEntryCard } =
    useCandidates({
      ...filters,
      page,
      per_page: 20,
    });

  const [formOpen, setFormOpen] = useState(false);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [editingCandidate, setEditingCandidate] = useState(null);
  const [deletingCandidate, setDeletingCandidate] = useState(null);
  const [entryCardData, setEntryCardData] = useState(null);
  const [cardLoadingId, setCardLoadingId] = useState(null);
  const [busy, setBusy] = useState(false);

  // دریافت کارت ورود متقاضی از Endpoint واقعی
  const handleViewEntryCard = async (candidate) => {
    setCardLoadingId(candidate.id);
    try {
      const res = await getEntryCard(candidate.id);
      if (res) {
        setEntryCardData(res);
      }
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'امکان دریافت کارت ورود وجود ندارد.';
      notifyError(msg);
    } finally {
      setCardLoadingId(null);
    }
  };

  // ثبت حضور متقاضی (Check-in)
  const handleCheckIn = async (candidate) => {
    try {
      await checkIn(candidate.id);
      notifySuccess(`حضور متقاضی «${candidate.name}» با موفقیت تأیید شد.`);
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت Check-in.';
      notifyError(msg);
    }
  };

  // ترتیب ستون‌ها دقیقاً مطابق تقاضا: "نام | یوزرنیم | آزمون | شیفت | وضعیت"
  const columns = useMemo(
    () => [
      {
        key: 'name',
        title: 'نام',
        render: (_, row) => (
          <div>
            <div className="font-bold text-navy">{row.name || 'بدون نام'}</div>
            <div className="text-xs text-ink-soft font-mono">
              کد: {row.candidate_code} {row.national_id ? `· تذکره: ${row.national_id}` : ''}
            </div>
          </div>
        ),
      },
      {
        key: 'username',
        title: 'یوزرنیم',
        render: (un) => <span className="font-mono text-xs text-navy font-semibold">{un}</span>,
      },
      {
        key: 'exam',
        title: 'آزمون',
        render: (_, row) => {
          const assignment = row.latest_assignment || row.latestAssignment;
          const examTitle = assignment?.exam?.title;
          return (
            <span className="text-xs font-medium text-ink">
              {examTitle || '—'}
            </span>
          );
        },
      },
      {
        key: 'shift',
        title: 'شیفت',
        render: (_, row) => {
          const assignment = row.latest_assignment || row.latestAssignment;
          const shiftName = assignment?.shift?.name;
          return (
            <span className="text-xs text-ink-soft">
              {shiftName || '—'}
            </span>
          );
        },
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
        title: 'عملیات',
        align: 'end',
        render: (_, row) => {
          const isCardBusy = cardLoadingId === row.id;
          return (
            <div className="flex items-center justify-end gap-1.5 flex-wrap">
              {/* دکمه مشاهده و چاپ کارت ورود */}
              <Button
                size="sm"
                variant="outline"
                loading={isCardBusy}
                onClick={() => handleViewEntryCard(row)}
                title="مشاهده کارت ورود"
              >
                کارت ورود
              </Button>

              {/* دکمه Check-in برای متقاضیان غایب */}
              {canCheckIn && row.status === 'absent' && (
                <Button
                  size="sm"
                  variant="teal"
                  onClick={() => handleCheckIn(row)}
                >
                  ✓ Check-in
                </Button>
              )}

              {canManage && (
                <>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      setEditingCandidate(row);
                      setFormOpen(true);
                    }}
                  >
                    ویرایش
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    className="text-red hover:bg-red/10"
                    onClick={() => setDeletingCandidate(row)}
                  >
                    حذف
                  </Button>
                </>
              )}
            </div>
          );
        },
      },
    ],
    [canManage, canCheckIn, cardLoadingId]
  );

  const handleSave = async (formData) => {
    setBusy(true);
    try {
      if (editingCandidate) {
        await update(editingCandidate.id, formData);
        notifySuccess('اطلاعات متقاضی با موفقیت به‌روزرسانی شد.');
        setEditingCandidate(null);
        reload();
        return null;
      } else {
        const created = await create(formData);
        notifySuccess('متقاضی جدید با موفقیت ایجاد شد.');
        reload();
        return created;
      }
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingCandidate) return;
    setBusy(true);
    try {
      await remove(deletingCandidate.id);
      notifySuccess('متقاضی با موفقیت حذف شد.');
      setDeletingCandidate(null);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'امکان حذف این متقاضی وجود ندارد.';
      notifyError(msg);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        title="متقاضیان"
        crumb={['پوهنتون کندز', 'متقاضیان']}
        actions={
          canManage && (
            <div className="flex gap-2">
              <Button variant="outline" onClick={() => setBulkOpen(true)}>
                + تولید گروهی
              </Button>
              <Button
                variant="gold"
                onClick={() => {
                  setEditingCandidate(null);
                  setFormOpen(true);
                }}
              >
                + افزودن متقاضی
              </Button>
            </div>
          )
        }
      />

      <FilterBar>
        <FilterInput
          value={filters.search}
          onChange={(e) => {
            setFilters((p) => ({ ...p, search: e.target.value }));
            setPage(1);
          }}
          placeholder="جستجو در نام، کد متقاضی یا یوزرنیم..."
        />
        <FilterSelect
          value={filters.status}
          onChange={(e) => {
            setFilters((p) => ({ ...p, status: e.target.value }));
            setPage(1);
          }}
        >
          <option value="">وضعیت: همه</option>
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
          emptyTitle="هیچ متقاضی‌ای با این مشخصات یافت نشد."
        />
        {pagination && pagination.lastPage > 1 && (
          <div className="mt-4">
            <Pagination
              currentPage={pagination.currentPage}
              lastPage={pagination.lastPage}
              onPageChange={setPage}
            />
          </div>
        )}
      </div>

      {/* مودال افزودن / ویرایش متقاضی */}
      <CandidateFormModal
        open={formOpen}
        onClose={() => {
          setFormOpen(false);
          setEditingCandidate(null);
        }}
        onSave={handleSave}
        candidate={editingCandidate}
        busy={busy}
      />

      {/* مودال تولید گروهی حساب‌ها */}
      <CandidateBulkGenerateModal
        open={bulkOpen}
        onClose={() => setBulkOpen(false)}
        onGenerate={bulkGenerate}
        busy={busy}
      />

      {/* مودال نمایش کارت ورود متقاضی بر اساس پاسخ واقعی سرور */}
      {entryCardData && (
        <Modal
          open={!!entryCardData}
          onClose={() => setEntryCardData(null)}
          title="کارت ورود به جلسه آزمون"
          size="md"
        >
          <div className="space-y-4">
            <div className="border-2 border-navy rounded-2xl p-4 bg-panel shadow-sm flex gap-4 text-xs">
              {/* شبیه‌ساز امن QR Token */}
              <div className="size-24 bg-paper border border-line rounded-xl p-2 flex flex-col items-center justify-center shrink-0 text-center">
                <span className="text-xl">🔲</span>
                <span className="font-mono text-[9px] text-ink-soft truncate w-20 mt-1">
                  {entryCardData.qr_token?.slice(0, 8)}...
                </span>
              </div>

              <div className="flex-1 space-y-1.5 leading-relaxed">
                <div className="text-[10px] text-ink-soft">پوهنتون کندز — معاونیت امور علمی</div>
                <div className="font-extrabold text-navy text-sm">کارت ورود به جلسه آزمون</div>
                <div>
                  نام متقاضی: <b className="text-navy">{entryCardData.candidate?.name}</b>
                </div>
                <div className="font-mono text-ink-soft">
                  کد متقاضی: <b className="text-navy">{entryCardData.candidate?.candidate_code}</b>
                </div>
                <div className="text-xs text-ink-soft">
                  آزمون: <b className="text-navy">{entryCardData.exam || '—'}</b>
                </div>
                <div className="text-xs text-ink-soft">
                  شیفت: <b className="text-navy">{entryCardData.shift || '—'}</b>
                </div>
                <div className="text-[11px] text-ink-soft">
                  محل: {entryCardData.lab || 'لابراتوار'} · سیستم: {entryCardData.computer || 'صندلی اختصاصی'}
                </div>
              </div>
            </div>

            <div className="flex justify-between items-center pt-3 border-t border-line">
              <Button variant="gold" size="sm" onClick={() => window.print()}>
                🖨️ چاپ کارت
              </Button>
              <Button variant="outline" size="sm" onClick={() => setEntryCardData(null)}>
                بستن
              </Button>
            </div>
          </div>
        </Modal>
      )}

      {/* دیالوگ تأیید حذف */}
      <ConfirmDialog
        open={!!deletingCandidate}
        onClose={() => setDeletingCandidate(null)}
        onConfirm={handleDelete}
        title="حذف متقاضی"
        message={`آیا از حذف متقاضی «${deletingCandidate?.name || deletingCandidate?.candidate_code}» اطمینان دارید؟`}
        confirmTone="danger"
        confirmLabel="حذف"
        busy={busy}
      />
    </>
  );
}
