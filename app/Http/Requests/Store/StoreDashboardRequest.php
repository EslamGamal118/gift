<?php

namespace App\Http\Requests\Store;

use App\Services\StoreDashboardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /store/dashboard?period=month&orders_limit=5
 */
class StoreDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStore() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(StoreDashboardService::PERIODS)],
            'orders_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function period(): string
    {
        return $this->validated('period') ?? 'month';
    }

    public function ordersLimit(): int
    {
        return (int) ($this->validated('orders_limit') ?? 5);
    }


    public function attributes(): array
    {
        return [
            'period' => __('validation.attributes.period'),
            'orders_limit' => __('validation.attributes.orders_limit'),
        ];
    }
}
