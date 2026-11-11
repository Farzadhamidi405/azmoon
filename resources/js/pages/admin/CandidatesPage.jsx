import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  FilterBar,
  FilterInput,
  FilterSelect,
  PageHeader,
  Pagination,
} from '../../components/ui/index.js';
import CandidateFormModal from '../../components/candidates/CandidateFormModal.jsx';
import CandidateBulkGenerateModal from '../../components/candidates/CandidateBulkGenerateModal.jsx';
import CandidateCardsModal from '../../components/candidates/CandidateCardsModal.jsx';
import useCandidates from '../../hooks/useCandidates.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { notifySuccess } from '../../lib/notify.js';

const STATUS_CONFIG = {
  registered: { label: 'ثبت‌نام شده', tone: 'off' },
  checked_in: { label: 'حاضر (Checked-in)', tone: 'teal' },
  in_progress: { label: 'در حال امتحان', tone: 'on' },
  completed: { label: 'تکمیل‌شده', tone: 'gold' },
  absent: { label: 'غایب', tone: 'bad' },
  disqualified: { label: 'محروم', tone: 'bad' },
};

export default function CandidatesPage() {
  const { can, hasRole } = useAuth();
  const canManage = can('candidates.create') || hasRole('Super Admin', 'Exam Administrator', 'Lab Manager');

  const [filters, setFilters] = useState({ search: '', status: '' });
  const [page, setPage] = useState(1);
  const { items, pagination, loading, reload, create, update, remove, bulkGenerate, checkIn } =
    useCandidates({
      ...filters,
      page,
      per_page: 25,
    });

  const [formOpen, setFormOpen] = useState(false);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [cardsOpen, setCardsOpen] = useState(false);
  const [editingCandidate, setEditingCandidate] = useState(null);
  const [deletingCandidate, setDeletingCandidate] = useState(null);
  const [busy, setBusy] = useState(false);

  const columns = useMemo(
    () => [
      {
        key: 'name',
        title: 'نام متقاضی',
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
        title: 'نام کاربری',
        render: (un) => <span className="font-mono text-xs">{un}</span>,
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
        render: (_, row) => (
          <div className="flex items-center justify-end gap-1.5 flex-wrap">
            {row.status === 'registered' && (
              <Button
                size="sm"
                variant="teal"
                onClick={async () => {
                  await checkIn(row.id);
                  notifySuccess(`متقاضی «${row.name}» با موفقیت Check-in شد.`);
                }}
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
                  className="text-red hover:bg-red-soft"
                  onClick={() => setDeletingCandidate(row)}
                >
                  حذف
                </Button>
              </>
            )}
          </div>
        ),
      },
    ],
    [canManage, checkIn]
  );

  const handleSave = async (formData) => {
    setBusy(true);
    try {
      if (editingCandidate) {
        await update(editingCandidate.id, formData);
        notifySuccess('اطلاعات متقاضی با موفقیت به‌روزرسانی شد.');
      } else {
        await create(formData);
        notifySuccess('متقاضی جدید با موفقیت اضافه شد.');
      }
      setEditingCandidate(null);
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
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        title="متقاضیان"
        crumb={['متقاضیان', 'لیست متقاضیان']}
        actions={
          canManage && (
            <div className="flex gap-2">
              <Button variant="outline" onClick={() => setCardsOpen(true)}>
                کارت‌های ورود
              </Button>
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
          onChange={(e) => setFilters((p) => ({ ...p, search: e.target.value }))}
          placeholder="جستجو در نام، کد یا یوزرنیم..."
        />
        <FilterSelect
          value={filters.status}
          onChange={(e) => setFilters((p) => ({ ...p, status: e.target.value }))}
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
          emptyTitle="هیچ متقاضی‌ای ثبت نشده است."
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

      <CandidateBulkGenerateModal
        open={bulkOpen}
        onClose={() => setBulkOpen(false)}
        onGenerate={bulkGenerate}
        busy={busy}
      />

      <CandidateCardsModal
        open={cardsOpen}
        onClose={() => setCardsOpen(false)}
        candidates={items}
      />

      <ConfirmDialog
        open={!!deletingCandidate}
        onClose={() => setDeletingCandidate(null)}
        onConfirm={handleDelete}
        title="حذف متقاضی"
        message={`آیا از حذف متقاضی «${deletingCandidate?.name || deletingCandidate?.candidate_code}» مطمئن هستید؟`}
        confirmTone="danger"
        confirmLabel="حذف"
        busy={busy}
      />
    </>
  );
}
