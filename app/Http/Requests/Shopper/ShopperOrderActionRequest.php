<?php

namespace App\Http\Requests\Shopper;

use App\Models\CustomOrder;
use App\Services\ShopperOrderService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for actions on one of the shopper's orders (route `{customOrder}`).
 * Another shopper's order, a draft or an unknown id is a 404.
 */
abstract class ShopperOrderActionRequest extends FormRequest
{
    protected ?CustomOrder $customOrder = null;

    public function authorize(): bool
    {
        return $this->user()?->isShopper() ?? false;
    }

    public function order(): CustomOrder
    {
        return $this->customOrder ??= app(ShopperOrderService::class)
            ->findForShopper($this->user(), (int) $this->route('customOrder'));
    }
}
