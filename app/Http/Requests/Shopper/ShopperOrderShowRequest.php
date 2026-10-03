<?php

namespace App\Http\Requests\Shopper;

/**
 * GET /shopper/orders/{customOrder}
 *
 * Shoppers only (403 otherwise); the order must be assigned to them and
 * confirmed (404 otherwise, see ShopperOrderActionRequest).
 */
class ShopperOrderShowRequest extends ShopperOrderActionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
