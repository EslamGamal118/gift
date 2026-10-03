<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Interfaces\Payable;
use App\Models\Order;
use App\Models\PaymentTransaction;

/**
 * Opens a hosted payment page for a payable (store order, multi-store
 * checkout or custom order) with the chosen gateway. Shared by
 * POST /orders/{order}/pay, POST /checkouts/{checkout}/pay,
 * POST /gifts/checkout and POST /checkout/pay; the result is confirmed later
 * by the gateway's callback / webhook through PaymentService.
 */
class PaymentInitiationService
{
    public function __construct(protected PaymentService $payments) {}

    /**
     * @return array{success: bool, redirect_url?: string, reference?: string|null, message?: string, rejection_reason?: string}
     *
     * @throws CheckoutException when the order cannot be paid or the gateway is not configured
     */
    public function start(Payable $order, string $gateway): array
    {
        // A store order split from a multi-store checkout is paid with its siblings
        if ($order instanceof Order) {
            $order = $order->payableForPayment();
        }

        if (! $order->isPayable()) {
            throw CheckoutException::orderNotPayable();
        }

        $order->forceFill(['payment_method' => $gateway])->save();

        return match ($gateway) {
            Order::METHOD_TABBY => $this->viaTabby($order),
            Order::METHOD_TAMARA => $this->viaTamara($order),
            Order::METHOD_ALRAJHI => $this->viaAlRajhi($order),
        };
    }

    /**
     * @return array{success: bool, redirect_url?: string, reference?: string|null, message?: string, rejection_reason?: string}
     */
    protected function viaTabby(Payable $order): array
    {
        $tabby = app(TabbyService::class);

        if (! $tabby->isConfigured()) {
            throw CheckoutException::gatewayUnavailable('Tabby');
        }

        $result = $tabby->createCheckoutSession($order);

        return $result['success']
            ? ['success' => true, 'redirect_url' => $result['checkout_url'], 'reference' => $result['payment_id']]
            : $result;
    }

    /**
     * @return array{success: bool, redirect_url?: string, reference?: string|null, message?: string}
     */
    protected function viaTamara(Payable $order): array
    {
        $tamara = app(TamaraService::class);

        if (! $tamara->isConfigured()) {
            throw CheckoutException::gatewayUnavailable('Tamara');
        }

        $result = $tamara->createCheckoutSession($order);

        if (! $result['success']) {
            return $result;
        }

        $this->payments->recordTransaction($order, Order::METHOD_TAMARA, 'session_created', PaymentTransaction::STATUS_INITIATED, $result['tamara_order_id'], [
            'checkout_id' => $result['checkout_id'] ?? null,
        ]);

        return ['success' => true, 'redirect_url' => $result['checkout_url'], 'reference' => $result['tamara_order_id']];
    }

    /**
     * @return array{success: bool, redirect_url?: string, reference?: string|null, message?: string}
     */
    protected function viaAlRajhi(Payable $order): array
    {
        if (! config('services.alrajhi.base_url') || ! config('services.alrajhi.transportal_id')) {
            throw CheckoutException::gatewayUnavailable('AlRajhi');
        }

        $result = app(AlRajhiService::class)->sendPayment($order);

        if (! $result['success']) {
            return ['success' => false, 'message' => 'checkout.gateway_error'];
        }

        $this->payments->recordTransaction($order, Order::METHOD_ALRAJHI, 'session_created', PaymentTransaction::STATUS_INITIATED, null, [
            'url' => $result['url'],
        ]);

        return ['success' => true, 'redirect_url' => $result['url'], 'reference' => null];
    }
}
