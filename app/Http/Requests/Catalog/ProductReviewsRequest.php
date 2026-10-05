<?php

namespace App\Http\Requests\Catalog;

use App\Models\Review;
use App\Services\ReviewFeedService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /products/{product}/reviews?sort=newest|latest|highest|lowest&stars=1..5&with_comment=1&page=..&per_page=..
 *
 * `latest` is accepted as an alias of `newest`.
 */
class ProductReviewsRequest extends FormRequest
{
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
            'sort'         => ['nullable', Rule::in([...ReviewFeedService::SORTS, 'latest'])],
            'stars'        => ['nullable', 'integer', 'between:'.Review::MIN_RATING.','.Review::MAX_RATING],
            'with_comment' => ['nullable', 'boolean'],
            'page'         => ['nullable', 'integer', 'min:1'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{stars: ?int, with_comment: bool, sort: string}
     */
    public function filters(): array
    {
        $sort = $this->validated('sort') ?? 'newest';

        return [
            'stars'        => $this->filled('stars') ? (int) $this->validated('stars') : null,
            'with_comment' => $this->boolean('with_comment'),
            'sort'         => $sort === 'latest' ? 'newest' : $sort,
        ];
    }

    public function attributes(): array
    {
        return [
            'page'     => __('validation.attributes.page'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }
}
