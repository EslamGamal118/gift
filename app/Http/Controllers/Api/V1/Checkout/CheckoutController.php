<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\ApplyPromoRequest;
use App\Http\Requests\Checkout\GiftMessageRequest;
use App\Http\Requests\Checkout\PlaceOrderRequest;
use App\Http\Requests\Checkout\SelectAddressRequest;
use App\Http\Resources\Checkout\CartItemResource;
use App\Http\Resources\Checkout\CheckoutGroupResource;
use App\Http\Resources\Checkout\UserAddressResource;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\DeliverySchedulingService;
use App\Services\PaymentInitiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checkout review screen: address, delivery, gift message, promo code,
 * financial breakdown (per store and in total) and order placement.
 */
class CheckoutController extends Controller
{
    use ResolvesFileUrls;

    public function __construct(
        protected CartService $carts,
        protected CheckoutService $checkout,
        protected DeliverySchedulingService $scheduling,
        protected PaymentInitiationService $payments,
    ) {}

    /**
     * GET /api/v1/checkout/summary
     */
    public function summary(Request $request): JsonResponse
    {
        return ApiResponse::success('messages.success', $this->summaryPayload($request));
    }

    /**
     * POST /api/v1/checkout/address  { address_id }
     */
    public function selectAddress(SelectAddressRequest $request): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($request->integer('address_id'));

        $this->checkout->selectAddress($this->carts->forUser($request->user(), false), $address);

        return ApiResponse::success('checkout.address_selected', $this->summaryPayload($request));
    }

    /**
     * PUT /api/v1/checkout/gift-message  { gift_message }
     */
    public function giftMessage(GiftMessageRequest $request): JsonResponse
    {
        $this->checkout->setGiftMessage($this->carts->forUser($request->user(), false), $request->validated('gift_message'));

        return ApiResponse::success('checkout.gift_message_saved', $this->summaryPayload($request));
    }

    /**
     * POST /api/v1/checkout/promo  { code }
     */
    public function applyPromo(ApplyPromoRequest $request): JsonResponse
    {
        $cart = $this->carts->forUser($request->user());

        $this->checkout->applyPromo($cart, $request->user(), $request->validated('code'));

        return ApiResponse::success('checkout.promo_applied', $this->summaryPayload($request));
    }

    /**
     * DELETE /api/v1/checkout/promo
     */
    public function removePromo(Request $request): JsonResponse
    {
        $this->checkout->removePromo($this->carts->forUser($request->user(), false));

        return ApiResponse::success('checkout.promo_removed', $this->summaryPayload($request));
    }

    /**
     * POST /api/v1/checkout/place-order  { payment_method, ...optional overrides }
     *
     * Freezes the cart into a checkout and opens the gateway's payment page
     * for it (`payment.redirect_url`). No order is created, no stock is taken
     * and the cart is kept: the per-store orders are created and the cart
     * cleared only once the gateway confirms the payment.
     */
    public function placeOrder(PlaceOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $cart = $this->carts->forUser($user);

        // Optional last-minute overrides from the review screen
        if ($request->filled('address_id')) {
            $this->checkout->selectAddress($cart, $user->addresses()->findOrFail($request->integer('address_id')));
        }
        // Delivery can be picked here instead of via POST /checkout/delivery
        if ($request->hasDeliverySelection()) {
            $this->scheduling->select($cart, $request->deliveryType(), $request->deliveryDate(), $request->slotId());
        }
        if ($request->has('gift_message')) {
            $this->checkout->setGiftMessage($cart, $request->validated('gift_message'));
        }
        if ($request->filled('promo_code')) {
            $this->checkout->applyPromo($cart, $user, $request->validated('promo_code'));
        }

        // Nothing is ordered yet: the cart is frozen into a checkout, paid next
        $checkout = $this->checkout->startCheckout($user, $request->paymentMethod());
        $result = $this->payments->start($checkout, $checkout->payment_method);

        $data = [
            'checkout' => new CheckoutGroupResource($checkout),
            'payment' => [
                'gateway' => $checkout->payment_method,
                'amount' => (float) $checkout->total_amount,
                'currency' => $checkout->currency,
                'redirect_url' => $result['redirect_url'] ?? null,
                'gateway_reference' => $result['reference'] ?? null,
            ],
        ];

        // The checkout stays payable: the app can retry with POST /checkouts/{id}/pay
        if (! $result['success']) {
            $data['payment'] += isset($result['rejection_reason']) ? ['rejection_reason' => $result['rejection_reason']] : [];

            return ApiResponse::send(422, $result['message'] ?? 'checkout.gateway_error', $data, ['gateway' => $checkout->payment_method, 'detail' => '']);
        }

        return ApiResponse::send(201, 'checkout.payment_initiated', $data);
    }

    /**
     * Summary array with models rendered through their resources.
     *
     * @return array<string, mixed>
     */
    protected function summaryPayload(Request $request): array
    {
        $cart = $this->carts->forUser($request->user());
        $summary = $this->checkout->summary($cart, $request->user());

        return [
            // Every line of every store in one flat list, each with its own store
            // (`store.id` matches `stores[].store.id`); in the order they were added
            'items' => CartItemResource::collection($cart->items->sortBy('id')->values()),
            // One entry per store = one order once placed: its delivery and totals
            'stores' => collect($summary['stores'])->map(fn (array $share) => [
                'store' => [
                    'id' => $share['store_id'],
                    'store_name' => $share['store']?->store_name,
                    'logo' => $this->fileUrl($share['store']?->logo),
                ],
                'items_count' => (int) $share['items']->sum('quantity'),
                'delivery' => $share['delivery'],
                'totals' => $share['totals'],
            ])->values(),
            'address' => $summary['address'] ? new UserAddressResource($summary['address']) : null,
        ] + collect($summary)->except(['cart', 'stores', 'address'])->all();
    }
}
