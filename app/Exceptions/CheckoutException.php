<?php

namespace App\Exceptions;

/**
 * Cart / checkout / payment flow errors rendered through ApiResponse (see Handler).
 * Field-level problems (e.g. adding an item from another store) are raised as
 * ValidationException instead so the client gets them under `errors`.
 */
class CheckoutException extends ApiException
{
    public static function cartEmpty(): self
    {
        return new self('checkout.cart_empty', 422);
    }

    public static function itemNotFound(): self
    {
        return new self('checkout.item_not_found', 404);
    }

    public static function storeUnavailable(): self
    {
        return new self('checkout.store_unavailable', 422);
    }

    public static function productUnavailable(string $name): self
    {
        return new self('checkout.product_unavailable', 422, ['product' => $name], ['product' => $name]);
    }

    public static function insufficientStock(string $name, int $available): self
    {
        return new self('checkout.insufficient_stock', 422, [
            'product' => $name,
            'available' => $available,
        ], ['product' => $name, 'available' => $available]);
    }

    public static function addressRequired(): self
    {
        return new self('checkout.address_required', 422);
    }

    public static function deliveryRequired(): self
    {
        return new self('checkout.delivery_required', 422);
    }

    public static function instantUnavailable(): self
    {
        return new self('checkout.instant_unavailable', 422);
    }

    public static function slotUnavailable(): self
    {
        return new self('checkout.slot_unavailable', 422);
    }

    public static function promoInvalid(string $reasonKey): self
    {
        return new self('checkout.promo.'.$reasonKey, 422, ['reason' => $reasonKey]);
    }

    public static function orderNotPayable(): self
    {
        return new self('checkout.order_not_payable', 409);
    }

    public static function orderNotCancellable(): self
    {
        return new self('checkout.order_not_cancellable', 409);
    }

    public static function gatewayUnavailable(string $gateway): self
    {
        return new self('checkout.gateway_unavailable', 503, ['gateway' => $gateway], ['gateway' => $gateway]);
    }

    public static function gatewayError(string $message): self
    {
        return new self('checkout.gateway_error', 502, ['detail' => $message], ['detail' => $message]);
    }
}
