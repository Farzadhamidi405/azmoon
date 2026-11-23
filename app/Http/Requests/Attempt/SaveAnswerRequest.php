<?php

namespace App\Http\Requests\Attempt;

use Illuminate\Foundation\Http\FormRequest;

/**
 * اعتبارسنجی درخواست ذخیره‌سازی خودکار پاسخ داوطلب (Auto-save).
 * بند 17 SRS.
 */
class SaveAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // احراز هویت در سطح کنترلر و گارد candidate بررسی می‌شود.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // پشتیبانی از سازگاری: اگر question_id ارسال شده بود، به exam_snapshot_question_id مپ شود
        if (!$this->has('exam_snapshot_question_id') && $this->has('question_id')) {
            $this->merge([
                'exam_snapshot_question_id' => $this->input('question_id'),
            ]);
        }

        // پشتیبانی از سازگاری: اگر selected_option_id تک‌مقداری ارسال شده بود، به آرایه تبدیل شود
        if (!$this->has('selected_option_ids') && $this->has('selected_option_id')) {
            $singleOpt = $this->input('selected_option_id');
            $this->merge([
                'selected_option_ids' => $singleOpt !== null ? [(int) $singleOpt] : [],
            ]);
        } elseif (!$this->has('selected_option_ids') && $this->has('selected_option')) {
            $singleOpt = $this->input('selected_option');
            if (is_numeric($singleOpt)) {
                $this->merge([
                    'selected_option_ids' => [(int) $singleOpt],
                ]);
            }
        }

        // پشتیبانی از سازگاری: اگر answer ارسال شده بود و answer_text نبود
        if (!$this->has('answer_text') && $this->has('answer')) {
            $this->merge([
                'answer_text' => (string) $this->input('answer'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'exam_snapshot_question_id' => ['required', 'integer'],
            'idempotency_key'           => ['required', 'string', 'max:128'],
            'selected_option_ids'       => ['nullable', 'array'],
            'selected_option_ids.*'     => ['integer'],
            'answer_text'               => ['nullable', 'string', 'max:10000'],
            'time_spent_seconds'        => ['nullable', 'integer', 'min:0'],
            'client_timestamp'          => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'exam_snapshot_question_id.required' => 'شناسه سوال الزامی است.',
            'idempotency_key.required'           => 'کلید یکتایی درخواست (Idempotency Key) الزامی است.',
            'selected_option_ids.array'          => 'فرمت گزینه‌های انتخاب‌شده باید آرایه باشد.',
            'selected_option_ids.*.integer'      => 'شناسه گزینه باید عدد معتبر باشد.',
        ];
    }
}
