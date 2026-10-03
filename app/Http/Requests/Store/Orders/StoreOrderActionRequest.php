<?php

namespace App\Http\Requests\Store\Orders;

use App\Exceptions\ApiException;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for every store action on an order: resolves `{order}` from
 * the route and authorises only when the authenticated store owns it.
 * Orders of other stores (and unpaid ones) are reported as not found so
 * their existence is never leaked.
 */
class StoreOrderActionRequest extends FormRequest
{
    protected ?Order $order = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || ! $user->isStore()) {
            return false;
        }

        $this->order = Order::query()
            ->visibleToStore()
            ->find((int) $this->route('order'));

        return $this->order !== null && $this->order->belongsToStore($user->id);
    }

    protected function failedAuthorization(): void
    {
        throw new ApiException('orders.not_found', 404);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The order targeted by the route, owned by the caller.
     */
    public function order(): Order
    {
        return $this->order ?? throw new ApiException('orders.not_found', 404);
    }
}
