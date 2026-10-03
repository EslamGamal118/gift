<?php

namespace App\Http\Requests\Store\Orders;

use App\Models\Order;
use App\Services\StoreOrderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the store's order list. `status` accepts one status, a comma
 * separated list, or the aliases `all` / `active`. `status_type` picks the
 * tab: `current` (الحالية, in progress) or `previous` (السابقة, delivered or
 * cancelled); combined with `status` it narrows that list.
 */
class StoreOrderIndexRequest extends FormRequest
{
    public const STATUS_ALIASES = ['all', 'active'];

    public const STATUS_TYPES = [
        'current' => Order::ACTIVE_STATUSES,
        'previous' => Order::HISTORY_STATUSES,
    ];

    public function authorize(): bool
    {
        return $this->user()?->isStore() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');

        if (is_string($status)) {
            $this->merge(['status' => array_values(array_filter(array_map('trim', explode(',', $status))))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statuses = array_merge(self::STATUS_ALIASES, Order::ACTIVE_STATUSES, [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED]);

        return [
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::in($statuses)],
            'status_type' => ['nullable', Rule::in(array_keys(self::STATUS_TYPES))],
            'search' => ['nullable', 'string', 'max:100'],
            'date_range' => ['nullable', Rule::in(StoreOrderService::DATE_RANGES)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Normalised filters for StoreOrderService.
     *
     * @return array{status: list<string>|null, status_type: string|null, search: string|null, date_range: string|null, date_from: string|null, date_to: string|null}
     */
    public function filters(): array
    {
        $statuses = $this->validated('status') ?? [];

        if (in_array('all', $statuses, true) || $statuses === []) {
            $statuses = null;
        } elseif (in_array('active', $statuses, true)) {
            $statuses = array_values(array_unique(array_merge(Order::ACTIVE_STATUSES, array_diff($statuses, ['active']))));
        }

        if ($type = $this->validated('status_type')) {
            $statuses = array_values(array_intersect($statuses ?? self::STATUS_TYPES[$type], self::STATUS_TYPES[$type]));
        }

        return [
            'status' => $statuses,
            'status_type' => $this->validated('status_type'),
            'search' => $this->validated('search'),
            'date_range' => $this->validated('date_range'),
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => __('validation.attributes.status'),
            'status_type' => __('validation.attributes.status_type'),
            'search' => __('validation.attributes.search'),
            'date_range' => __('validation.attributes.date_range'),
            'date_from' => __('validation.attributes.date_from'),
            'date_to' => __('validation.attributes.date_to'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }
}
