<?php

namespace App\Http\Requests\Catalog;

use App\Services\StoreShowService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Product list of a store screen.
 *
 * GET /stores/{store}/products?tab=best_sellers|all|{category_id}&search=..&in_stock=1&sort=..&page=..
 *
 * `tab` mirrors the horizontal filter tabs: the virtual "best sellers" tab,
 * "all", or one of the store's product categories by id.
 */
class StoreProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tab'    => strtolower(trim((string) $this->input('tab', StoreShowService::TAB_BEST_SELLERS))),
            'search' => $this->filled('search') ? trim((string) $this->input('search')) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab'      => ['required', 'regex:/^(all|best_sellers|[1-9]\d*)$/'],
            'search'   => ['nullable', 'string', 'max:100'],
            'in_stock' => ['nullable', 'boolean'],
            'sort'     => ['nullable', Rule::in(StoreShowService::PRODUCT_SORTS)],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function tab(): string
    {
        return $this->validated('tab', StoreShowService::TAB_BEST_SELLERS);
    }

    /**
     * Filters for StoreShowService::products().
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            ['tab' => $this->tab()] + $this->safe()->only(['search', 'in_stock', 'sort']),
            fn ($value) => $value !== null && $value !== ''
        );
    }

    public function attributes(): array
    {
        return [
            'tab'      => __('validation.attributes.tab'),
            'search'   => __('validation.attributes.search'),
            'in_stock' => __('validation.attributes.in_stock'),
            'sort'     => __('validation.attributes.sort'),
            'page'     => __('validation.attributes.page'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }
}
