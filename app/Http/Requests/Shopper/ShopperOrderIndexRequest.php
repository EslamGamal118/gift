<?php

namespace App\Http\Requests\Shopper;

use App\Services\ShopperOrderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /shopper/orders?tab=active|history&status=new|accepted|in_progress|waiting_for_alternative|completed|cancelled&search=&page=&per_page=
 *
 * `tab` splits current work (الحالية) from past orders (السابقة); `status`
 * narrows to one badge inside it (also accepts pending / confirmed / canceled);
 * `search` matches the order number, customer / recipient, items and notes.
 */
class ShopperOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isShopper() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->filled('status') ? strtolower(trim((string) $this->input('status'))) : null;

        $this->merge([
            'tab'    => $this->filled('tab') ? strtolower(trim((string) $this->input('tab'))) : null,
            'status' => $status !== null ? (ShopperOrderService::STATUS_ALIASES[$status] ?? $status) : null,
            'search' => $this->filled('search') ? trim((string) $this->input('search')) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab'      => ['nullable', Rule::in(ShopperOrderService::TABS)],
            'status'   => ['nullable', Rule::in(array_keys(ShopperOrderService::STATUS_KEYS))],
            'search'   => ['nullable', 'string', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{tab: ?string, status: ?string, search: ?string}
     */
    public function filters(): array
    {
        return [
            'tab'    => $this->validated('tab') ?: null,
            'status' => $this->validated('status') ?: null,
            'search' => $this->validated('search') ?: null,
        ];
    }

    public function attributes(): array
    {
        return [
            'tab'      => __('validation.attributes.tab'),
            'status'   => __('validation.attributes.status'),
            'search'   => __('validation.attributes.search'),
            'page'     => __('validation.attributes.page'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }
}
