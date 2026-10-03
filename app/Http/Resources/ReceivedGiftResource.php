<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\Gift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A gift the customer received, as the "Online gifts" card and the details
 * screen opened by "Open my gift" show it. The QR code (to scan at the store)
 * is only given while the gift is usable. Expects GiftService::DETAIL_RELATIONS.
 *
 * @mixin Gift
 */
class ReceivedGiftResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $line = $this->order?->items->first();
        $profile = $this->store?->storeProfile;
        $status = $this->status();
        $locale = app()->getLocale();

        return [
            'gift_id' => $this->id,
            'item_name' => $line?->product_name,
            'item_image' => $this->fileUrl($line?->product_image),
            'quantity' => $line ? (int) $line->quantity : null,

            'qr_code' => $status === Gift::STATUS_ACTIVE ? [
                'value' => $this->redemption_code,      // render as QR in the app
                'format' => 'qr',
            ] : null,

            'store' => [
                'id' => $profile?->id,                   // GET /api/v1/stores/{id}
                'name' => $profile?->store_name ?? $this->store?->name,
                'logo' => $this->fileUrl($profile?->logo),
                'rating' => round((float) $profile?->rating_avg, 1),
            ],
            // Where to redeem it: the store's main branch
            'address' => $profile?->mainBranch?->address,

            'sender_name' => $this->sender?->name ?? __('gifts.someone'),
            'sender_avatar' => $this->fileUrl($this->sender?->avatar),
            'gift_message' => $this->gift_message,

            'expiry_date' => $this->expires_at?->copy()->timezone(config('app.timezone'))->locale($locale)->translatedFormat('j F Y'),
            'expires_at' => $this->expires_at?->toIso8601String(),

            'status' => [
                'key' => $status,                        // active | redeemed | expired
                'label' => __('gifts.statuses.'.$status),
                'tab' => $status === Gift::STATUS_ACTIVE ? Gift::TAB_AVAILABLE : Gift::TAB_FINISHED,
            ],
            'redeemed_at' => $this->redeemed_at?->toIso8601String(),
            'is_opened' => $this->isOpened(),
            'received_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
