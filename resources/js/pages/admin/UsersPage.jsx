import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  FilterBar,
  FilterInput,
  PageHeader,
  Pagination,
} from '../../components/ui/index.js';
import UserFormModal from '../../components/users/UserFormModal.jsx';
import useUsers from '../../hooks/useUsers.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { notifySuccess } from '../../lib/notify.js';

export default function UsersPage() {
  const { hasRole } = useAuth();
  const isSuperAdmin = hasRole('Super Admin');

  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const { items, pagination, loading, create, update, remove, toggleActive } = useUsers({
    search,
    page,
    per_page: 20,
  });

  const [formOpen, setFormOpen] = useState(false);
  const [editingUser, setEditingUser] = useState(null);
  const [deletingUser, setDeletingUser] = useState(null);
  const [busy, setBusy] = useState(false);

  const columns = useMemo(
    () => [
      {
        key: 'name',
        title: 'نام کاربر',
        render: (_, row) => (
          <div>
            <div className="font-bold text-navy">{row.name}</div>
            <div className="text-xs text-ink-soft">{row.email || '—'}</div>
          </div>
        ),
      },
      {
        key: 'username',
        title: 'نام کاربری',
        render: (un) => <span className="font-mono text-xs">{un}</span>,
      },
      {
        key: 'roles',
        title: 'نقش‌های دسترسی',
        render: (_, row) => {
          const roleNames = row.roles?.map((r) => r.name).join('، ') || row.role || '—';
          return <Badge tone="navy">{roleNames}</Badge>;
        },
      },
      {
        key: 'status',
        title: 'وضعیت',
        render: (_, row) => {
          const isActive = row.status === 'active' || row.is_active;
          return <Badge tone={isActive ? 'teal' : 'bad'}>{isActive ? 'فعال' : 'غیرفعال'}</Badge>;
        },
      },
      {
        key: 'actions',
        title: 'عملیات',
        align: 'end',
        render: (_, row) => (
          <div className="flex items-center justify-end gap-1 flex-wrap">
            <Button
              size="sm"
              variant="outline"
              onClick={async () => {
                await toggleActive(row.id);
                notifySuccess('وضعیت کاربر تغییر یافت.');
              }}
            >
              {row.status === 'active' || row.is_active ? 'تعلیق' : 'فعال‌سازی'}
            </Button>

            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                setEditingUser(row);
                setFormOpen(true);
              }}
            >
              ویرایش
            </Button>

            {isSuperAdmin && (
              <Button
                size="sm"
                variant="ghost"
                className="text-red hover:bg-red-soft"
                onClick={() => setDeletingUser(row)}
              >
                حذف
              </Button>
            )}
          </div>
        ),
      },
    ],
    [isSuperAdmin, toggleActive]
  );

  const handleSave = async (data) => {
    setBusy(true);
    try {
      if (editingUser) {
        await update(editingUser.id, data);
        notifySuccess('کاربر با موفقیت به‌روزرسانی شد.');
      } else {
        await create(data);
        notifySuccess('کاربر جدید با موفقیت ایجاد شد.');
      }
      setEditingUser(null);
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingUser) return;
    setBusy(true);
    try {
      await remove(deletingUser.id);
      notifySuccess('کاربر حذف شد.');
      setDeletingUser(null);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        title="مدیریت کاربران و دسترسی‌ها"
        crumb={['تنظیمات سیستم', 'کاربران']}
        actions={
          <Button
            variant="gold"
            onClick={() => {
              setEditingUser(null);
              setFormOpen(true);
            }}
          >
            + تعریف کاربر جدید
          </Button>
        }
      />

      <FilterBar>
        <FilterInput
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="جستجو در نام یا نام کاربری..."
        />
      </FilterBar>

      <div className="card">
        <DataTable
          columns={columns}
          data={items}
          loading={loading}
          emptyTitle="هیچ کاربری یافت نشد."
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

      <UserFormModal
        open={formOpen}
        onClose={() => {
          setFormOpen(false);
          setEditingUser(null);
        }}
        onSave={handleSave}
        user={editingUser}
        busy={busy}
      />

      <ConfirmDialog
        open={!!deletingUser}
        onClose={() => setDeletingUser(null)}
        onConfirm={handleDelete}
        title="حذف کاربر"
        message={`آیا از حذف حساب کاربری «${deletingUser?.name}» اطمینان دارید؟`}
        confirmTone="danger"
        confirmLabel="حذف کاربر"
        busy={busy}
      />
    </>
  );
}
