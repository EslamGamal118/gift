<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\GiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Online gifts on the merchant side: redeeming the recipient's QR code.
 */
class StoreGiftController extends Controller
{
    public function __construct(protected GiftService $gifts) {}

    /**
     * POST /api/v1/store/gifts/redeem  { code }
     *
     * The code scanned from the recipient's QR. 404 for an unknown code or
     * another store's gift; 409 when already redeemed or expired.
     */
    public function redeem(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:64']])['code'];

        $gift = $this->gifts->redeem($request->user(), $code);
        $line = $gift->order?->items->first();

        return ApiResponse::success('gifts.redeem.done', [
            'gift_id' => $gift->id,
            'order_id' => $gift->order_id,
            'order_reference' => $gift->order ? '#'.$gift->order->order_number : null,
            'item_name' => $line?->product_name,
            'quantity' => $line ? (int) $line->quantity : null,
            'recipient_name' => $gift->recipient_name,
            'sender_name' => $gift->sender?->name,
            'redeemed_at' => $gift->redeemed_at?->toIso8601String(),
        ]);
    }
}
