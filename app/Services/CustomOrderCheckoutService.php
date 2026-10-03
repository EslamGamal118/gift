<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Exceptions\CustomOrderException;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The customer pays a custom order once the shopper has submitted the
 * invoice: the order is re-priced on the server (items + shopper fees +
 * delivery + VAT, see CustomOrderPricing) and a hosted payment page is opened
 * with the chosen gateway through the same PaymentInitiationService as store
 * orders. The gateway's return URL / webhook / callback then marks it paid or
 * failed through PaymentService.
 */
class CustomOrderCheckoutService
{
    /**
     * Other names the app may send for a gateway.
     *
     * @var array<string, string>
     */
    public const GATEWAY_ALIASES = [
        'paymob'  => Order::METHOD_ALRAJHI,   // the card gateway in use is AlRajhi (Neoleap)
        'neoleap' => Order::METHOD_ALRAJHI,
        'card'    => Order::METHOD_ALRAJHI,
    ];

    public function __construct(
        protected PaymentInitiationService $initiator,
        protected CustomOrderPricing $pricing,
    ) {}

    /**
     * @return array{order: CustomOrder, pricing: array<string, mixed>, result: array{success: bool, redirect_url?: string, reference?: string|null, message?: string, rejection_reason?: string}}
     *
     * @throws CustomOrderException  not payable yet / address not found
     * @throws CheckoutException  already paid, or the gateway is not configured
     */
    public function pay(User $customer, int $orderId, string $gateway, ?int $deliveryAddressId = null): array
    {
        $pricing = [];

        $order = DB::transaction(function () use ($customer, $orderId, $deliveryAddressId, &$pricing) {
            /** @var CustomOrder $order */
            $order = CustomOrder::query()->forCustomer($customer->id)->lockForUpdate()->findOrFail($orderId);

            if (! $order->isPayable()) {
                throw $order->isPaid() || $order->status === CustomOrder::STATUS_CANCELLED
                    ? CheckoutException::orderNotPayable()
                    : CustomOrderException::notPayableYet();
            }

            if ($deliveryAddressId !== null) {
                $address = $customer->addresses()->find($deliveryAddressId) ?? throw CustomOrderException::addressNotFound();
                $order->forceFill(CustomOrder::deliveryAddressAttributes($address, $customer));
            }

            // Never trust a stored or sent total: price it again from the items
            $subtotal = $this->pricing->itemsSubtotal($order->items()->get()) ?? throw CustomOrderException::notPayableYet();
            $pricing  = $this->pricing->apply($order, $subtotal, (float) $order->shopper_fees, (float) $order->delivery_fee);

            $order->save();

            return $order;
        });

        // Gateway calls stay outside the transaction (HTTP)
        $result = $this->initiator->start($order, $gateway);

        return ['order' => $order->refresh(), 'pricing' => $pricing, 'result' => $result];
    }
}
