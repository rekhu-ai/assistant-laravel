<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Rekhu\Assistant\Protocol;
use Rekhu\Assistant\Runtime\Reports;

/** A report on one answer: which answer, what went wrong, and what the user expected instead. */
final class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'conversation' => ['required', 'string', 'max:64'],
            'message' => ['required', 'string', 'regex:/^m_\d{1,9}$/'],
            'reason' => ['required', 'string', Rule::in(Reports::keys())],
            'comment' => ['nullable', 'string', 'max:'.Reports::maxComment()],
            'locale' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ];
    }

    /** Errors as JSON, also for a client that asks for something else. */
    protected function failedValidation(Validator $validator): void
    {
        Protocol::refuse(422, (string) $validator->errors()->first(), ['errors' => $validator->errors()->toArray()]);
    }
}
