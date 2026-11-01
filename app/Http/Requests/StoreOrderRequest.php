<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|string|distinct',
            'recipient_name' => 'required|string|max:255',
            'shipping_phone' => 'required|string|max:30',
            'shipping_address' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'item_ids.required' => 'Select at least one item to purchase.',
            'item_ids.*.distinct' => 'The same item was sent more than once.',
        ];
    }
}
