<?php

namespace App\Http\Requests\Order;

use App\Services\UserOrderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /user/orders/{id}
 *
 * A store order. `type=custom` is still honoured for app versions released
 * before GET /user/custom-orders/{id}; store and custom orders have separate
 * id sequences, so the type is never guessed.
 */
class UserOrderShowRequest extends FormRequest
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
            'type' => ['nullable', Rule::in(UserOrderService::TYPES)],
        ];
    }

    public function type(): string
    {
        return $this->validated('type') ?? UserOrderService::TYPE_STANDARD;
    }

    public function attributes(): array
    {
        return [
            'type' => __('validation.attributes.type'),
        ];
    }
}
