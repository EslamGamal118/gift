<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\Review;
use App\Models\StoreReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One card of a "Reviews" screen (ReviewFeedService), the same shape whatever
 * the table behind it:
 *
 *  - `direction`: given (the viewer wrote it) | received
 *  - `subject_type`: store | shopper (who was rated)
 *  - `counterpart`: the other party (the store / shopper rated, or the customer who rated)
 *
 * @mixin Review|StoreReview
 */
class ReviewFeedItemResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $given = $this->resource instanceof Review && $this->user_id === $request->user()?->id;
        $order = $this->resource instanceof StoreReview ? $this->order : $this->reviewable;
        $subject = $order instanceof CustomOrder ? 'shopper' : 'store';

        return [
            'id' => $this->id,
            'rating' => (int) ($this->resource instanceof StoreReview ? $this->rating : $this->store_rating),
            'comment' => $this->resource instanceof StoreReview ? $this->comment : $this->store_comment,
            // Store orders also rate the products; only the author sees it here
            'products_rating' => $given && $subject === 'store' ? (int) $this->products_rating : null,
            'time_ago' => $this->timeAgo($this->created_at),
            'created_at' => $this->created_at?->toIso8601String(),
            'direction' => $given ? 'given' : 'received',
            'subject_type' => $subject,
            'counterpart' => $given ? $this->ratedParty($order) : [
                'role' => 'customer',
                'id' => $this->user_id,
                'name' => $this->user?->name,
                'avatar' => $this->fileUrl($this->user?->avatar),
            ],
            'order' => $order ? [
                'type' => $order instanceof CustomOrder ? Review::TYPE_CUSTOM_ORDER : Review::TYPE_ORDER,
                'id' => $order->id,
                'reference' => '#'.$order->order_number,
            ] : null,
        ];
    }

    /**
     * The store or personal shopper the viewer rated.
     *
     * @return array<string, mixed>|null
     */
    protected function ratedParty(Order|CustomOrder|null $order): ?array
    {
        if ($order instanceof CustomOrder) {
            return [
                'role' => 'shopper',
                'id' => $order->shopper_id,
                'name' => $order->shopper?->name,
                'avatar' => $this->fileUrl($order->shopper?->avatar),
            ];
        }

        $store = $order?->storeProfile;

        return $store ? [
            'role' => 'store',
            'id' => $store->id,   // GET /api/v1/stores/{id}
            'name' => $store->store_name,
            'avatar' => $this->fileUrl($store->logo),
        ] : null;
    }

    /**
     * "منذ 5 دقائق" today, "أمس", "منذ يومين" this week, then the date ("28 سبتمبر").
     */
    protected function timeAgo(?Carbon $at): ?string
    {
        $at = $at?->copy()->timezone(config('app.timezone'))->locale(app()->getLocale());

        return match (true) {
            $at === null => null,
            $at->isToday() => $at->diffForHumans(),
            $at->isYesterday() => __('orders.yesterday'),
            $at->gt(now()->subWeek()) => $at->diffForHumans(),
            default => $at->translatedFormat('j F'),
        };
    }
}
