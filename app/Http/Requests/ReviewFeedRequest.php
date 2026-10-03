<?php

namespace App\Http\Requests;

use App\Services\ReviewFeedService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of a "Reviews" screen: ?stars=1..5&with_comment=1&sort=newest|highest|lowest
 * (+ ?subject=all|store|shopper on the customer's given reviews). The role is
 * checked by the route's `role:` middleware.
 */
class ReviewFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stars' => ['nullable', 'integer', 'between:1,5'],
            'with_comment' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(ReviewFeedService::SORTS)],
            'subject' => ['nullable', Rule::in(['all', 'store', 'shopper'])],
        ];
    }

    /**
     * @return array{stars: ?int, with_comment: bool, sort: string, subject: string}
     */
    public function filters(): array
    {
        return [
            'stars' => $this->filled('stars') ? (int) $this->validated('stars') : null,
            'with_comment' => $this->boolean('with_comment'),
            'sort' => $this->validated('sort') ?? 'newest',
            'subject' => $this->validated('subject') ?? 'all',
        ];
    }
}
