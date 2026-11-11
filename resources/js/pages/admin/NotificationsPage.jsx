import { Badge, Button, PageHeader } from '../../components/ui/index.js';
import useNotifications from '../../hooks/useNotifications.js';
import formatDate from '../../lib/formatDate.js';

export default function NotificationsPage() {
  const { items, unreadCount, loading, reload, markAsRead, markAllAsRead } = useNotifications();

  return (
    <>
      <PageHeader
        title="مرکز اعلانات سیستم"
        crumb={['سیستم', 'اعلان‌ها']}
        actions={
          <div className="flex gap-2">
            {unreadCount > 0 && (
              <Button variant="outline" onClick={markAllAsRead}>
                علامت‌گذاری همه به عنوان خوانده‌شده
              </Button>
            )}
            <Button variant="outline" onClick={reload}>
              ⟳ بروزرسانی
            </Button>
          </div>
        }
      />

      <div className="card">
        {loading ? (
          <div className="py-12 text-center text-ink-soft text-sm">در حال بارگذاری اعلانات...</div>
        ) : items.length === 0 ? (
          <div className="py-12 text-center text-ink-soft text-sm">هیچ اعلانی برای نمایش وجود ندارد.</div>
        ) : (
          <div className="divide-y divide-line">
            {items.map((n) => {
              const isUnread = !n.read_at;
              return (
                <div
                  key={n.id}
                  className={`p-4 flex items-start justify-between gap-3 transition-colors ${
                    isUnread ? 'bg-gold-soft/20' : 'bg-panel'
                  }`}
                >
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <span className="font-bold text-navy text-sm">{n.title || n.data?.title || 'اعلان سامانه'}</span>
                      {isUnread && <Badge tone="gold">جدید</Badge>}
                    </div>
                    <div className="text-xs text-ink">{n.body || n.data?.body || n.message}</div>
                    <div className="text-[11px] text-ink-soft">{formatDate(n.created_at)}</div>
                  </div>
                  {isUnread && (
                    <Button size="sm" variant="ghost" onClick={() => markAsRead(n.id)}>
                      خوانده شد
                    </Button>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>
    </>
  );
}
