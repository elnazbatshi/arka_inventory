<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // احراز هویت و مجوز عضویت شرکت بعداً اضافه می‌شود.
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['in', 'out', 'transfer'])],
            'warehouse_id' => ['required', 'integer', 'min:1'],

            'to_warehouse_id' => [
                'required_if:type,transfer',
                'prohibited_unless:type,transfer',
                'integer',
                'min:1',
                'different:warehouse_id',
            ],

            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:4294967295',
            ],

            'reference' => ['nullable', 'string', 'max:255'],
            'tenant_id' => ['prohibited'],
        ];
    }
}
