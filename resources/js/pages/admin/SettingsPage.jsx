import { useState } from 'react';
import { PageHeader } from '../../components/ui/index.js';
import { notifySuccess } from '../../lib/notify.js';

export default function SettingsPage() {
  const [activeTab, setActiveTab] = useState('general');
  const [savedMessage, setSavedMessage] = useState('');

  const handleSave = (section) => {
    setSavedMessage(`تنظیمات بخش ${section} با موفقیت ذخیره شد.`);
    notifySuccess(`تنظیمات ذخیره شد.`);
    setTimeout(() => setSavedMessage(''), 3000);
  };

  return (
    <div className="max-w-[820px] mx-auto w-full space-y-4">
      <PageHeader
        title="تنظیمات سیستم"
        crumb={['سیستم', 'تنظیمات']}
      />

      {/* Tabs */}
      <div className="tabs flex border-b border-line gap-1 mb-2">
        <button
          type="button"
          onClick={() => setActiveTab('general')}
          className={`tab px-4 py-2 text-xs font-semibold rounded-t-lg transition-colors cursor-pointer border-b-2 ${
            activeTab === 'general'
              ? 'border-navy text-navy bg-white font-bold'
              : 'border-transparent text-ink-soft hover:text-ink'
          }`}
        >
          عمومی
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('exam')}
          className={`tab px-4 py-2 text-xs font-semibold rounded-t-lg transition-colors cursor-pointer border-b-2 ${
            activeTab === 'exam'
              ? 'border-navy text-navy bg-white font-bold'
              : 'border-transparent text-ink-soft hover:text-ink'
          }`}
        >
          تنظیمات امتحان
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('security')}
          className={`tab px-4 py-2 text-xs font-semibold rounded-t-lg transition-colors cursor-pointer border-b-2 ${
            activeTab === 'security'
              ? 'border-navy text-navy bg-white font-bold'
              : 'border-transparent text-ink-soft hover:text-ink'
          }`}
        >
          امنیت
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('backup')}
          className={`tab px-4 py-2 text-xs font-semibold rounded-t-lg transition-colors cursor-pointer border-b-2 ${
            activeTab === 'backup'
              ? 'border-navy text-navy bg-white font-bold'
              : 'border-transparent text-ink-soft hover:text-ink'
          }`}
        >
          پشتیبان‌گیری
        </button>
      </div>

      {savedMessage ? (
        <div className="p-3 bg-teal-soft text-teal border border-teal/20 rounded-md text-xs font-semibold">
          ✓ {savedMessage}
        </div>
      ) : null}

      {/* Tab 1: General */}
      {activeTab === 'general' && (
        <div className="card space-y-4">
          <div className="section-title text-[14px] font-bold text-navy">هویت پوهنتون</div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div className="field space-y-1">
              <label className="font-semibold text-ink">نام پوهنتون</label>
              <input
                defaultValue="پوهنتون کندز"
                className="w-full rounded border border-line p-2 text-xs text-ink bg-white"
              />
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">لوگو</label>
              <input
                defaultValue="kunduz-logo.png"
                readOnly
                className="w-full rounded border border-line p-2 text-xs text-ink-soft bg-paper/50 font-mono"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div className="field space-y-1">
              <label className="font-semibold text-ink">زبان پیش‌فرض</label>
              <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
                <option>دری</option>
                <option>پشتو</option>
                <option>English</option>
              </select>
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">منطقهٔ زمانی</label>
              <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
                <option>Asia/Kabul (UTC+4:30)</option>
              </select>
            </div>
          </div>

          <div className="field max-w-[260px] space-y-1 text-xs">
            <label className="font-semibold text-ink">فرمت تاریخ</label>
            <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
              <option>هجری شمسی</option>
              <option>میلادی</option>
            </select>
          </div>

          <div className="pt-2">
            <button
              type="button"
              onClick={() => handleSave('عمومی')}
              className="btn btn-primary text-xs"
            >
              ذخیرهٔ تنظیمات
            </button>
          </div>
        </div>
      )}

      {/* Tab 2: Exam Settings */}
      {activeTab === 'exam' && (
        <div className="card space-y-4">
          <div className="section-title text-[14px] font-bold text-navy">تنظیمات پیش‌فرض امتحان</div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div className="field space-y-1">
              <label className="font-semibold text-ink">Auto Save</label>
              <input
                defaultValue="فوری — هر پاسخ بلافاصله"
                className="w-full rounded border border-line p-2 text-xs text-ink bg-white"
              />
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">Session Timeout بی‌فعالیتی</label>
              <input
                defaultValue="۱۵ دقیقه"
                className="w-full rounded border border-line p-2 text-xs text-ink bg-white"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
            <div className="field space-y-1">
              <label className="font-semibold text-ink">حرکت به سوال قبلی</label>
              <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
                <option>مجاز</option>
                <option>غیرمجاز</option>
              </select>
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">Randomize سوالات</label>
              <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
                <option>فعال</option>
                <option>غیرفعال</option>
              </select>
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">Randomize گزینه‌ها</label>
              <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
                <option>فعال</option>
                <option>غیرفعال</option>
              </select>
            </div>
          </div>

          <div className="field max-w-[260px] space-y-1 text-xs">
            <label className="font-semibold text-ink">ارسال خودکار پس از اتمام زمان</label>
            <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
              <option>فعال (اجباری)</option>
              <option>غیرفعال</option>
            </select>
          </div>

          <div className="section-title text-[14px] font-bold text-navy pt-2">Exam Browser Policy</div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div className="field space-y-1">
              <label className="font-semibold text-ink">مرورگرهای مجاز</label>
              <input
                defaultValue="Chrome (آخرین نسخه), Edge (آخرین نسخه)"
                className="w-full rounded border border-line p-2 text-xs text-ink bg-white"
              />
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">Fullscreen پیش از شروع</label>
              <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
                <option>اجباری</option>
                <option>پیشنهادی</option>
              </select>
            </div>
          </div>

          <div className="pt-2">
            <button
              type="button"
              onClick={() => handleSave('امتحان')}
              className="btn btn-primary text-xs"
            >
              ذخیرهٔ تنظیمات
            </button>
          </div>
        </div>
      )}

      {/* Tab 3: Security */}
      {activeTab === 'security' && (
        <div className="card space-y-4">
          <div className="section-title text-[14px] font-bold text-navy">سیاست رمز عبور و ورود</div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div className="field space-y-1">
              <label className="font-semibold text-ink">حداقل طول رمز عبور</label>
              <input
                defaultValue="۸ کاراکتر"
                className="w-full rounded border border-line p-2 text-xs text-ink bg-white"
              />
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">پیچیدگی رمز</label>
              <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
                <option>حروف + عدد + نماد</option>
                <option>فقط حروف و عدد</option>
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div className="field space-y-1">
              <label className="font-semibold text-ink">حداکثر تلاش ناموفق ورود</label>
              <input
                defaultValue="۵ بار"
                className="w-full rounded border border-line p-2 text-xs text-ink bg-white"
              />
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">مدت قفل حساب پس از تلاش ناموفق</label>
              <input
                defaultValue="۱۵ دقیقه"
                className="w-full rounded border border-line p-2 text-xs text-ink bg-white"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div className="field space-y-1">
              <label className="font-semibold text-ink">Session Timeout پنل ادمین</label>
              <input
                defaultValue="۳۰ دقیقه بی‌فعالیتی"
                className="w-full rounded border border-line p-2 text-xs text-ink bg-white"
              />
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">احراز هویت دومرحله‌ای (2FA)</label>
              <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
                <option>اجباری برای Super Admin</option>
                <option>اختیاری</option>
                <option>غیرفعال</option>
              </select>
            </div>
          </div>

          <div className="field max-w-[340px] space-y-1 text-xs">
            <label className="font-semibold text-ink">محدودیت IP برای ورود ادمین</label>
            <input
              defaultValue="فقط شبکهٔ داخلی پوهنتون (10.10.0.0/16)"
              className="w-full rounded border border-line p-2 text-xs text-ink bg-white font-mono"
            />
          </div>

          <div className="pt-2">
            <button
              type="button"
              onClick={() => handleSave('امنیتی')}
              className="btn btn-primary text-xs"
            >
              ذخیرهٔ تنظیمات امنیتی
            </button>
          </div>
        </div>
      )}

      {/* Tab 4: Backup */}
      {activeTab === 'backup' && (
        <div className="card space-y-4">
          <div className="section-title text-[14px] font-bold text-navy">پشتیبان‌گیری</div>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div className="stat">
              <div className="lbl text-xs text-ink-soft">آخرین پشتیبان</div>
              <div className="num text-[14px] font-mono mt-1 text-navy font-bold">۱۴۰۴/۰۶/۱۲ — ۰۲:۰۰</div>
            </div>
            <div className="stat">
              <div className="lbl text-xs text-ink-soft">حجم فایل</div>
              <div className="num text-[14px] font-mono mt-1 text-navy font-bold">۱٫۸ GB</div>
            </div>
            <div className="stat">
              <div className="lbl text-xs text-ink-soft">وضعیت</div>
              <div className="num text-[14px] mt-1"><span className="badge on">موفق</span></div>
            </div>
          </div>

          <div className="flex gap-2 flex-wrap pt-1">
            <button type="button" className="btn btn-primary text-xs">
              ایجاد پشتیبان جدید
            </button>
            <button type="button" className="btn btn-outline text-xs">
              دانلود آخرین پشتیبان
            </button>
            <button type="button" className="btn btn-outline text-xs">
              بازیابی از پشتیبان
            </button>
            <button type="button" className="btn btn-danger text-xs">
              حذف پشتیبان‌های قدیمی
            </button>
          </div>

          <div className="section-title text-[14px] font-bold text-navy pt-2">زمان‌بندی خودکار</div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div className="field space-y-1">
              <label className="font-semibold text-ink">تناوب پشتیبان‌گیری خودکار</label>
              <select className="w-full rounded border border-line p-2 text-xs text-ink bg-white">
                <option>روزانه — ساعت ۰۲:۰۰</option>
                <option>هفتگی</option>
              </select>
            </div>
            <div className="field space-y-1">
              <label className="font-semibold text-ink">نگهداری نسخه‌ها</label>
              <input
                defaultValue="۳۰ روز اخیر"
                className="w-full rounded border border-line p-2 text-xs text-ink bg-white"
              />
            </div>
          </div>

          <div className="overflow-x-auto pt-2">
            <table className="w-full text-right text-xs">
              <thead>
                <tr className="border-b border-line text-ink-soft">
                  <th className="py-2.5 px-3">تاریخ</th>
                  <th className="py-2.5 px-3">حجم</th>
                  <th className="py-2.5 px-3">نوع</th>
                  <th className="py-2.5 px-3">وضعیت</th>
                  <th className="py-2.5 px-3">عملیات</th>
                </tr>
              </thead>
              <tbody>
                <tr className="border-b border-line-soft hover:bg-row-hover">
                  <td className="py-2.5 px-3 font-mono">۱۴۰۴/۰۶/۱۲ — ۰۲:۰۰</td>
                  <td className="py-2.5 px-3 font-mono">۱٫۸ GB</td>
                  <td className="py-2.5 px-3">خودکار (روزانه)</td>
                  <td className="py-2.5 px-3"><span className="badge on">موفق</span></td>
                  <td className="py-2.5 px-3"><button type="button" className="btn-ghost text-xs">دانلود</button></td>
                </tr>
                <tr className="border-b border-line-soft hover:bg-row-hover">
                  <td className="py-2.5 px-3 font-mono">۱۴۰۴/۰۶/۱۱ — ۰۲:۰۰</td>
                  <td className="py-2.5 px-3 font-mono">۱٫۷ GB</td>
                  <td className="py-2.5 px-3">خودکار (روزانه)</td>
                  <td className="py-2.5 px-3"><span className="badge on">موفق</span></td>
                  <td className="py-2.5 px-3"><button type="button" className="btn-ghost text-xs">دانلود</button></td>
                </tr>
                <tr className="border-b border-line-soft hover:bg-row-hover">
                  <td className="py-2.5 px-3 font-mono">۱۴۰۴/۰۶/۱۰ — ۱۸:۴۰</td>
                  <td className="py-2.5 px-3 font-mono">—</td>
                  <td className="py-2.5 px-3">دستی</td>
                  <td className="py-2.5 px-3"><span className="badge bad">ناموفق</span></td>
                  <td className="py-2.5 px-3"><button type="button" className="btn-ghost text-xs">جزئیات خطا</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

export { SettingsPage };
