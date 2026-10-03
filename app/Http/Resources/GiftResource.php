<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\Gift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An online gift, as seen by its sender or its recipient. Only the sender
 * gets the claim link (to share it again if WhatsApp did not arrive) and the
 * WhatsApp delivery state.
 *
 * @mixin Gift
 */
class GiftResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $line = $this->whenLoaded('order', fn () => $this->order->relationLoaded('items') ? $this->order->items->first() : null, null);
        $isSender = (int) $request->user()?->id === (int) $this->sender_id;

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order->order_number),
            'payment_status' => $this->payment_status,
            'payment_gateway' => $this->payment_gateway,
            'is_paid' => $this->isPaid(),
            'product' => [
                'id' => $this->product_id,
                'name' => $line?->product_name,
                'image' => $this->fileUrl($line?->product_image),
                'quantity' => $line ? (int) $line->quantity : null,
            ],
            'store' => $this->whenLoaded('store', fn () => [
                'id' => $this->store?->storeProfile?->id,
                'user_id' => $this->store_id,
                'name' => $this->store?->storeProfile?->store_name ?? $this->store?->name,
            ]),
            'sender' => $this->whenLoaded('sender', fn () => [
                'id' => $this->sender_id,
                'name' => $this->sender?->name,
            ]),
            'recipient' => [
                'name' => $this->recipient_name,
                'phone' => $this->recipient_phone,
                'email' => $this->recipient_email,
                'has_account' => $this->recipient_id !== null,
            ],
            'gift_message' => $this->gift_message,
            'total' => $this->whenLoaded('order', fn () => [
                'amount' => (float) $this->order->total_amount,
                'currency' => $this->order->currency,
            ]),
            'is_claimed' => (bool) $this->is_claimed,
            // Recipient side: opened in the app ("Open my gift")
            'is_opened' => $this->isOpened(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'claimed_at' => $this->claimed_at?->toIso8601String(),
            'claim_url' => $this->when($isSender && $this->isPaid(), fn () => $this->claimUrl()),
            'whatsapp_status' => $this->when($isSender, $this->whatsapp_status),
            'sms_status' => $this->when($isSender, $this->sms_status),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
