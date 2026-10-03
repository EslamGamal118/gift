<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\DeliveryQuoteRequest;
use App\Http\Requests\Checkout\DeliverySelectionRequest;
use App\Http\Resources\Checkout\CartResource;
use App\Services\CartService;
use App\Services\DeliverySchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Delivery time" screen: available dates / slots, the instant option, fees.
 */
class DeliveryController extends Controller
{
    public function __construct(
        protected CartService $carts,
        protected DeliverySchedulingService $scheduling,
    ) {}

    /**
     * GET /api/v1/checkout/delivery/options
     *
     * Dates with their slots (available only when every store in the cart is
     * open) plus the instant-delivery option with its fee (summed over the
     * cart's stores) and ETA.
     */
    public function options(Request $request): JsonResponse
    {
        $cart = $this->carts->forUser($request->user());

        return ApiResponse::success('messages.success', $this->scheduling->options($this->scheduling->storesOf($cart)) + [
            'selected' => [
                'type' => $cart->delivery_type,
                'date' => $cart->delivery_date?->toDateString(),
                'slot_id' => $cart->delivery_slot_id,
            ],
        ]);
    }

    /**
     * GET /api/v1/checkout/delivery/quote?delivery_type=instant
     *
     * Delivery + express fee for a delivery type without saving it (summed
     * over the cart's stores, with the per-store breakdown).
     */
    public function quote(DeliveryQuoteRequest $request): JsonResponse
    {
        $cart = $this->carts->forUser($request->user());
        $type = $request->validated('delivery_type') ?? $cart->delivery_type;

        $address = $request->filled('address_id')
            ? $request->user()->addresses()->find($request->integer('address_id'))
            : $cart->address;

        return ApiResponse::success('messages.success', $this->scheduling->quoteForCart($cart, $address, $type));
    }

    /**
     * POST /api/v1/checkout/delivery
     *
     * Save the chosen slot (or instant delivery) on the cart and return the fee.
     */
    public function select(DeliverySelectionRequest $request): JsonResponse
    {
        $cart = $this->carts->forUser($request->user());

        $cart = $this->scheduling->select($cart, $request->deliveryType(), $request->deliveryDate(), $request->slotId());
        $cart->load('deliverySlot');

        return ApiResponse::success('checkout.delivery_saved', [
            'cart' => new CartResource($cart),
            'quote' => $this->scheduling->quoteForCart($cart, $cart->address, $cart->delivery_type),
        ]);
    }
}
