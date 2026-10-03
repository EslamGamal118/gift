<?php

namespace App\Http\Requests\Store\Orders;

use App\Services\StoreOrderService;
use App\Support\OrderStateMachine;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body of POST /store/orders/{order}/status. Ownership and payment are
 * checked by the parent (unpaid orders are 404); here the target status must
 * be known and reachable from the order's current status. The store never
 * picks a captain: a `captain_id` in the body is ignored.
 */
class UpdateStoreOrderStatusRequest extends StoreOrderActionRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => strtolower(trim($this->input('status')))]);
        }

        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_keys(StoreOrderService::STATUS_ACTIONS))],
            'reason' => ['required_if:status,cancelled', 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * Reject a status that does not follow the current one (e.g. `completed`
     * on a new order). Concurrent requests are caught again under lock (409).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('status')) {
                return;
            }

            $order = $this->order();
            $next = app(StoreOrderService::class)->nextStatuses($order);

            if (in_array($this->input('status'), $next, true)) {
                return;
            }

            $validator->errors()->add('status', __('orders.status_not_allowed', [
                'status' => OrderStateMachine::statusLabel($order->status),
                'allowed' => $next === [] ? __('orders.no_next_status') : implode(', ', $next),
            ]));
        });
    }

    public function targetStatus(): string
    {
        return (string) $this->validated('status');
    }

    public function reason(): ?string
    {
        return $this->validated('reason');
    }

    public function attributes(): array
    {
        return [
            'status' => __('validation.attributes.status'),
            'reason' => __('validation.attributes.cancellation_reason'),
        ];
    }
}
