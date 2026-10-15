<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Rekhu\Assistant\Http\Middleware\IdentifyVisitor;
use Rekhu\Assistant\Protocol;

/** One message to the assistant: words, or the click on a confirm card. */
final class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['nullable', 'required_without:confirm', 'string', 'max:'.self::maxMessage(IdentifyVisitor::of($this) !== null)],
            'conversation' => ['nullable', 'string', 'max:64'],
            'confirm' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ];
    }

    public function messages(): array
    {
        return ['message.required_without' => __('rekhu-assistant::messages.empty')];
    }

    /** The most characters in one message: config max_message, or guests.max_message on the public chat. */
    public static function maxMessage(bool $visitor): int
    {
        return $visitor
            ? (int) config('rekhu-assistant.guests.max_message', 1000)
            : (int) config('rekhu-assistant.max_message', 4000);
    }

    public function message(): string
    {
        return trim((string) $this->validated('message'));
    }

    public function confirmation(): ?string
    {
        return $this->validated('confirm');
    }

    public function conversation(): ?string
    {
        return $this->validated('conversation');
    }

    public function locale(): ?string
    {
        return $this->validated('locale');
    }

    /** Errors as JSON, also for the widget, which asks for an event stream. */
    protected function failedValidation(Validator $validator): void
    {
        Protocol::refuse(422, (string) $validator->errors()->first(), ['errors' => $validator->errors()->toArray()]);
    }
}
