<?php

namespace App\Http\Requests\Review;

use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /orders/{id}/review  |  POST /custom-orders/{id}/review
 * { store_rating: 1-5, store_comment?, products_rating: 1-5, products_comment? }
 */
class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['store_comment', 'products_comment'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) ?: null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $stars = ['required', 'integer', 'between:'.Review::MIN_RATING.','.Review::MAX_RATING];

        return [
            'store_rating'     => $stars,
            'store_comment'    => ['nullable', 'string', 'max:500'],
            'products_rating'  => $stars,
            'products_comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{store_rating: int, store_comment: ?string, products_rating: int, products_comment: ?string}
     */
    public function review(): array
    {
        return [
            'store_rating'     => (int) $this->validated('store_rating'),
            'store_comment'    => $this->validated('store_comment'),
            'products_rating'  => (int) $this->validated('products_rating'),
            'products_comment' => $this->validated('products_comment'),
        ];
    }

    public function attributes(): array
    {
        return [
            'store_rating'     => __('validation.attributes.store_rating'),
            'store_comment'    => __('validation.attributes.store_comment'),
            'products_rating'  => __('validation.attributes.products_rating'),
            'products_comment' => __('validation.attributes.products_comment'),
        ];
    }
}
