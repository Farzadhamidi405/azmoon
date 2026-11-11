import { useState } from 'react';
import { Button, Field, FieldRow, Input, PageHeader, Select, Spinner } from '../../components/ui/index.js';
import useSettings from '../../hooks/useSettings.js';
import { notifySuccess } from '../../lib/notify.js';

export default function SettingsPage() {
  const { items, loading, updateSetting } = useSettings();
  const [savingKey, setSavingKey] = useState(null);

  // Fallback defaults if settings table empty
  const defaultSettings = {
    institution_name: 'پوهنتون کندز — معاونیت امور علمی',
    exam_grace_period_seconds: '120',
    heartbeat_interval_seconds: '10',
    max_offline_grace_seconds: '300',
    anti_cheat_strictness: 'high',
    allow_calculator: 'no',
    auto_backup_enabled: 'yes',
  };

  const getVal = (k) => {
    const found = items.find((s) => s.key === k);
    return found ? found.value : defaultSettings[k] || '';
  };

  const [formVals, setFormVals] = useState(defaultSettings);

  const handleSave = async (key, value) => {
    setSavingKey(key);
    try {
      await updateSetting(key, value);
      notifySuccess(`تنظیمات ذخیره شد.`);
    } finally {
      setSavingKey(null);
    }
  };

  return (
    <>
      <PageHeader
        title="تنظیمات سامانه و سیاست‌های سراسری"
        crumb={['تنظیمات', 'تنظیمات سراسری']}
      />

      {loading ? (
        <div className="card p-12 text-center text-ink-soft flex items-center justify-center gap-2">
          <Spinner size="sm" />
          <span>در حال خواندن پیکربندی سیستم...</span>
        </div>
      ) : (
        <div className="space-y-6 max-w-4xl">
          {/* General Settings */}
          <div className="card p-5 space-y-4">
            <h3 className="font-bold text-navy text-sm border-b border-line pb-2">تنظیمات هویتی و مؤسسه</h3>
            <Field label="نام رسمی دانشگاه / مؤسسه">
              <div className="flex gap-2">
                <Input
                  defaultValue={getVal('institution_name')}
                  onChange={(e) => (formVals.institution_name = e.target.value)}
                />
                <Button
                  variant="primary"
                  loading={savingKey === 'institution_name'}
                  onClick={() => handleSave('institution_name', formVals.institution_name || getVal('institution_name'))}
                >
                  ذخیره
                </Button>
              </div>
            </Field>
          </div>

          {/* Exam Network & Resiliency Settings */}
          <div className="card p-5 space-y-4">
            <h3 className="font-bold text-navy text-sm border-b border-line pb-2">تاب‌آوری شبکه و پایش آنلاین (Offline Resiliency)</h3>
            <FieldRow cols={2}>
              <Field label="فاصله ارسال ضربان حیات (Heartbeat) به ثانیه">
                <div className="flex gap-2">
                  <Input
                    type="number"
                    defaultValue={getVal('heartbeat_interval_seconds')}
                    onChange={(e) => (formVals.heartbeat_interval_seconds = e.target.value)}
                  />
                  <Button
                    variant="outline"
                    loading={savingKey === 'heartbeat_interval_seconds'}
                    onClick={() => handleSave('heartbeat_interval_seconds', formVals.heartbeat_interval_seconds || getVal('heartbeat_interval_seconds'))}
                  >
                    ثبت
                  </Button>
                </div>
              </Field>

              <Field label="حداکثر زمان مجاز قطعی شبکه محلی (مهلت تاب‌آوری - ثانیه)">
                <div className="flex gap-2">
                  <Input
                    type="number"
                    defaultValue={getVal('max_offline_grace_seconds')}
                    onChange={(e) => (formVals.max_offline_grace_seconds = e.target.value)}
                  />
                  <Button
                    variant="outline"
                    loading={savingKey === 'max_offline_grace_seconds'}
                    onClick={() => handleSave('max_offline_grace_seconds', formVals.max_offline_grace_seconds || getVal('max_offline_grace_seconds'))}
                  >
                    ثبت
                  </Button>
                </div>
              </Field>
            </FieldRow>
          </div>

          {/* Anti-cheat Policy Settings */}
          <div className="card p-5 space-y-4">
            <h3 className="font-bold text-navy text-sm border-b border-line pb-2">سیاست‌های ضدتقلب و امنیت مرورگر</h3>
            <FieldRow cols={2}>
              <Field label="سطح سخت‌گیری ضدتقلب">
                <div className="flex gap-2">
                  <Select
                    defaultValue={getVal('anti_cheat_strictness')}
                    onChange={(e) => (formVals.anti_cheat_strictness = e.target.value)}
                  >
                    <option value="standard">استاندارد (ثبت رویداد + تذکر)</option>
                    <option value="high">سخت‌گیرانه (قفل موقت پس از ۳ خطا)</option>
                    <option value="strict">فوق‌العاده سخت (تعلیق خودکار با یک خطا)</option>
                  </Select>
                  <Button
                    variant="outline"
                    loading={savingKey === 'anti_cheat_strictness'}
                    onClick={() => handleSave('anti_cheat_strictness', formVals.anti_cheat_strictness || getVal('anti_cheat_strictness'))}
                  >
                    ثبت
                  </Button>
                </div>
              </Field>

              <Field label="مجوز استفاده از ماشین حساب سیستمی">
                <div className="flex gap-2">
                  <Select
                    defaultValue={getVal('allow_calculator')}
                    onChange={(e) => (formVals.allow_calculator = e.target.value)}
                  >
                    <option value="no">غیرمجاز (مسدود کامل)</option>
                    <option value="yes">مجاز برای تمام آزمون‌ها</option>
                  </Select>
                  <Button
                    variant="outline"
                    loading={savingKey === 'allow_calculator'}
                    onClick={() => handleSave('allow_calculator', formVals.allow_calculator || getVal('allow_calculator'))}
                  >
                    ثبت
                  </Button>
                </div>
              </Field>
            </FieldRow>
          </div>
        </div>
      )}
    </>
  );
}
