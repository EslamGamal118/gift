<?php

namespace App\Http\Requests\Catalog;

use App\Models\StoreReview;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /stores/{store}/reviews?rating=5&page=..
 */
class StoreReviewsRequest extends FormRequest
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
            'rating'   => ['nullable', 'integer', 'between:'.StoreReview::MIN_RATING.','.StoreReview::MAX_RATING],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function rating(): ?int
    {
        return $this->filled('rating') ? (int) $this->validated('rating') : null;
    }

    public function attributes(): array
    {
        return [
            'rating'   => __('validation.attributes.rating'),
            'page'     => __('validation.attributes.page'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }
}
