<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The assistant reads these rules as the action's input, and authorize() is checked before it runs. */
class ChangeAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order')) && ! $this->route('order')->hasShipped();
    }

    public function rules(): array
    {
        return [
            'street' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:12'],
            'city' => ['required', 'string', 'max:80'],
            'country' => ['required', 'string', 'size:2'],
        ];
    }
}
