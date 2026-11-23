import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
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
import CandidateAssignModal from '../../components/candidates/CandidateAssignModal.jsx';
import CandidateDetailModal from '../../components/candidates/CandidateDetailModal.jsx';
import QRCodeImage from '../../components/ui/QRCodeImage.jsx';
import useCandidates from '../../hooks/useCandidates.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { api, unwrapList } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

// نگاشت وضعیت‌های جامع جدول candidates
const STATUS_CONFIG = {
  present: { label: 'حاضر (تأیید ورود)', tone: 'teal' },
  absent: { label: 'غایب / شروع‌نکرده', tone: 'bad' },
  late: { label: 'با تأخیر', tone: 'pending' },
  in_progress: { label: 'در حال امتحان', tone: 'gold' },
  completed: { label: 'تکمیل‌شده', tone: 'teal' },
  disqualified: { label: 'محروم از آزمون', tone: 'bad' },
  cancelled: { label: 'لغوشده', tone: 'off' },
};

export default function CandidatesPage({ action = null }) {
  const { id: routeId } = useParams();
  const navigate = useNavigate();
  const { can, hasRole } = useAuth();

  const canManage = can('candidates.create') || hasRole('Super Admin', 'Exam Administrator', 'Lab Manager');
  const canUpdate = can('candidates.update') || hasRole('Super Admin', 'Exam Administrator', 'Invigilator', 'Lab Manager');
  const canDelete = can('candidates.delete') || hasRole('Super Admin', 'Exam Administrator');

  const [filters, setFilters] = useState({ search: '', status: '', exam_id: '' });
  const [page, setPage] = useState(1);
  const [examsList, setExamsList] = useState([]);

  // دریافت آرام و ایمن لیست آزمون‌ها برای فیلتر کشویی (بدون توست خطای ۴۰۳ در صورت عدم دسترسی)
  useEffect(() => {
    let active = true;
    api
      .get('/api/v1/admin/exams', { params: { per_page: 100 }, skipErrorToast: true })
      .then((res) => {
        if (!active) return;
        const unwrapped = unwrapList(res.data);
        setExamsList(unwrapped.items || []);
      })
      .catch(() => {
        if (active) setExamsList([]);
      });
    return () => {
      active = false;
    };
  }, []);

  const {
    items,
    pagination,
    stats,
    loading,
    error,
    reload,
    get: getCandidateDetail,
    create,
    update,
    toggleStatus,
    remove,
    bulkGenerate,
    checkIn,
    getEntryCard,
  } = useCandidates({
    ...filters,
    page,
    per_page: 20,
  });

  const [formOpen, setFormOpen] = useState(false);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);
  const [assignCandidate, setAssignCandidate] = useState(null);
  const [unassignModalOpen, setUnassignModalOpen] = useState(false);
  const [unassignCandidate, setUnassignCandidate] = useState(null);
  const [unassignReason, setUnassignReason] = useState('');
  const [unassignBusy, setUnassignBusy] = useState(false);
  const [detailOpen, setDetailOpen] = useState(false);
  const [selectedCandidate, setSelectedCandidate] = useState(null);
  const [editingCandidate, setEditingCandidate] = useState(null);
  const [deletingCandidate, setDeletingCandidate] = useState(null);
  const [entryCardData, setEntryCardData] = useState(null);
  const [cardLoadingId, setCardLoadingId] = useState(null);
  const [statusTogglingId, setStatusTogglingId] = useState(null);
  const [busy, setBusy] = useState(false);

  // بررسی روت‌های فرعی درخواستی (/admin/candidates/create, /admin/candidates/:id, /admin/candidates/:id/edit)
  useEffect(() => {
    if (action === 'create' && canManage) {
      setEditingCandidate(null);
      setFormOpen(true);
    } else if (action === 'edit' && routeId && canUpdate) {
      getCandidateDetail(routeId)
        .then((cand) => {
          if (cand) {
            setEditingCandidate(cand);
            setFormOpen(true);
          }
        })
        .catch(() => {
          notifyError('اطلاعات متقاضی برای ویرایش یافت نشد.');
          navigate('/admin/candidates', { replace: true });
        });
    } else if (action === 'view' && routeId) {
      getCandidateDetail(routeId)
        .then((cand) => {
          if (cand) {
            setSelectedCandidate(cand);
            setDetailOpen(true);
          }
        })
        .catch(() => {
          notifyError('اطلاعات متقاضی یافت نشد.');
          navigate('/admin/candidates', { replace: true });
        });
    }
  }, [action, routeId, canManage, canUpdate]);

  // دریافت و نمایش کارت ورود متقاضی
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

  // تأیید حضور (Check-in) سریع
  const handleCheckIn = async (candidate) => {
    try {
      await checkIn(candidate.id);
      notifySuccess(`حضور متقاضی «${candidate.name}» با موفقیت تأیید شد.`);
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت Check-in.';
      notifyError(msg);
    }
  };

  // تغییر وضعیت سریع (فعال / غیرفعال / محروم)
  const handleQuickToggleStatus = async (candidate) => {
    setStatusTogglingId(candidate.id);
    const nextStatus = candidate.status === 'disqualified' ? 'present' : 'disqualified';
    try {
      await toggleStatus(candidate.id, nextStatus);
      notifySuccess(
        nextStatus === 'disqualified'
          ? `متقاضی «${candidate.name}» به وضعیت محروم تغییر یافت.`
          : `متقاضی «${candidate.name}» به وضعیت فعال/حاضر بازگردانده شد.`
      );
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در تغییر وضعیت متقاضی.';
      notifyError(msg);
    } finally {
      setStatusTogglingId(null);
    }
  };

  // لغو تخصیص متقاضی (Unassign)
  const handleOpenUnassign = (candidate) => {
    setUnassignCandidate(candidate);
    setUnassignReason('');
    setUnassignModalOpen(true);
  };

  const handleConfirmUnassign = async () => {
    if (!unassignCandidate) return;
    setUnassignBusy(true);
    try {
      const res = await api.post(`/api/v1/admin/candidates/${unassignCandidate.id}/unassign`, {
        reason: unassignReason || 'لغو تخصیص توسط ناظر/مدیر سیستم',
      });
      notifySuccess(res.data?.message || 'تخصیص متقاضی با موفقیت لغو شد.');
      setUnassignModalOpen(false);
      setUnassignCandidate(null);
      reload();
    } catch (err) {
      notifyError(err.response?.data?.message || err.apiMessage || 'خطا در لغو تخصیص متقاضی.');
    } finally {
      setUnassignBusy(false);
    }
  };

  // باز کردن مودال جزئیات کامل
  const handleOpenDetail = async (candidate) => {
    try {
      const detailed = await getCandidateDetail(candidate.id);
      setSelectedCandidate(detailed || candidate);
    } catch {
      setSelectedCandidate(candidate);
    }
    setDetailOpen(true);
  };

  // ستون‌های جدول: تمامی توابع render به صورت صریح با ۲ آرگومان (_, row) تعریف شده‌اند تا از پاس داده شدن اشتباه آبجکت به JSX در DataTable جلوگیری شود
  const columns = useMemo(
    () => [
      {
        key: 'name',
        title: 'نام و مشخصات کامل',
        render: (_, row) => (
          <div>
            <div
              className="font-bold text-navy hover:text-gold cursor-pointer transition-colors"
              onClick={() => handleOpenDetail(row)}
              title="برای مشاهده جزئیات پرونده کلیک کنید"
            >
              {row.name || 'بدون نام'}
            </div>
            <div className="text-[11px] text-ink-soft font-mono flex items-center gap-1.5 mt-0.5">
              <span>کد: <b className="text-navy">{row.candidate_code}</b></span>
              {row.national_id ? <span>· تذکره: {row.national_id}</span> : null}
            </div>
          </div>
        ),
      },
      {
        key: 'username',
        title: 'نام کاربری',
        render: (_, row) => (
          <span className="font-mono text-xs text-navy font-semibold bg-panel-2 px-2 py-0.5 rounded border border-line">
            {row.username || '—'}
          </span>
        ),
      },
      {
        key: 'exam',
        title: 'آزمون تخصیص‌یافته',
        render: (_, row) => {
          const assignment = row.latest_assignment || row.latestAssignment;
          const examTitle = assignment?.exam?.title;
          return (
            <div>
              <div className="text-xs font-semibold text-ink line-clamp-1">
                {examTitle || 'تخصیص نیافته'}
              </div>
              {assignment?.shift?.name ? (
                <div className="text-[11px] text-ink-soft mt-0.5">
                  شیفت: {assignment.shift.name}
                </div>
              ) : null}
            </div>
          );
        },
      },
      {
        key: 'status',
        title: 'وضعیت',
        render: (_, row) => {
          const cfg = STATUS_CONFIG[row.status] || { label: row.status || 'نامشخص', tone: 'off' };
          return <Badge tone={cfg.tone}>{cfg.label}</Badge>;
        },
      },
      {
        key: 'actions',
        title: 'عملیات',
        align: 'end',
        actions: true,
        render: (_, row) => {
          const isCardBusy = cardLoadingId === row.id;
          const isStatusBusy = statusTogglingId === row.id;
          const isDisqualified = row.status === 'disqualified';

          return (
            <div className="flex items-center justify-end gap-1.5 flex-wrap">
              {/* مشاهده جزئیات پرونده */}
              <Button
                size="sm"
                variant="outline"
                onClick={() => handleOpenDetail(row)}
                title="مشاهده جزئیات پرونده"
              >
                جزئیات
              </Button>

              {/* تخصیص آزمون و صندلی */}
              {canUpdate && (
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    setAssignCandidate(row);
                    setAssignOpen(true);
                  }}
                  title="تخصیص به آزمون، شیفت و صندلی"
                >
                  تخصیص
                </Button>
              )}

              {/* لغو تخصیص آزمون و صندلی */}
              {canUpdate && (row.latest_assignment || row.latestAssignment) && (
                <Button
                  size="sm"
                  variant="ghost"
                  className="text-red hover:bg-red/10"
                  onClick={() => handleOpenUnassign(row)}
                  title="لغو تخصیص آزمون و آزادسازی صندلی"
                >
                  لغو تخصیص
                </Button>
              )}

              {/* کارت ورود */}
              <Button
                size="sm"
                variant="outline"
                loading={isCardBusy}
                onClick={() => handleViewEntryCard(row)}
                title="مشاهده و چاپ کارت ورود"
              >
                کارت ورود
              </Button>

              {/* Check-in سریع اگر غایب باشد */}
              {canUpdate && row.status === 'absent' && (
                <Button
                  size="sm"
                  variant="teal"
                  onClick={() => handleCheckIn(row)}
                  title="تأیید ورود به جلسه"
                >
                  ✓ Check-in
                </Button>
              )}

              {/* فعال / غیرفعال‌سازی (محروم) */}
              {canUpdate && (
                <Button
                  size="sm"
                  variant="ghost"
                  loading={isStatusBusy}
                  className={isDisqualified ? 'text-teal hover:bg-teal/10' : 'text-amber hover:bg-amber/10'}
                  onClick={() => handleQuickToggleStatus(row)}
                  title={isDisqualified ? 'رفع محرومیت و فعال‌سازی' : 'محروم‌سازی موقت'}
                >
                  {isDisqualified ? 'فعال‌سازی' : 'محروم‌سازی'}
                </Button>
              )}

              {/* ویرایش */}
              {canUpdate && (
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    setEditingCandidate(row);
                    setFormOpen(true);
                  }}
                  title="ویرایش اطلاعات"
                >
                  ویرایش
                </Button>
              )}

              {/* حذف با بررسی محدودیت */}
              {canDelete && (
                <Button
                  size="sm"
                  variant="ghost"
                  className="text-red hover:bg-red/10"
                  onClick={() => setDeletingCandidate(row)}
                  title="حذف متقاضی"
                >
                  حذف
                </Button>
              )}
            </div>
          );
        },
      },
    ],
    [canUpdate, canDelete, cardLoadingId, statusTogglingId]
  );

  const handleSave = async (formData) => {
    setBusy(true);
    try {
      if (editingCandidate) {
        await update(editingCandidate.id, formData);
        notifySuccess('اطلاعات متقاضی با موفقیت به‌روزرسانی شد.');
        setEditingCandidate(null);
        if (action) navigate('/admin/candidates', { replace: true });
        reload();
        return null;
      } else {
        const created = await create(formData);
        notifySuccess('متقاضی جدید با موفقیت ایجاد شد.');
        if (action) navigate('/admin/candidates', { replace: true });
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

  // محاسبه آمار واقعی زنده از دیتابیس
  const totalCount = stats?.total ?? pagination?.total ?? items.length;
  const presentCount = stats?.present ?? items.filter((c) => c.status === 'present').length;
  const inProgressCount = stats?.in_progress ?? items.filter((c) => c.status === 'in_progress').length;
  const absentCount = stats?.absent ?? items.filter((c) => c.status === 'absent').length;

  return (
    <div className="space-y-4 font-vazir">
      <PageHeader
        title="همه متقاضیان"
        crumb={['پوهنتون کندز', 'متقاضیان', 'همه متقاضیان']}
        actions={
          <div className="flex items-center gap-2 flex-wrap">
            <Link to="/admin/candidates/import">
              <Button variant="outline" size="sm">
                📥 ورود از اکسل
              </Button>
            </Link>
            <Link to="/admin/candidates/cards">
              <Button variant="outline" size="sm">
                🪪 کارت‌های ورود
              </Button>
            </Link>
            <Link to="/admin/candidates/checkin">
              <Button variant="outline" size="sm">
                📋 Check-in و حضور
              </Button>
            </Link>
            {canManage && (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setAssignCandidate(null);
                    setAssignOpen(true);
                  }}
                  title="تخصیص متقاضی به آزمون و صندلی"
                >
                  🎯 تخصیص آزمون
                </Button>
                <Button variant="outline" size="sm" onClick={() => setBulkOpen(true)}>
                  ⚡ تولید گروهی
                </Button>
                <Button
                  variant="gold"
                  size="sm"
                  onClick={() => {
                    setEditingCandidate(null);
                    setFormOpen(true);
                  }}
                >
                  + افزودن متقاضی
                </Button>
              </>
            )}
          </div>
        }
      />

      {/* ۴ کارت خلاصه وضعیت واقعی بر اساس دیتابیس بدون هیچ Mock */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div className="card p-3.5 border-r-4 border-r-navy">
          <div className="text-xs text-ink-soft">مجموع کل متقاضیان</div>
          <div className="text-2xl font-black text-navy mt-1 font-mono">{totalCount} نفر</div>
        </div>

        <div className="card p-3.5 border-r-4 border-r-teal">
          <div className="text-xs text-ink-soft">حاضر / تأیید ورود</div>
          <div className="text-2xl font-black text-teal mt-1 font-mono">{presentCount} نفر</div>
        </div>

        <div className="card p-3.5 border-r-4 border-r-gold">
          <div className="text-xs text-ink-soft">در حال برگزاری امتحان</div>
          <div className="text-2xl font-black text-gold mt-1 font-mono">{inProgressCount} نفر</div>
        </div>

        <div className="card p-3.5 border-r-4 border-r-red">
          <div className="text-xs text-ink-soft">غایب / شروع‌نکرده</div>
          <div className="text-2xl font-black text-red mt-1 font-mono">{absentCount} نفر</div>
        </div>
      </div>

      {/* نوار فیلتر و جستجوی چندگانه */}
      <FilterBar>
        <FilterInput
          value={filters.search}
          onChange={(e) => {
            setFilters((p) => ({ ...p, search: e.target.value }));
            setPage(1);
          }}
          placeholder="جستجو در نام، کد متقاضی یا نام کاربری..."
          className="flex-1 min-w-[220px]"
        />

        <FilterSelect
          value={filters.status}
          onChange={(e) => {
            setFilters((p) => ({ ...p, status: e.target.value }));
            setPage(1);
          }}
          className="min-w-[150px]"
        >
          <option value="">وضعیت: همه</option>
          {Object.entries(STATUS_CONFIG).map(([k, v]) => (
            <option key={k} value={k}>
              {v.label}
            </option>
          ))}
        </FilterSelect>

        <FilterSelect
          value={filters.exam_id}
          onChange={(e) => {
            setFilters((p) => ({ ...p, exam_id: e.target.value }));
            setPage(1);
          }}
          className="min-w-[180px]"
        >
          <option value="">آزمون: همه آزمون‌ها</option>
          {(examsList || []).map((ex) => (
            <option key={ex.id} value={ex.id}>
              {ex.title}
            </option>
          ))}
        </FilterSelect>

        {(filters.search || filters.status || filters.exam_id) && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              setFilters({ search: '', status: '', exam_id: '' });
              setPage(1);
            }}
          >
            ✕ پاکسازی فیلترها
          </Button>
        )}
      </FilterBar>

      {/* جدول داده‌ها و صفحه‌بندی */}
      <div className="card">
        <DataTable
          columns={columns}
          data={items}
          loading={loading}
          error={error}
          onRetry={reload}
          emptyTitle="هیچ متقاضی‌ای با این مشخصات یافت نشد."
          emptyDescription="می‌توانید با دکمه‌های بالای صفحه متقاضی جدید ایجاد کرده یا از اکسل وارد نمایید."
        />
        {pagination && pagination.lastPage > 1 && (
          <div className="p-3 border-t border-line flex justify-center">
            <Pagination
              currentPage={pagination.currentPage}
              lastPage={pagination.lastPage}
              onPageChange={setPage}
            />
          </div>
        )}
      </div>

      {/* مودال مشاهده جزئیات پرونده متقاضی */}
      <CandidateDetailModal
        open={detailOpen}
        onClose={() => {
          setDetailOpen(false);
          setSelectedCandidate(null);
          if (action === 'view') navigate('/admin/candidates', { replace: true });
        }}
        candidate={selectedCandidate}
        onOpenCard={(cand) => handleViewEntryCard(cand)}
        onAssign={(cand) => {
          setAssignCandidate(cand);
          setAssignOpen(true);
        }}
        onEdit={(cand) => {
          setEditingCandidate(cand);
          setFormOpen(true);
        }}
      />

      {/* مودال افزودن / ویرایش متقاضی */}
      <CandidateFormModal
        open={formOpen}
        onClose={() => {
          setFormOpen(false);
          setEditingCandidate(null);
          if (action === 'create' || action === 'edit') navigate('/admin/candidates', { replace: true });
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

      {/* مودال تخصیص آزمون و صندلی */}
      <CandidateAssignModal
        open={assignOpen}
        onClose={() => {
          setAssignOpen(false);
          setAssignCandidate(null);
        }}
        candidate={assignCandidate}
        allCandidates={items}
        onAssigned={() => reload()}
      />

      {/* مودال کارت ورود با قابلیت چاپ */}
      {entryCardData && (
        <Modal
          open={!!entryCardData}
          onClose={() => setEntryCardData(null)}
          title="کارت ورود به جلسه آزمون"
          size="md"
        >
          <div className="space-y-4 font-vazir">
            <div className="border-2 border-navy rounded-2xl p-4 bg-panel shadow-sm flex gap-4 text-xs">
              {/* تصویر QR Code واقعی و استاندارد */}
              <div className="shrink-0 flex flex-col items-center">
                <QRCodeImage
                  value={entryCardData.qr_token || `AZMOON:${entryCardData.candidate?.candidate_code}`}
                  size={96}
                  alt={`QR Code ${entryCardData.candidate?.candidate_code}`}
                />
                <span className="font-mono text-[9px] text-ink-soft truncate w-20 mt-1 text-center">
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
                  کد داوطلبی: <b className="text-navy font-bold">{entryCardData.candidate?.candidate_code}</b>
                </div>
                <div className="text-xs text-ink-soft">
                  آزمون: <b className="text-navy">{entryCardData.exam || '—'}</b>
                </div>
                <div className="text-xs text-ink-soft">
                  شیفت: <b className="text-navy">{entryCardData.shift || '—'}</b>
                </div>
                <div className="text-[11px] text-ink-soft">
                  محل: {entryCardData.lab || 'لابراتوار مرکزی'} · سیستم: {entryCardData.computer || 'صندلی اختصاصی'}
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

      {/* مودال تأیید لغو تخصیص متقاضی */}
      <Modal
        open={unassignModalOpen}
        onClose={() => !unassignBusy && setUnassignModalOpen(false)}
        title="تأیید لغو تخصیص آزمون متقاضی"
        size="sm"
      >
        <div className="space-y-4 font-vazir text-ink">
          <div className="p-3 bg-red/10 border border-red/20 rounded-xl text-xs text-red leading-relaxed">
            ⚠️ <b>توجه:</b> با لغو تخصیص، صندلی/کامپیوتر متقاضی آزاد شده، کارت ورود قبلی باطل گردیده و وضعیت حضور ریست خواهد شد.
          </div>

          <div className="text-sm">
            آیا از لغو تخصیص متقاضی <b className="text-navy">{unassignCandidate?.name}</b> (کد: <span className="font-mono">{unassignCandidate?.candidate_code}</span>) اطمینان دارید؟
          </div>

          <div className="space-y-1">
            <label className="text-xs font-semibold text-ink-soft">دلیل لغو تخصیص (اختیاری):</label>
            <input
              type="text"
              className="w-full text-xs p-2 border border-line rounded-lg focus:border-navy focus:outline-none"
              placeholder="مثال: انصراف داوطلب یا تغییر نوبت آزمون..."
              value={unassignReason}
              onChange={(e) => setUnassignReason(e.target.value)}
              disabled={unassignBusy}
            />
          </div>

          <div className="flex justify-end gap-2 pt-3 border-t border-line">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setUnassignModalOpen(false)}
              disabled={unassignBusy}
            >
              انصراف
            </Button>
            <Button
              variant="primary"
              size="sm"
              className="bg-red hover:bg-red/90 text-panel border-red"
              onClick={handleConfirmUnassign}
              loading={unassignBusy}
            >
              تأیید و لغو تخصیص
            </Button>
          </div>
        </div>
      </Modal>

      {/* دیالوگ تأیید حذف با هندلینگ خطا */}
      <ConfirmDialog
        open={!!deletingCandidate}
        onClose={() => setDeletingCandidate(null)}
        onConfirm={handleDelete}
        title="حذف متقاضی"
        message={`آیا از حذف متقاضی «${deletingCandidate?.name || deletingCandidate?.candidate_code}» اطمینان دارید؟ در صورتی که متقاضی در حال امتحان باشد، حذف مسدود خواهد شد.`}
        confirmTone="danger"
        confirmLabel="حذف متقاضی"
        busy={busy}
      />
    </div>
  );
}
