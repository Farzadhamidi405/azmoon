import { useEffect, useState } from 'react';
import { Badge, DataTable, PageHeader, Spinner } from '../../components/ui/index.js';
import api, { API_BASE, unwrapList } from '../../lib/api.js';

export default function RolesPage() {
  const [roles, setRoles] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get(`${API_BASE}/admin/roles`)
      .then((res) => {
        const unwrapped = unwrapList(res.data);
        setRoles(unwrapped.items || []);
      })
      .catch(() => setRoles([]))
      .finally(() => setLoading(false));
  }, []);

  const columns = [
    {
      key: 'name',
      title: 'عنوان نقش (Role)',
      render: (name) => <span className="font-bold text-navy">{name}</span>,
    },
    {
      key: 'guard_name',
      title: 'گارد دسترسی',
      render: (g) => <Badge tone="navy">{g || 'admin'}</Badge>,
    },
    {
      key: 'permissions',
      title: 'تعداد دسترسی‌های تعریف‌شده',
      render: (_, row) => (
        <span className="text-xs text-ink-soft">
          {row.permissions?.length || 0} مجوز دسترسی
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="نقش‌ها و ماتریس دسترسی (RBAC)"
        crumb={['تنظیمات سیستم', 'نقش‌ها و دسترسی‌ها']}
      />

      <div className="card mb-4 p-4 bg-panel border-line text-xs space-y-1">
        <div className="font-bold text-navy">الگوی کنترل دسترسی مبتنی بر نقش (Role-Based Access Control):</div>
        <div className="text-ink-soft leading-relaxed">
          دسترسی‌های هر کاربر بر اساس نقش تعیین‌شده توسط سیستم اعطا می‌شود. ماتریس دسترسی شامل نقش‌های Super Admin، Exam Administrator، Question Manager، Reviewer، Lab Manager و Proctor می‌باشد.
        </div>
      </div>

      <div className="card">
        {loading ? (
          <div className="py-12 flex justify-center items-center gap-2 text-ink-soft">
            <Spinner size="sm" />
            <span>در حال بارگذاری ماتریس نقش‌ها...</span>
          </div>
        ) : (
          <DataTable
            columns={columns}
            data={roles}
            emptyTitle="هیچ نقشی تعریف نشده است."
          />
        )}
      </div>
    </>
  );
}
