<?php

namespace App\Http\Requests\Order;

use App\Services\UserOrderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /user/orders?tab=active|history&search=&page=&per_page=          (store orders)
 * GET /user/custom-orders?tab=active|history&search=&page=&per_page=   (custom orders)
 *
 * `search` is matched by each model's matchingKeyword scope: order number and
 * product names for both; store name and gift recipient for store orders;
 * notes, item descriptions and shopper name for custom orders.
 */
class UserOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim(preg_replace('/\s+/u', ' ', (string) $this->input('search'))) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab'      => ['nullable', Rule::in(UserOrderService::TABS)],
            'search'   => ['nullable', 'string', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function tab(): ?string
    {
        return $this->validated('tab') ?: null;
    }

    public function search(): ?string
    {
        return $this->validated('search') ?: null;
    }

    public function attributes(): array
    {
        return [
            'tab'      => __('validation.attributes.tab'),
            'search'   => __('validation.attributes.search'),
            'page'     => __('validation.attributes.page'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }
}
