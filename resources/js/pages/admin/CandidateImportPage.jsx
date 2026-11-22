import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge, Button, PageHeader, Spinner } from '../../components/ui/index.js';
import { api, API_BASE } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

export default function CandidateImportPage() {
  const fileInputRef = useRef(null);
  const [file, setFile] = useState(null);
  const [prefix, setPrefix] = useState('KND');
  const [loadingPreview, setLoadingPreview] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // وضعیت مرحله اول (پیش‌نمایش)
  const [previewData, setPreviewData] = useState(null);

  // وضعیت مرحله دوم (نتیجه نهایی)
  const [finalResult, setFinalResult] = useState(null);

  const handleFileSelect = (e) => {
    const selected = e.target.files?.[0];
    if (selected) {
      setFile(selected);
      setPreviewData(null);
      setFinalResult(null);
    }
  };

  // مرحله ۱: ارسال فایل برای اعتبارسنجی ساختار و نمایش پیش‌نمایش بدون ذخیره
  const handleValidatePreview = async () => {
    if (!file) {
      notifyError('لطفاً ابتدا یک فایل Excel (.xlsx) یا .csv انتخاب نمایید.');
      return;
    }

    setLoadingPreview(true);
    setPreviewData(null);
    setFinalResult(null);

    const formData = new FormData();
    formData.append('file', file);
    formData.append('confirmed', '0');
    formData.append('prefix', prefix);

    try {
      const res = await api.post(`${API_BASE}/admin/candidates/import`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setPreviewData(res.data?.data || null);
      notifySuccess('فایل با موفقیت بررسی شد. پیش‌نمایش در زیر قابل مشاهده است.');
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در بررسی ساختار فایل.';
      notifyError(msg);
    } finally {
      setLoadingPreview(false);
    }
  };

  // مرحله ۲: تأیید نهایی کاربر و ارسال confirmed=true جهت ذخیره در پایگاه‌داده
  const handleConfirmImport = async () => {
    if (!file || !previewData) return;

    setSubmitting(true);
    const formData = new FormData();
    formData.append('file', file);
    formData.append('confirmed', '1');
    formData.append('prefix', prefix);

    try {
      const res = await api.post(`${API_BASE}/admin/candidates/import`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      const data = res.data?.data || {};
      setFinalResult(data);
      notifySuccess(res.data?.message || 'متقاضیان معتبر با موفقیت در پایگاه‌داده ثبت شدند.');
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت نهایی متقاضیان.';
      notifyError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const handleCopyCredentials = () => {
    if (!finalResult?.candidates?.length) return;
    const lines = ['کد متقاضی\tنام کاربری\tنام متقاضی\tرمز عبور اولیه'];
    finalResult.candidates.forEach((item) => {
      const c = item.candidate || item;
      const pass = item.plain_password || '••••••';
      lines.push(`${c.candidate_code}\t${c.username}\t${c.name}\t${pass}`);
    });
    navigator.clipboard?.writeText(lines.join('\n'));
    notifySuccess('اطلاعات ورود متقاضیان در کلیپ‌بورد کپی شد.');
  };

  const summary = previewData?.summary || {
    total_count: 0,
    valid_count: 0,
    duplicate_count: 0,
    invalid_count: 0,
  };

  return (
    <div className="max-w-[880px] mx-auto w-full space-y-4 font-vazir text-xs">
      <PageHeader
        title="ورود متقاضیان از فایل Excel و CSV"
        crumb={['پوهنتون کندز', 'مدیریت متقاضیان', 'Import متقاضیان']}
        actions={
          <Link to="/admin/candidates">
            <Button variant="outline" size="sm">
              ← بازگشت به لیست متقاضیان
            </Button>
          </Link>
        }
      />

      {/* راهنما و نکات ساختار فایل */}
      <div className="card p-4 bg-paper/50 border border-line space-y-2">
        <div className="font-bold text-navy text-sm flex items-center gap-2">
          <span>📋</span>
          <span>راهنمای ساختار فایل و ستون‌ها</span>
        </div>
        <div className="text-ink text-xs leading-relaxed space-y-1">
          <div>
            سیستم به صورت هوشمند فایل‌های <b>XLSX</b> (اکسل مدرن) و <b>CSV</b> را پردازش می‌کند. هدر ردیف اول می‌تواند فارسی یا انگلیسی باشد:
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1 font-mono text-[11px]">
            <div className="bg-panel p-2 rounded border border-line">
              <b className="text-navy">ستون ۱:</b> <code>نام</code> یا <code>نام کامل</code> یا <code>name</code> (اجباری)
            </div>
            <div className="bg-panel p-2 rounded border border-line">
              <b className="text-navy">ستون ۲:</b> <code>تذکره</code> یا <code>کد ملی</code> یا <code>national_id</code> (اختیاری)
            </div>
          </div>
        </div>
      </div>

      {/* کارت آپلود و انتخاب فایل */}
      {!finalResult && (
        <div className="card space-y-4 p-5">
          <input
            ref={fileInputRef}
            type="file"
            accept=".xlsx,.xls,.csv"
            className="hidden"
            onChange={handleFileSelect}
          />

          <div
            onClick={() => fileInputRef.current?.click()}
            className="border-2 border-dashed border-line rounded-2xl p-6 text-center cursor-pointer hover:border-gold hover:bg-gold/5 transition-all bg-paper/20"
          >
            {file ? (
              <div className="space-y-1.5">
                <div className="text-2xl">📄</div>
                <div className="font-bold text-navy text-sm">{file.name}</div>
                <div className="text-[11px] text-ink-soft font-mono">
                  {(file.size / 1024).toFixed(1)} کیلوبایت · {file.name.endsWith('.csv') ? 'فرمت CSV' : 'فرمت Excel (XLSX)'}
                </div>
                <div className="text-gold font-semibold text-xs pt-1 underline">کلیک برای تغییر فایل</div>
              </div>
            ) : (
              <div className="space-y-2">
                <div className="text-3xl text-navy">📥</div>
                <div className="font-bold text-navy text-sm">
                  فایل Excel یا CSV متقاضیان را اینجا رها کنید یا کلیک کنید
                </div>
                <div className="text-ink-soft text-[11px]">
                  پشتیبانی از فایل‌های استاندارد <code>.xlsx</code> و <code>.csv</code> با حداکثر حجم ۵ مگابایت
                </div>
              </div>
            )}
          </div>

          <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2">
            <div className="flex items-center gap-2 w-full sm:w-auto">
              <span className="text-ink-soft whitespace-nowrap">پیشوند کد متقاضی:</span>
              <input
                type="text"
                value={prefix}
                onChange={(e) => setPrefix(e.target.value.toUpperCase())}
                placeholder="KND"
                className="rounded border border-line px-3 py-1 font-mono text-xs w-24 text-navy font-bold uppercase"
              />
            </div>

            <Button
              variant="primary"
              size="md"
              loading={loadingPreview}
              disabled={!file}
              onClick={handleValidatePreview}
              className="w-full sm:w-auto"
            >
              🔍 مرحله اول: بررسی فایل و پیش‌نمایش
            </Button>
          </div>
        </div>
      )}

      {/* مرحله ۱: نمایش پیش‌نمایش و خلاصه اعتبارسنجی */}
      {previewData && !finalResult && (
        <div className="card space-y-4 p-5 border-2 border-navy/20">
          <div className="flex items-center justify-between border-b border-line pb-3">
            <div>
              <div className="font-black text-navy text-sm">نتیجهٔ اعتبارسنجی پیش‌نمایش (مرحله ۱)</div>
              <div className="text-[11px] text-ink-soft mt-0.5">
                در این مرحله هیچ اطلاعاتی در پایگاه‌داده ذخیره نشده است.
              </div>
            </div>
            <Badge tone="pending">پیش‌نمایش تأییدنشده</Badge>
          </div>

          {/* خلاصه آمار */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
            <div className="p-3 bg-paper rounded-xl border border-line">
              <div className="text-xs text-ink-soft">کل ردیف‌ها</div>
              <div className="text-xl font-bold font-mono text-navy mt-1">{summary.total_count}</div>
            </div>
            <div className="p-3 bg-teal/10 rounded-xl border border-teal/20">
              <div className="text-xs text-teal font-semibold">آماده و معتبر</div>
              <div className="text-xl font-bold font-mono text-teal mt-1">{summary.valid_count}</div>
            </div>
            <div className="p-3 bg-amber/10 rounded-xl border border-amber/20">
              <div className="text-xs text-amber font-semibold">تکراری در سیستم/فایل</div>
              <div className="text-xl font-bold font-mono text-amber mt-1">{summary.duplicate_count}</div>
            </div>
            <div className="p-3 bg-red/10 rounded-xl border border-red/20">
              <div className="text-xs text-red font-semibold">نامعتبر و دارای خطا</div>
              <div className="text-xl font-bold font-mono text-red mt-1">{summary.invalid_count}</div>
            </div>
          </div>

          {/* خطاهای احتمالی */}
          {previewData.errors?.length > 0 && (
            <div className="p-3.5 bg-red-50 border border-red-200 rounded-xl space-y-1.5">
              <div className="font-bold text-red-900 text-xs">
                ⚠️ لیست ردیف‌های نامعتبر (این ردیف‌ها در هنگام ذخیره نادیده گرفته می‌شوند):
              </div>
              <ul className="list-disc list-inside text-[11px] text-red-800 space-y-0.5 max-h-36 overflow-y-auto">
                {previewData.errors.map((err, idx) => (
                  <li key={idx}>
                    ردیف {err.row}: {Object.values(err.errors || {}).join(' — ')}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* جدول نمونه ردیف‌های معتبر آماده ورود */}
          {previewData.valid?.length > 0 && (
            <div className="space-y-2">
              <div className="font-bold text-navy text-xs">
                نمونه ردیف‌های معتبر شناسایی‌شده ({previewData.valid.length} متقاضی):
              </div>
              <div className="max-h-56 overflow-y-auto border border-line rounded-lg">
                <table className="w-full text-right text-xs">
                  <thead className="bg-paper border-b border-line sticky top-0">
                    <tr>
                      <th className="p-2">#</th>
                      <th className="p-2">نام کامل متقاضی</th>
                      <th className="p-2">شماره تذکره / کد ملی</th>
                      <th className="p-2">وضعیت اعتبارسنجی</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {previewData.valid.slice(0, 30).map((row, i) => (
                      <tr key={i} className="hover:bg-paper/40">
                        <td className="p-2 font-mono text-ink-soft">{i + 1}</td>
                        <td className="p-2 font-bold text-navy">{row.name}</td>
                        <td className="p-2 font-mono">{row.national_id || '—'}</td>
                        <td className="p-2">
                          <span className="text-teal font-semibold">✓ آماده ایجاد حساب</span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {previewData.valid.length > 30 && (
                <div className="text-center text-[11px] text-ink-soft">
                  ... و {previewData.valid.length - 30} متقاضی دیگر
                </div>
              )}
            </div>
          )}

          {/* عملیات مرحله دوم: تأیید نهایی و ذخیره */}
          <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-3 border-t border-line">
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setPreviewData(null);
                setFile(null);
              }}
            >
              ✕ انصراف و انتخاب فایل دیگر
            </Button>

            <Button
              variant="gold"
              size="md"
              loading={submitting}
              disabled={summary.valid_count === 0}
              onClick={handleConfirmImport}
            >
              ✓ مرحله دوم: تأیید نهایی و ثبت {summary.valid_count} متقاضی در پایگاه‌داده
            </Button>
          </div>
        </div>
      )}

      {/* نتیجه نهایی ذخیره‌سازی */}
      {finalResult && (
        <div className="card space-y-4 p-5 border-2 border-teal/40 bg-teal/5">
          <div className="flex items-center justify-between border-b border-line pb-3">
            <div className="space-y-1">
              <div className="text-teal font-black text-base flex items-center gap-2">
                <span>✓</span>
                <span>عملیات با موفقیت انجام شد!</span>
              </div>
              <div className="text-ink text-xs">
                تعداد <b>{finalResult.imported_count}</b> حساب متقاضی جدید در پایگاه‌داده ساخته شد.
              </div>
            </div>
            <Button variant="outline" size="sm" onClick={handleCopyCredentials}>
              📋 کپی رمزها و یوزرنیم‌ها
            </Button>
          </div>

          <div className="max-h-72 overflow-y-auto border border-line rounded-lg bg-panel">
            <table className="w-full text-right text-xs">
              <thead className="bg-paper border-b border-line sticky top-0">
                <tr>
                  <th className="p-2">کد متقاضی</th>
                  <th className="p-2">نام کامل</th>
                  <th className="p-2">نام کاربری</th>
                  <th className="p-2">رمز عبور اولیه</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {(finalResult.candidates || []).map((item, idx) => {
                  const cand = item.candidate || item;
                  return (
                    <tr key={idx} className="hover:bg-paper/40">
                      <td className="p-2 font-mono font-bold text-navy">{cand.candidate_code}</td>
                      <td className="p-2 text-ink">{cand.name}</td>
                      <td className="p-2 font-mono text-ink-soft">{cand.username}</td>
                      <td className="p-2 font-mono font-bold text-gold">{item.plain_password || '••••••'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <div className="flex justify-between items-center pt-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setFile(null);
                setPreviewData(null);
                setFinalResult(null);
              }}
            >
              + ورود فایل دیگر
            </Button>

            <Link to="/admin/candidates">
              <Button variant="primary" size="md">
                مشاهده در جدول متقاضیان →
              </Button>
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}

export { CandidateImportPage };
