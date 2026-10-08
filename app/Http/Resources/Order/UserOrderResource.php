<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\Checkout\OrderResource;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Http\Resources\CustomOrder\Concerns\DescribesCustomOrder;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Services\UserOrderService;
use App\Support\City;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card of the customer's "My orders" list, in the same shape for store
 * orders (`order_type` = standard) and custom orders (`order_type` = custom).
 *
 * `city` / `district` come from the order's delivery address snapshot (null
 * when there is none, e.g. online gifts have no district). Custom orders also
 * carry `shopper` (name, photo, bio, rating; null until assigned) and `budget`
 * (the items' total budget, as on the details screen). `pricing.total` is the order total for store orders and the
 * final amount for custom orders (null until the shopper sets it, in which
 * case `pricing.budget` is the customer's estimate).
 *
 * Ready-to-render card fields (both types): `order_reference` ("#GFT-..."),
 * `status_badge` (customer wording + a colour `tone`), `location_label`
 * ("الرياض، حي الياسمين"), `items_preview` (first PREVIEW_ITEMS images) with
 * `remaining_items_count` / `remaining_items_label` ("+2"), and `actions`
 * (the card's buttons, the primary one first: track / pay / details).
 *
 * Expects the models from UserOrderService (relations loaded, `order_type` set).
 *
 * @mixin Order|CustomOrder
 */
class UserOrderResource extends JsonResource
{
    use DescribesCustomOrder, ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->resource->getAttribute('order_type')
            ?? ($this->resource instanceof CustomOrder ? UserOrderService::TYPE_CUSTOM : UserOrderService::TYPE_STANDARD);

        $card = $type === UserOrderService::TYPE_CUSTOM ? $this->customCard() : $this->standardCard();

        return [
            'order_type'   => $type,
            'id'           => $this->id,
            'order_number' => $this->order_number,
            'status'       => $this->status,
            'status_label' => __(($type === UserOrderService::TYPE_CUSTOM ? 'custom_orders' : 'orders').'.statuses.'.$this->status),
            'tab'          => UserOrderService::tabFor($type, $this->status),
            'order_reference' => '#'.$this->order_number,
            'status_badge' => [
                'key'   => $this->status,
                'label' => $this->badgeLabel($type),
                'tone'  => self::tone($this->status),
            ],
        ] + $card + $this->display($type) + [
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function standardCard(): array
    {
        return [
            'city'        => $this->addressPart($this->shipping_city),
            'district'    => $this->addressPart($this->shipping_district),
            'items_count' => $this->items->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customCard(): array
    {
        return [
            'city'        => $this->addressPart($this->delivery_city),
            'district'    => $this->addressPart($this->delivery_district),
            'shopper'     => $this->shopperCard(),
            'budget'      => $this->budget(),
            'items_count' => $this->items->count(),
        ];
    }

   /**
     * Location, product images and buttons of the card, for both order types.
     *
     * @return array<string, mixed>
     */
    protected function display(string $type): array
    {
        $custom = $type === UserOrderService::TYPE_CUSTOM;

        return [
            'location_label' => $this->locationLabel(
                $this->addressPart($custom ? $this->delivery_city : $this->shipping_city),
                $this->addressPart($custom ? $this->delivery_district : $this->shipping_district),
            ),
            'items_preview' => $this->items->take(OrderResource::PREVIEW_ITEMS)->map(fn ($item) => [
                'id'    => $item->id,
                'name'  => $item->product_name,
                'image' => $custom ? $item->media->first()?->url() : $this->fileUrl($item->product_image),
            ])->values(),
        ];
    }

    /**
     * The card's buttons, primary first: pay while the payment is awaited,
     * track while it is on its way, else the details.
     *
     * @return list<array{key: string, label: string, is_primary: bool}>
     */
    protected function actions(string $type): array
    {
        $awaitingPayment = in_array($this->status, [Order::STATUS_PENDING_PAYMENT, CustomOrder::STATUS_WAITING_FOR_PAYMENT], true);
        $primary = match (true) {
            $awaitingPayment => 'pay',
            UserOrderService::tabFor($type, $this->status) === UserOrderService::TAB_ACTIVE => 'track',
            default => 'details',
        };

        return collect([$primary, 'details'])->unique()->map(fn (string $key) => [
            'key'        => $key,
            'label'      => __('orders.customer_actions.'.$key),
            'is_primary' => $key === $primary,
        ])->values()->all();
    }

    /**
     * "الرياض، حي الياسمين": the city's name (stored as a key or a name) and
     * the district; either alone when the other is missing.
     */
    protected function locationLabel(?string $city, ?string $district): ?string
    {
        $city = $city !== null ? (City::find(strtolower($city))?->name() ?? $city) : null;

        if ($district !== null) {
            // The prefix ("حي") is added by the translation
            $district = __('orders.location.district', ['district' => preg_replace('/^حي\s+/u', '', $district)]);
        }

        return match (true) {
            $city !== null && $district !== null => __('orders.location.full', ['city' => $city, 'district' => $district]),
            default => $city ?? $district,
        };
    }

    /**
     * The customer's wording: the badge ("مكتمل") or the customer status
     * ("قيد المراجعة") for store orders, the status label for custom orders.
     */
    protected function badgeLabel(string $type): string
    {
        if ($type === UserOrderService::TYPE_CUSTOM) {
            return __('custom_orders.statuses.'.$this->status);
        }

        $key = 'orders.badges.'.$this->status;

        return trans()->has($key) ? __($key) : __("orders.customer_statuses.{$this->status}.label");
    }

    /**
     * Badge colour: warning (waiting on someone), info (in progress), primary
     * (with the driver), success (done), danger (cancelled).
     */
    public static function tone(string $status): string
    {
        return match ($status) {
            Order::STATUS_PENDING_PAYMENT, Order::STATUS_PENDING, CustomOrder::STATUS_WAITING_FOR_PAYMENT,
            CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE, CustomOrder::STATUS_DRAFT => 'warning',
            Order::STATUS_OUT_FOR_DELIVERY, CustomOrder::STATUS_ORDER_PICKED_UP, CustomOrder::STATUS_ARRIVED_TO_DROPOFF => 'primary',
            Order::STATUS_DELIVERED, CustomOrder::STATUS_COMPLETED => 'success',
            Order::STATUS_CANCELLED, CustomOrder::STATUS_CANCELLATION_PROCESSING => 'danger',
            default => 'info',
        };
    }

    /**
     * Address snapshot value, null when empty or the "-" placeholder.
     */
    protected function addressPart(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '-' ? null : $value;
    }
}
