<?php

namespace App\Interfaces;

use Illuminate\Http\Request;

/**
 * Contract for redirect-based payment gateways (AlRajhi / NeoPay).
 * Session-based gateways (Tamara, Tabby) expose their own richer services.
 */
interface PaymentGatewayInterface
{
    /**
     * Start a payment for the store order or custom order.
     *
     * @return array{success: bool, url?: string, message?: string}
     */
    public function sendPayment(Payable $order): array;

    /**
     * Handle the gateway's return / callback request and return the affected payable.
     */
    public function callBack(Request $request): ?Payable;
}
