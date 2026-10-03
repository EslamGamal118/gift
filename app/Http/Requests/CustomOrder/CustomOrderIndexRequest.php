<?php

namespace App\Http\Requests\CustomOrder;

use App\Models\CustomOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /custom-orders?status=pending&page=&per_page=
 */
class CustomOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status'   => ['nullable', Rule::in(CustomOrder::STATUSES)],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function status(): ?string
    {
        return $this->validated('status') ?: null;
    }

    public function attributes(): array
    {
        return [
            'status'   => __('validation.attributes.status'),
            'page'     => __('validation.attributes.page'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }
}
