<?php

namespace App\Http\Requests\CustomOrder;

use App\Http\Requests\Catalog\Concerns\ResolvesCustomerLocation;
use App\Services\CustomOrderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Personal shoppers the customer can pick from (step 2).
 *
 * GET /custom-orders/shoppers?category_id=&search=&available_only=1&min_rating=4
 *     &latitude=&longitude=&within_km=&sort=rating|distance|orders|newest&page=&per_page=
 *
 * With a position (GPS, city, or the customer's default address) each
 * shopper carries `distance_km` and the default sort becomes "distance".
 */
class ShopperListRequest extends FormRequest
{
    use ResolvesCustomerLocation;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim((string) $this->input('search')) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id'    => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'search'         => ['nullable', 'string', 'max:100'],
            'available_only' => ['nullable', 'boolean'],
            'min_rating'     => ['nullable', 'numeric', 'between:0,5'],
            'within_km'      => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'sort'           => ['nullable', Rule::in(CustomOrderService::SHOPPER_SORTS)],
            'page'           => ['nullable', 'integer', 'min:1'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ] + $this->locationRules();
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only(['category_id', 'search', 'available_only', 'min_rating', 'within_km', 'sort']),
            fn ($value) => $value !== null && $value !== ''
        );
    }

    public function attributes(): array
    {
        return [
            'category_id'    => __('validation.attributes.category_id'),
            'search'         => __('validation.attributes.search'),
            'available_only' => __('validation.attributes.available_only'),
            'min_rating'     => __('validation.attributes.min_rating'),
            'within_km'      => __('validation.attributes.within_km'),
            'sort'           => __('validation.attributes.sort'),
            'page'           => __('validation.attributes.page'),
            'per_page'       => __('validation.attributes.per_page'),
        ] + $this->locationAttributes();
    }
}
