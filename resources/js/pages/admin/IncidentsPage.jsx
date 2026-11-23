import { useMemo, useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  Field,
  FilterBar,
  FilterInput,
  FilterSelect,
  Input,
  Modal,
  PageHeader,
  Pagination,
  Textarea,
} from '../../components/ui/index.js';
import useIncidents from '../../hooks/useIncidents.js';
import useComputers from '../../hooks/useComputers.js';
import { useAuth } from '../../context/AuthContext.jsx';
import formatDate from '../../lib/formatDate.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';
import { getErrorMessage } from '../../lib/api.js';

export default function IncidentsPage() {
  const { user, can, hasRole } = useAuth();
  const canManage = can('incidents.manage') || hasRole('Super Admin', 'Exam Administrator', 'Lab Manager');

  // فیلترها و پیجینیشن
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);

  const { items, pagination, loading, error, reload, report, resolve, update } = useIncidents({
    search: search.trim() || undefined,
    status: statusFilter || undefined,
    page,
    per_page: 20,
  });

  // دریافت لیست کامپیوترها برای سهولت در فرم ثبت حادثه
  const { items: computers } = useComputers({ per_page: 100 });

  // وضعیت‌های مودال
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [resolveTarget, setResolveTarget] = useState(null);
  const [actionModalTarget, setActionModalTarget] = useState(null);
  const [actionText, setActionText] = useState('');
  const [busy, setBusy] = useState(false);
  const [formErrors, setFormErrors] = useState({});

  // فرم ثبت حادثه جدید
  const [newIncident, setNewIncident] = useState({
    candidate_id: '',
    computer_id: '',
    problem_description: '',
    action_taken: '',
  });

  const handleOpenCreate = () => {
    setFormErrors({});
    setNewIncident({
      candidate_id: '',
      computer_id: computers.length > 0 ? String(computers[0].id) : '',
      problem_description: '',
      action_taken: '',
    });
    setCreateModalOpen(true);
  };

  const handleCreateSubmit = async (e) => {
    e.preventDefault();
    setFormErrors({});

    if (!newIncident.problem_description || newIncident.problem_description.trim().length < 10) {
      setFormErrors({ problem_description: 'شرح مشکل الزامی است و باید حداقل ۱۰ کاراکتر باشد.' });
      return;
    }

    if (!newIncident.computer_id && !newIncident.candidate_id) {
      setFormErrors({ computer_id: 'حداقل یکی از متقاضی یا کامپیوتر باید مشخص شود.' });
      return;
    }

    setBusy(true);
    try {
      await report({
        problem_description: newIncident.problem_description.trim(),
        action_taken: newIncident.action_taken ? newIncident.action_taken.trim() : null,
        computer_id: newIncident.computer_id ? Number(newIncident.computer_id) : null,
        candidate_id: newIncident.candidate_id ? Number(newIncident.candidate_id) : null,
        responsible_user_id: user?.id,
        occurred_at: new Date().toISOString(),
      });
      notifySuccess('حادثه با موفقیت در سامانه ثبت شد.');
      setCreateModalOpen(false);
    } catch (err) {
      const msg = getErrorMessage(err, 'خطا در ثبت حادثه.');
      notifyError(msg);
      if (err.response?.data?.errors) {
        setFormErrors(err.response.data.errors);
      }
    } finally {
      setBusy(false);
    }
  };

  const handleConfirmResolve = async () => {
    if (!resolveTarget) return;
    setBusy(true);
    try {
      await resolve(resolveTarget.id);
      notifySuccess(`حادثه #${resolveTarget.id} به عنوان حل‌شده علامت‌گذاری شد.`);
      setResolveTarget(null);
    } catch (err) {
      notifyError(getErrorMessage(err, 'خطا در علامت‌گذاری حادثه.'));
    } finally {
      setBusy(false);
    }
  };

  const handleOpenActionModal = (row) => {
    setActionModalTarget(row);
    setActionText(row.action_taken || '');
  };

  const handleSaveAction = async (e) => {
    e.preventDefault();
    if (!actionModalTarget) return;

    setBusy(true);
    try {
      await update(actionModalTarget.id, {
        action_taken: actionText.trim(),
      });
      notifySuccess('اقدام انجام‌شده با موفقیت به‌روزرسانی شد.');
      setActionModalTarget(null);
    } catch (err) {
      notifyError(getErrorMessage(err, 'خطا در ذخیره اقدام انجام‌شده.'));
    } finally {
      setBusy(false);
    }
  };

  const columns = useMemo(
    () => [
      {
        key: 'id',
        title: '#',
        render: (id) => <span className="font-mono font-bold text-navy text-xs">#{id}</span>,
      },
      {
        key: 'candidate',
        title: 'متقاضی',
        render: (_, row) => (
          <div>
            {row.candidate ? (
              <>
                <div className="font-bold text-navy text-xs">{row.candidate.name}</div>
                <div className="text-[11px] text-ink-soft font-mono">
                  کد: {row.candidate.candidate_code || row.candidate.code || '—'}
                </div>
              </>
            ) : (
              <span className="text-ink-soft text-xs">— (مسئله عمومی سالن)</span>
            )}
          </div>
        ),
      },
      {
        key: 'computer',
        title: 'کامپیوتر / سیستم',
        render: (_, row) => (
          <div>
            {row.computer ? (
              <span className="font-mono text-xs font-semibold text-ink">
                {row.computer.computer_code}
              </span>
            ) : (
              <span className="text-ink-soft text-xs">—</span>
            )}
          </div>
        ),
      },
      {
        key: 'problem',
        title: 'شرح مشکل',
        render: (_, row) => (
          <div className="max-w-[320px]">
            <div className="text-xs font-medium text-ink leading-relaxed">
              {row.problem_description}
            </div>
          </div>
        ),
      },
      {
        key: 'action',
        title: 'اقدام انجام‌شده',
        render: (_, row) => (
          <div className="max-w-[260px]">
            {row.action_taken ? (
              <span className="text-xs text-teal font-medium">{row.action_taken}</span>
            ) : (
              <span className="text-xs text-ink-soft italic">هنوز اقدامی ثبت نشده</span>
            )}
          </div>
        ),
      },
      {
        key: 'responsible_user',
        title: 'مسئول رسیدگی',
        render: (_, row) => (
          <span className="text-xs text-ink-soft">
            {row.responsible_user?.name || row.responsible_user?.username || '—'}
          </span>
        ),
      },
      {
        key: 'occurred_at',
        title: 'زمان وقوع',
        render: (d) => (
          <span className="font-mono text-xs text-ink-soft whitespace-nowrap">
            {d ? formatDate(d) : '—'}
          </span>
        ),
      },
      {
        key: 'status',
        title: 'وضعیت',
        render: (st) => (
          <Badge tone={st === 'resolved' ? 'teal' : 'bad'}>
            {st === 'resolved' ? 'حل‌شده' : 'در انتظار اقدام'}
          </Badge>
        ),
      },
      {
        key: 'actions',
        title: 'عملیات',
        render: (_, row) => (
          <div className="flex items-center gap-1.5 whitespace-nowrap">
            {row.status === 'open' && canManage ? (
              <>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => handleOpenActionModal(row)}
                  title="ویرایش یا ثبت اقدام انجام‌شده"
                >
                  ثبت اقدام
                </Button>
                <Button
                  size="sm"
                  variant="primary"
                  onClick={() => setResolveTarget(row)}
                  title="علامت‌گذاری به عنوان حل‌شده"
                >
                  حل شد ✓
                </Button>
              </>
            ) : (
              <span className="text-[11px] text-teal font-medium">مختومه</span>
            )}
          </div>
        ),
      },
    ],
    [canManage]
  );

  return (
    <div className="space-y-4">
      <PageHeader
        title="مدیریت حوادث امتحان"
        crumb={['جلسات و لابراتوار', 'حوادث']}
        actions={
          <div className="flex gap-2">
            <Button variant="outline" onClick={reload} loading={loading} disabled={loading}>
              ⟳ بروزرسانی
            </Button>
            {canManage && (
              <Button variant="primary" onClick={handleOpenCreate}>
                + ثبت حادثهٔ جدید
              </Button>
            )}
          </div>
        }
      />

      {/* خطای بارگذاری */}
      {error && (
        <Alert
          tone="bad"
          title="خطا در دریافت لیست حوادث"
          action={
            <Button size="sm" variant="outline" onClick={reload}>
              تلاش مجدد
            </Button>
          }
        >
          {getErrorMessage(error, 'امکان دریافت اطلاعات حوادث از سرور وجود ندارد.')}
        </Alert>
      )}

      {/* نوار فیلتر و جستجو */}
      <FilterBar>
        <FilterInput
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          placeholder="جستجو در شرح مشکل، متقاضی یا کامپیوتر..."
        />
        <FilterSelect
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value);
            setPage(1);
          }}
        >
          <option value="">همه وضعیت‌ها</option>
          <option value="open">در انتظار اقدام (Open)</option>
          <option value="resolved">حل‌شده (Resolved)</option>
        </FilterSelect>
      </FilterBar>

      {/* جدول داده‌ها */}
      <div className="card">
        <DataTable
          columns={columns}
          data={items}
          loading={loading}
          emptyTitle="هیچ رخداد یا حادثه‌ای یافت نشد."
        />
        {pagination && pagination.last_page > 1 && (
          <div className="p-4 border-t border-line">
            <Pagination
              currentPage={pagination.current_page}
              totalPages={pagination.last_page}
              onPageChange={setPage}
            />
          </div>
        )}
      </div>

      {/* Modal ثبت حادثه جدید */}
      <Modal
        open={createModalOpen}
        onClose={() => setCreateModalOpen(false)}
        title="ثبت حادثهٔ جدید در جلسه امتحان"
        size="md"
      >
        <form onSubmit={handleCreateSubmit} className="space-y-4 text-xs">
          <Field
            label="کامپیوتر / ایستگاه آزمون"
            error={formErrors.computer_id?.[0] || formErrors.computer_id}
          >
            <select
              value={newIncident.computer_id}
              onChange={(e) => setNewIncident({ ...newIncident, computer_id: e.target.value })}
              className="w-full rounded border border-line p-2 text-xs bg-white"
            >
              <option value="">-- انتخاب سیستم (اختیاری اگر متقاضی مشخص است) --</option>
              {computers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.computer_code} ({c.lab?.name || 'لابراتوار'} - IP: {c.ip_address || '—'})
                </option>
              ))}
            </select>
          </Field>

          <Field
            label="شناسه متقاضی (اختیاری)"
            error={formErrors.candidate_id?.[0] || formErrors.candidate_id}
            helper="در صورتی که مشکل متوجه متقاضی خاصی است شناسه متقاضی را وارد کنید."
          >
            <Input
              type="number"
              placeholder="شناسه عددی داوطلب (مثلاً 10)"
              value={newIncident.candidate_id}
              onChange={(e) => setNewIncident({ ...newIncident, candidate_id: e.target.value })}
            />
          </Field>

          <Field
            label="شرح کامل مشکل فنی یا رخداد"
            required
            error={formErrors.problem_description?.[0] || formErrors.problem_description}
            helper="حداقل ۱۰ کاراکتر (مانند قطع برق، مشکل در کابل شبکه، خاموشی مانیتور و...)"
          >
            <Textarea
              rows={3}
              value={newIncident.problem_description}
              onChange={(e) =>
                setNewIncident({ ...newIncident, problem_description: e.target.value })
              }
              placeholder="توضیح دهید دقیقاً چه اتفاقی رخ داده است..."
              required
            />
          </Field>

          <Field
            label="اقدام اولیه انجام‌شده (اختیاری)"
            error={formErrors.action_taken?.[0] || formErrors.action_taken}
          >
            <Input
              value={newIncident.action_taken}
              onChange={(e) => setNewIncident({ ...newIncident, action_taken: e.target.value })}
              placeholder="مثلاً: بررسی کابل شبکه، هدایت به کامپیوتر رزرو..."
            />
          </Field>

          <div className="flex justify-end gap-2 pt-3 border-t border-line">
            <Button
              type="button"
              variant="outline"
              onClick={() => setCreateModalOpen(false)}
              disabled={busy}
            >
              انصراف
            </Button>
            <Button type="submit" variant="primary" loading={busy}>
              ثبت حادثه
            </Button>
          </div>
        </form>
      </Modal>

      {/* Modal ثبت اقدام تکمیلی */}
      <Modal
        open={Boolean(actionModalTarget)}
        onClose={() => setActionModalTarget(null)}
        title={`ثبت اقدام برای حادثه #${actionModalTarget?.id}`}
        size="sm"
      >
        <form onSubmit={handleSaveAction} className="space-y-4 text-xs">
          <div className="p-3 bg-panel rounded border border-line space-y-1">
            <div className="font-semibold text-navy">شرح مشکل:</div>
            <div className="text-ink-soft">{actionModalTarget?.problem_description}</div>
          </div>

          <Field label="اقدام انجام‌شده توسط ناظر" required>
            <Textarea
              rows={3}
              value={actionText}
              onChange={(e) => setActionText(e.target.value)}
              placeholder="توضیح اقدام انجام‌شده برای حل یا مدیریت این حادثه..."
              required
            />
          </Field>

          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => setActionModalTarget(null)}
              disabled={busy}
            >
              انصراف
            </Button>
            <Button type="submit" variant="primary" loading={busy}>
              ذخیره اقدام
            </Button>
          </div>
        </form>
      </Modal>

      {/* دیالوگ تایید حل شدن حادثه */}
      <ConfirmDialog
        open={Boolean(resolveTarget)}
        title="تأیید حل‌شدن حادثه"
        message={`آیا مطمئن هستید که حادثه #${resolveTarget?.id} به طور کامل برطرف شده است و مایل به علامت‌گذاری آن به عنوان حل‌شده (Resolved) هستید؟`}
        confirmLabel="تأیید و حل مشکل"
        cancelLabel="انصراف"
        variant="primary"
        loading={busy}
        onConfirm={handleConfirmResolve}
        onClose={() => setResolveTarget(null)}
      />
    </div>
  );
}

export { IncidentsPage };
