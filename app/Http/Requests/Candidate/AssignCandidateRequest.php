<?php

namespace App\Http\Requests\Candidate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * اعتبارسنجی تخصیص متقاضی به آزمون، شفت، لابراتوار، و کامپیوتر.
 * بند 11، 59.1 SRS.
 */
class AssignCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('candidates.update');
    }

    public function rules(): array
    {
        return [
            'exam_id'     => ['required', 'integer', 'exists:exams,id'],
            'shift_id'    => ['required', 'integer', 'exists:shifts,id'],
            'lab_id'      => ['required', 'integer', 'exists:labs,id'],
            'computer_id' => ['nullable', 'integer', 'exists:computers,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'exam_id.required'  => 'انتخاب آزمون الزامی است.',
            'exam_id.exists'    => 'آزمون انتخاب‌شده معتبر نیست.',
            'shift_id.required' => 'انتخاب شفت الزامی است.',
            'shift_id.exists'   => 'شفت انتخاب‌شده معتبر نیست.',
            'lab_id.required'   => 'انتخاب لابراتوار الزامی است.',
            'lab_id.exists'     => 'لابراتوار انتخاب‌شده معتبر نیست.',
            'computer_id.exists'=> 'کامپیوتر انتخاب‌شده معتبر نیست.',
        ];
    }
}
