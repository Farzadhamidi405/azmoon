import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  DataTable,
  FilterBar,
  FilterInput,
  PageHeader,
  Pagination,
} from '../../components/ui/index.js';
import useAuditLogs from '../../hooks/useAuditLogs.js';
import formatDate from '../../lib/formatDate.js';

export default function AuditLogsPage() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const { items, pagination, loading, reload } = useAuditLogs({
    search,
    page,
    per_page: 25,
  });

  const columns = useMemo(
    () => [
      {
        key: 'created_at',
        title: 'زمان رویداد',
        render: (d) => <span className="font-mono text-xs text-ink-soft">{d ? formatDate(d) : '—'}</span>,
      },
      {
        key: 'user',
        title: 'کاربر مجری',
        render: (_, row) => (
          <div>
            <div className="font-bold text-navy text-xs">{row.user?.name || row.user_name || 'کاربر سیستم'}</div>
            <div className="text-[11px] text-ink-soft font-mono">IP: {row.ip_address || '127.0.0.1'}</div>
          </div>
        ),
      },
      {
        key: 'action',
        title: 'عملیات (Action)',
        render: (act) => {
          let tone = 'off';
          if (act?.includes('delete') || act?.includes('destroy') || act?.includes('disqualify')) tone = 'bad';
          else if (act?.includes('create') || act?.includes('publish') || act?.includes('approve')) tone = 'teal';
          else if (act?.includes('update') || act?.includes('transfer')) tone = 'gold';
          return <Badge tone={tone}>{act || 'نامشخص'}</Badge>;
        },
      },
      {
        key: 'auditable_type',
        title: 'موجودیت هدف',
        render: (_, row) => (
          <span className="text-xs font-mono">
            {row.auditable_type ? row.auditable_type.split('\\').pop() : 'System'} #{row.auditable_id || '—'}
          </span>
        ),
      },
      {
        key: 'details',
        title: 'توضیحات / مقادیر',
        render: (_, row) => (
          <div className="text-xs text-ink max-w-[350px] truncate" title={JSON.stringify(row.new_values || row.details || {})}>
            {row.description || (row.new_values ? JSON.stringify(row.new_values) : '—')}
          </div>
        ),
      },
    ],
    []
  );

  return (
    <>
      <PageHeader
        title="دفتر کل حسابرسی امنیتی (Audit Logs)"
        crumb={['امنیت و انطباق', 'لاگ‌های تغییرناپذیر']}
        actions={
          <div className="flex gap-2">
            <Button variant="outline" onClick={reload}>
              ⟳ تازه‌سازی
            </Button>
            <Button variant="outline" disabled title="نیازمند اتصال به سخت‌افزار امضای دیجیتال یا سرویس خارجی">
              🛡️ تأیید زنجیره امضا (در دسترس نیست)
            </Button>
          </div>
        }
      />

      <div className="card mb-4 p-3 bg-teal-soft/30 border border-teal/20 text-xs text-ink-soft flex items-center justify-between">
        <div>
          <b>استاندارد امنیت تغییرناپذیر:</b> لاگ‌های حسابرسی به‌صورت Append-Only ذخیره شده و هیچ کاربری حتی Super Admin امکان حذف یا ویرایش آن‌ها را ندارد.
        </div>
        <Badge tone="teal">حسابرسی فعال</Badge>
      </div>

      <FilterBar>
        <FilterInput
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="جستجو در متن یا کاربر..."
        />
      </FilterBar>

      <div className="card">
        <DataTable
          columns={columns}
          data={items}
          loading={loading}
          emptyTitle="هیچ رکوردی در دفتر کل حسابرسی یافت نشد."
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
    </>
  );
}
