<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ResolvesCustomerLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /stores/nearby?latitude=..&longitude=..&within_km=10&category_id=3&open_now=1&min_rating=4
 *
 * A position is required: GPS coordinates, a `city`, or (when authenticated)
 * a saved default address. Results are always sorted nearest-first.
 */
class NearbyStoresRequest extends FormRequest
{
    use ResolvesCustomerLocation;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'within_km'   => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'min_rating'  => ['nullable', 'numeric', 'min:0', 'max:5'],
            'open_now'    => ['nullable', 'boolean'],
            'search'      => ['nullable', 'string', 'max:100'],
            'page'        => ['nullable', 'integer', 'min:1'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ] + $this->locationRules();
    }

    /**
     * Once the fields are valid, make sure some position could be resolved.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->location() === null) {
                $validator->errors()->add('latitude', __('home.location_required'));
            }
        });
    }

    /**
     * Filters for CatalogService::nearbyStores().
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only(['within_km', 'category_id', 'min_rating', 'open_now', 'search']),
            fn ($value) => $value !== null && $value !== ''
        );
    }

    public function attributes(): array
    {
        return [
            'within_km'   => __('validation.attributes.within_km'),
            'category_id' => __('validation.attributes.category_id'),
            'min_rating'  => __('validation.attributes.min_rating'),
            'open_now'    => __('validation.attributes.open_now'),
            'search'      => __('validation.attributes.search'),
            'page'        => __('validation.attributes.page'),
            'per_page'    => __('validation.attributes.per_page'),
        ] + $this->locationAttributes();
    }
}
