<?php

namespace App\Http\Resources\Checkout;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreProfile;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The customer's "Order details" screen: everything OrderResource returns,
 * plus the status timeline, the store card, priced product lines, delivery
 * info, the payment summary and the screen's buttons. Amounts are
 * Money::format() objects ({amount, currency, formatted}).
 *
 * Expects RELATIONS loaded.
 *
 * @mixin Order
 */
class OrderDetailsResource extends OrderResource
{
    public const RELATIONS = ['items.addons', 'storeProfile.category', 'storeProfile.mainBranch'];

    /**
     * The customer-facing workflow, in order: status => the timestamp it was reached at.
     */
    public const TIMELINE = [
        Order::STATUS_PENDING => 'paid_at',
        Order::STATUS_ACCEPTED => 'accepted_at',
        Order::STATUS_PROCESSING => 'preparing_at',
        Order::STATUS_READY => 'ready_at',
        Order::STATUS_OUT_FOR_DELIVERY => 'dispatched_at',
        Order::STATUS_DELIVERED => 'delivered_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $details = [
            'current_status' => $this->statusView($this->status),
            'timeline' => $this->timeline(),
            'store' => $this->storeCard(),
            'products' => $this->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'image' => $this->fileUrl($item->product_image),
                'quantity' => (int) $item->quantity,
                'unit_price' => Money::format($item->unit_price, $this->currency),
                'addons' => $item->addons->map(fn ($addon) => [
                    'name' => $addon->name,
                    'quantity' => (int) $addon->quantity,
                    'total' => Money::format($addon->subtotal, $this->currency),
                ])->values(),
                'total' => Money::format($item->subtotal, $this->currency),   // incl. add-ons
            ])->values(),
            'delivery_info' => [
                'address' => $this->shipping_address,
                'location_name' => $this->shipping_location_name,
                'is_instant' => $this->isInstantDelivery(),
                'expected_time_label' => $this->expectedTimeLabel(),
                'fee' => Money::format((float) $this->delivery_fee + (float) $this->express_fee, $this->currency),
            ],
            'payment_summary' => [
                'subtotal' => Money::format($this->subtotal, $this->currency),
                'delivery_fee' => Money::format($this->delivery_fee, $this->currency),
                'express_fee' => Money::format($this->express_fee, $this->currency),
                'discount' => Money::format($this->discount_amount, $this->currency),
                'tax_rate' => (float) $this->tax_rate,
                'tax' => Money::format($this->tax_amount, $this->currency),
                'total' => Money::format($this->total_amount, $this->currency),
            ],
            'actions' => $this->actions() + [
                'can_contact_support' => array_filter($support = Arr::only((array) config('checkout.support'), ['phone', 'whatsapp', 'email'])) !== [],
                'support' => $support,
            ],
        ];

        return $details + parent::toArray($request);
    }

    /**
     * @return array{key: string, label: string, description: string}
     */
    protected function statusView(string $status): array
    {
        return [
            'key' => $status,
            'label' => __("orders.customer_statuses.{$status}.label"),
            'description' => __("orders.customer_statuses.{$status}.description"),
        ];
    }

    /**
     * Every workflow step as `completed`, `current` or `upcoming`. A step is
     * completed once the order went past it; a cancelled order keeps the steps
     * it reached and ends with a `cancelled` step.
     *
     * @return list<array<string, mixed>>
     */
    protected function timeline(): array
    {
        $steps = array_keys(self::TIMELINE);
        $current = array_search($this->status, $steps, true);
        $cancelled = $this->status === Order::STATUS_CANCELLED;

        $timeline = [];
        foreach (self::TIMELINE as $status => $column) {
            $index = array_search($status, $steps, true);
            $at = $this->localTime($this->{$column});

            $state = match (true) {
                $cancelled => $at ? 'completed' : 'upcoming',
                $current === false => 'upcoming',            // not paid yet
                $index < $current => 'completed',
                $index === $current => $status === Order::STATUS_DELIVERED ? 'completed' : 'current',
                default => 'upcoming',
            };

            $timeline[] = ['state' => $state] + $this->statusView($status) + [
                'at' => $at?->toIso8601String(),
                'at_label' => $at ? $this->timeLabel($at) : null,
            ];
        }

        if ($cancelled) {
            $at = $this->localTime($this->cancelled_at);
            $timeline[] = ['state' => 'current'] + $this->statusView(Order::STATUS_CANCELLED) + [
                'at' => $at?->toIso8601String(),
                'at_label' => $at ? $this->timeLabel($at) : null,
            ];
        }

        return $timeline;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function storeCard(): ?array
    {
        /** @var StoreProfile|null $profile */
        $profile = $this->storeProfile;

        if (! $profile) {
            return null;
        }

        $visitable = StoreProfile::query()->visible()->whereKey($profile->id)->exists();

        return [
            'id' => $this->store_id,
            // GET /api/v1/stores/{store_profile_id} is the store screen
            'store_profile_id' => $profile->id,
            'store_name' => $profile->store_name,
            'category' => $profile->category?->name,
            'description' => $profile->description,
            'logo' => $this->fileUrl($profile->logo),
            'address' => $profile->mainBranch?->address,
            'rating' => round((float) $profile->rating_avg, 1),
            'rating_count' => (int) $profile->rating_count,
            'can_visit' => $visitable,
            'visit_url' => $visitable ? url("/api/v1/stores/{$profile->id}") : null,
        ];
    }
}
