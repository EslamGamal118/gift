<?php

namespace App\Http\Requests\Search;

use App\Http\Requests\Catalog\Concerns\ResolvesCustomerLocation;
use App\Models\SearchHistory;
use App\Services\CatalogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /search?q=...&type=all|stores|products|categories
 *
 * Sorting depends on the type: stores accept nearest|rating|newest|name,
 * products accept newest|price_asc|price_desc|name. `type=all` returns a
 * short list per group; a specific type returns a paginated list.
 */
class SearchRequest extends FormRequest
{
    use ResolvesCustomerLocation;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q'    => SearchHistory::normalizeKeyword((string) $this->input('q', $this->input('keyword', ''))),
            'type' => strtolower((string) $this->input('type', SearchHistory::TYPE_ALL)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sorts = match ($this->input('type')) {
            SearchHistory::TYPE_STORES   => CatalogService::STORE_SORTS,
            SearchHistory::TYPE_PRODUCTS => CatalogService::PRODUCT_SORTS,
            default                      => ['relevance'],
        };

        return [
            'q'          => ['required', 'string', 'min:1', 'max:100'],
            'type'       => ['required', Rule::in(SearchHistory::TYPES)],
            'sort'       => ['nullable', Rule::in($sorts)],
            'page'       => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'save'       => ['nullable', 'boolean'],
            'open_now'   => ['nullable', 'boolean'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'within_km'  => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'in_stock'   => ['nullable', 'boolean'],
            'price_min'  => ['nullable', 'numeric', 'min:0'],
            'price_max'  => array_filter(['nullable', 'numeric', 'min:0', $this->filled('price_min') ? 'gte:price_min' : null]),
        ] + $this->locationRules();
    }

    public function keyword(): string
    {
        return $this->validated('q');
    }

    public function type(): string
    {
        return $this->validated('type', SearchHistory::TYPE_ALL);
    }

    /**
     * Whether the keyword should be recorded in the recent searches of the caller (default: yes).
     */
    public function shouldSave(): bool
    {
        return $this->boolean('save', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function storeFilters(): array
    {
        $filters = $this->only(['open_now', 'min_rating', 'within_km']);
        $filters['search'] = $this->keyword();

        if ($this->type() === SearchHistory::TYPE_STORES && $this->filled('sort')) {
            $filters['sort'] = $this->validated('sort');
        }

        if ($coordinates = $this->coordinates()) {
            $filters += $coordinates;
        }

        return $this->clean($filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function productFilters(): array
    {
        $filters = $this->only(['in_stock', 'price_min', 'price_max']);
        $filters['search'] = $this->keyword();

        if ($this->type() === SearchHistory::TYPE_PRODUCTS && $this->filled('sort')) {
            $filters['sort'] = $this->validated('sort');
        }

        return $this->clean($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function clean(array $filters): array
    {
        return array_filter($filters, fn ($value) => $value !== null && $value !== '');
    }

    public function attributes(): array
    {
        return [
            'q'          => __('validation.attributes.keyword'),
            'type'       => __('validation.attributes.type'),
            'sort'       => __('validation.attributes.sort'),
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
