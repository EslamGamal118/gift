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
 * plus one block per section of the screen, top to bottom:
 *
 *   header           "#GFT-10254" + "اليوم، 10:30 ص"
 *   current_status   + timeline (حالة الطلب): every step completed / current / upcoming
 *   store            (المتجر) name, category, logo, rating, "زيارة المتجر"
 *   products         (المنتجات) priced lines with add-ons
 *   delivery_info    (معلومات التوصيل) address, expected time, fee
 *   payment_summary  (ملخص الدفع) amounts + `lines`, the rows in display order
 *   actions          flags + `buttons` ("إلغاء الطلب", "تواصل مع الدعم")
 *
 * Amounts are Money::format() objects ({amount, currency, formatted}).
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
        Order::STATUS_ARRIVED_TO_DROPOFF => null,   // only when the delivery company reports it (وصل الموقع)
        Order::STATUS_DELIVERED => 'delivered_at',
    ];

    /**
     * The delivery company's statuses shown at their TIMELINE step: waiting
     * for its driver = ready, picked up = on the way.
     */
    public const TIMELINE_STEP = [
        Order::STATUS_ORDER_CREATED => Order::STATUS_READY,
        Order::STATUS_PENDING_DRIVER_ACCEPTANCE => Order::STATUS_READY,
        Order::STATUS_DRIVER_ACCEPTED => Order::STATUS_READY,
        Order::STATUS_PENDING_ORDER_PREPARATION => Order::STATUS_READY,
        Order::STATUS_ARRIVED_TO_PICKUP => Order::STATUS_READY,
        Order::STATUS_ORDER_PICKED_UP => Order::STATUS_OUT_FOR_DELIVERY,
        Order::STATUS_CANCELLATION_PROCESSING => Order::STATUS_OUT_FOR_DELIVERY,
    ];

    /**
     * @return array<string, mixed>
     */
  public function toArray(Request $request): array
    {
        return [
            'header' => [
                'order_number' => '#'.$this->order_number,
                'placed_at_label' => $this->placedAtLabel(),
                'created_at' => $this->created_at?->toIso8601String(),
            ],
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
                'total' => Money::format($item->subtotal, $this->currency),
            ])->values(),
            'delivery_info' => $this->deliveryInfo(),
            'payment_summary' => $this->paymentSummary(),
            'actions' => $this->detailActions(), // إذا كنت تريد تفاصيل الأزرار الخاصة بالشاشة فقط
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deliveryInfo(): array
    {
        $fee = round((float) $this->delivery_fee + (float) $this->express_fee, 2);

        return [
            'address' => $this->shipping_address,          // "الرياض، حي الياسمين، شارع الزهور، رقم 45"
            'location_name' => $this->shipping_location_name,
            'is_instant' => $this->isInstantDelivery(),
            'fee' => Money::format($fee, $this->currency),
            'is_free' => $fee <= 0,
            'fee_label' => $fee <= 0 ? __('orders.summary.free') : Money::format($fee, $this->currency)['formatted'],
        ];
    }

    /**
     * The amounts, plus `lines`: the summary rows in display order (the
     * express fee and the discount only when there is one).
     *
     * @return array<string, mixed>
     */
  protected function paymentSummary(): array
    {
        $money = fn ($amount) => Money::format($amount,$this->currency);
        $rate = (float)$this->tax_rate;

        return [
            'subtotal' => $money($this->subtotal),
            'delivery_fee' => $money($this->delivery_fee),
            'express_fee' => $money($this->express_fee),
            'discount' => $money($this->discount_amount),
            'tax_rate' => $rate,
            'tax' => $money($this->tax_amount),
            'total' => $money($this->total_amount),
        ];
    }
    /**
     * OrderResource's flags, the support channels, and `buttons` ready to
     * render: cancel (only while the store has not started) and contact support.
     *
     * @return array<string, mixed>
     */
    protected function detailActions(): array
    {
        $support = Arr::only((array) config('checkout.support'), ['phone', 'whatsapp', 'email']);
        $canSupport = array_filter($support) !== [];
        $actions = $this->actions();

        return $actions + [
            'can_contact_support' => $canSupport,
            'support' => $support,
            'buttons' => [
                [
                    'key' => 'cancel_order',
                    'label' => __('orders.customer_actions.cancel'),
                    'enabled' => $actions['can_cancel'],
                    'style' => 'danger',
                    'endpoint' => $actions['can_cancel'] ? url("/api/v1/orders/{$this->id}/cancel") : null,
                ],
                [
                    'key' => 'contact_support',
                    'label' => __('orders.customer_actions.contact_support'),
                    'enabled' => $canSupport,
                    'style' => 'secondary',
                ],
            ],
        ];
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
        $current = array_search(self::TIMELINE_STEP[$this->status] ?? $this->status, $steps, true);
        $cancelled = $this->status === Order::STATUS_CANCELLED;

        $timeline = [];
        foreach (self::TIMELINE as $status => $column) {
            $index = array_search($status, $steps, true);
            $at = $this->localTime(match (true) {
                $column !== null => $this->{$column},
                $index === $current => $this->delivery_updated_at,   // arrived: when it was reported
                default => null,
            });

            $state = match (true) {
                $cancelled => $at ? 'completed' : 'upcoming',
                $current === false => 'upcoming',            // not paid yet
                $index < $current => 'completed',
                $index === $current => $status === Order::STATUS_DELIVERED ? 'completed' : 'current',
                default => 'upcoming',
            };

            $timeline[] = ['state' => $state, 'is_current' => $state === 'current'] + $this->statusView($status) + [
                'at' => $at?->toIso8601String(),
                'at_label' => $at ? $this->timeLabel($at) : null,
            ];
        }

        if ($cancelled) {
            $at = $this->localTime($this->cancelled_at);
            $timeline[] = ['state' => 'current', 'is_current' => true] + $this->statusView(Order::STATUS_CANCELLED) + [
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
            'id' => $profile->id,
            'store_name' => $profile->store_name,
            'description' => $profile->description,
            'logo' => $this->fileUrl($profile->logo),
            'rating' => round((float) $profile->rating_avg, 1),
            'rating_count' => (int) $profile->rating_count,
        ];
    }
}
