<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ResolvesCustomerLocation;
use App\Services\CatalogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /categories/{category}/listings
 *
 * Both types : search, category_ids[] (any of them; default: the route category),
 *              sort (popular|most_popular|price_asc|price_desc|rating_desc|top_rated)
 * type=stores   : latitude, longitude | city, within_km, min_rating, open_now
 *                 Without coordinates: cached last GPS position / saved address of the
 *                 signed-in customer, else no distance (sorted by rating).
 *                 Without `sort`: nearest, highest rated, best selling; all stores of the
 *                 categories are paginated unless `within_km` is given.
 * type=products : in_stock, price_min, price_max (without `sort`: newest first)
 */
class CategoryListingRequest extends FormRequest
{
    use ResolvesCustomerLocation;

    public const TYPE_STORES   = 'stores';
    public const TYPE_PRODUCTS = 'products';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $categoryIds = $this->input('category_ids');

        $this->merge([
            'type'   => strtolower((string) $this->input('type', self::TYPE_STORES)),
            'search' => $this->filled('search') ? trim((string) $this->input('search')) : null,
            'sort'   => $this->filled('sort') ? strtolower(trim((string) $this->input('sort'))) : null,
            // Also accept "1,2,3"
            'category_ids' => is_string($categoryIds) ? array_values(array_filter(explode(',', $categoryIds), 'strlen')) : $categoryIds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type'       => ['required', Rule::in([self::TYPE_STORES, self::TYPE_PRODUCTS])],
            'search'     => ['nullable', 'string', 'max:100'],
            'sort'       => ['nullable', Rule::in(CatalogService::LISTING_SORTS)],
            'category_ids'   => ['nullable', 'array', 'max:20'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'page'       => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:100'],

            // Stores
            'within_km'  => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'open_now'   => ['nullable', 'boolean'],

            // Products
            'in_stock'   => ['nullable', 'boolean'],
            'price_min'  => ['nullable', 'numeric', 'min:0'],
            'price_max'  => array_filter(['nullable', 'numeric', 'min:0', $this->filled('price_min') ? 'gte:price_min' : null]),
        ] + $this->locationRules();
    }

    public function type(): string
    {
        return $this->validated('type', self::TYPE_STORES);
    }

    public function isProducts(): bool
    {
        return $this->input('type') === self::TYPE_PRODUCTS;
    }

    /**
     * Filters for CatalogService, keyed as it expects.
     *
     * @return array<string, mixed>
     */
    public function filters(int $categoryId): array
    {
        $filters = array_filter(
            $this->safe()->only(['search', 'sort', 'within_km', 'min_rating', 'open_now', 'in_stock', 'price_min', 'price_max']),
            fn ($value) => $value !== null && $value !== ''
        );

        $filters['category_ids'] = array_map('intval', $this->validated('category_ids') ?: [$categoryId]);

        return $filters;
    }

    /**
     * Same as the home screen: a signed-in customer's GPS position is cached,
     * and reused when a later request comes without coordinates.
     */
    protected function remembersLocation(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return [
            'type'       => __('validation.attributes.type'),
            'search'     => __('validation.attributes.search'),
            'sort'       => __('validation.attributes.sort'),
            'category_ids'   => __('validation.attributes.category_id'),
            'category_ids.*' => __('validation.attributes.category_id'),
            'page'       => __('validation.attributes.page'),
            'per_page'   => __('validation.attributes.per_page'),
            'within_km'  => __('validation.attributes.within_km'),
            'min_rating' => __('validation.attributes.min_rating'),
            'open_now'   => __('validation.attributes.open_now'),
            'in_stock'   => __('validation.attributes.in_stock'),
            'price_min'  => __('validation.attributes.price_min'),
            'price_max'  => __('validation.attributes.price_max'),
        ] + $this->locationAttributes();
    }
}
