<?php

namespace App\Filament\Support;

use App\Models\CustomOrder;
use App\Models\Order;
use Filament\Forms;
use Filament\Infolists;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

/**
 * Labels, badge colors and the details modal of store orders, shared by the
 * customer and store pages of the admin panel.
 */
class OrderDisplay
{
    /**
     * Store order statuses as the admin filters them (the delivery company's
     * statuses count as on the way).
     *
     * @var array<string, list<string>>
     */
    public const STORE_STATUS_GROUPS = [
        'pending_payment' => [Order::STATUS_PENDING_PAYMENT],
        'new' => [Order::STATUS_PENDING],
        'confirmed' => [Order::STATUS_ACCEPTED],
        'processing' => [Order::STATUS_PROCESSING, Order::STATUS_READY],
        'out_for_delivery' => [Order::STATUS_OUT_FOR_DELIVERY, ...Order::DELIVERY_STATUSES],
        'delivered' => [Order::STATUS_DELIVERED],
        'cancelled' => [Order::STATUS_CANCELLED],
    ];

    /**
     * Custom order statuses as the admin filters them (drafts are not listed).
     *
     * @var array<string, list<string>>
     */
    public const CUSTOM_STATUS_GROUPS = [
        'new' => [CustomOrder::STATUS_PENDING],
        'confirmed' => [CustomOrder::STATUS_ACCEPTED],
        'shopping' => [CustomOrder::STATUS_IN_PROGRESS, CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE],
        'waiting_for_payment' => [CustomOrder::STATUS_WAITING_FOR_PAYMENT],
        'out_for_delivery' => [
            CustomOrder::STATUS_PAID, CustomOrder::STATUS_ORDER_CREATED, CustomOrder::STATUS_PENDING_DRIVER_ACCEPTANCE,
            CustomOrder::STATUS_DRIVER_ACCEPTED, CustomOrder::STATUS_PENDING_ORDER_PREPARATION, CustomOrder::STATUS_ARRIVED_TO_PICKUP,
            CustomOrder::STATUS_ORDER_PICKED_UP, CustomOrder::STATUS_ARRIVED_TO_DROPOFF, CustomOrder::STATUS_CANCELLATION_PROCESSING,
        ],
        'delivered' => [CustomOrder::STATUS_COMPLETED],
        'cancelled' => [CustomOrder::STATUS_CANCELLED],
    ];

    /**
     * Multi-select filter over status groups.
     *
     * @param  array<string, list<string>>  $groups
     */
    public static function statusGroupFilter(array $groups): Tables\Filters\SelectFilter
    {
        return Tables\Filters\SelectFilter::make('status_group')
            ->label(__('admin.orders.status'))
            ->multiple()
            ->options(collect($groups)->keys()->mapWithKeys(fn (string $group) => [$group => __("admin.order_management.status_groups.$group")]))
            ->query(fn (Builder $query, array $data) => $query->when(
                $data['values'] ?? [],
                fn (Builder $q, array $values) => $q->whereIn($q->qualifyColumn('status'), collect($values)->flatMap(fn (string $group) => $groups[$group] ?? [])->all()),
            ));
    }

    /**
     * Created between two dates (either may be left empty).
     */
    public static function dateRangeFilter(): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make('created_between')
            ->form([
                Forms\Components\DatePicker::make('from')->label(__('admin.common.from_date')),
                Forms\Components\DatePicker::make('until')->label(__('admin.common.until_date')),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate($q->qualifyColumn('created_at'), '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate($q->qualifyColumn('created_at'), '<=', $date)))
            ->indicateUsing(fn (array $data) => array_values(array_filter([
                filled($data['from'] ?? null) ? __('admin.common.from_date').': '.$data['from'] : null,
                filled($data['until'] ?? null) ? __('admin.common.until_date').': '.$data['until'] : null,
            ])));
    }

    /**
     * Google Maps link for a pair of coordinates, or null without them.
     */
    public static function mapUrl(mixed $latitude, mixed $longitude): ?string
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude) || ((float) $latitude === 0.0 && (float) $longitude === 0.0)) {
            return null;
        }

        return 'https://www.google.com/maps?q='.((float) $latitude).','.((float) $longitude);
    }

    public static function coordinates(mixed $latitude, mixed $longitude): ?string
    {
        return self::mapUrl($latitude, $longitude) ? Bidi::ltr(((float) $latitude).', '.((float) $longitude)) : null;
    }

    public static function statusLabel(?string $status): ?string
    {
        return $status ? __("orders.statuses.$status") : null;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            Order::STATUS_DELIVERED => 'success',
            Order::STATUS_CANCELLED => 'danger',
            Order::STATUS_PENDING_PAYMENT => 'gray',
            Order::STATUS_PENDING => 'warning',
            default => 'info',
        };
    }

    public static function paymentStatusLabel(?string $status): ?string
    {
        return $status ? __('admin.orders.payment_statuses')[$status] ?? $status : null;
    }

    public static function paymentStatusColor(?string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'pending', 'refund_pending' => 'warning',
            'refunded' => 'info',
            default => 'danger',
        };
    }

    public static function gatewayLabel(?string $gateway): ?string
    {
        return $gateway ? __('admin.payments.gateways')[$gateway] ?? $gateway : null;
    }

    public static function customStatusLabel(?string $status): ?string
    {
        return $status ? __("custom_orders.statuses.$status") : null;
    }

    public static function customStatusColor(?string $status): string
    {
        return match ($status) {
            CustomOrder::STATUS_COMPLETED => 'success',
            CustomOrder::STATUS_CANCELLED => 'danger',
            CustomOrder::STATUS_DRAFT => 'gray',
            CustomOrder::STATUS_PENDING, CustomOrder::STATUS_WAITING_FOR_PAYMENT, CustomOrder::STATUS_WAITING_FOR_ALTERNATIVE => 'warning',
            default => 'info',
        };
    }

    /**
     * What the shopper spent on the items: final_amount is items + shopper fee
     * (App\Services\CustomOrderPricing). Null until the invoice is priced.
     */
    public static function itemsCost(CustomOrder $order): ?float
    {
        return $order->final_amount === null ? null : round(max((float) $order->final_amount - (float) $order->shopper_fees, 0), 2);
    }

    /**
     * Details of one custom (personal shopper) order (summary, items, amounts).
     *
     * @return array<int, Infolists\Components\Component>
     */
    public static function customOrderInfolistSchema(): array
    {
        return [
            Infolists\Components\Section::make(__('admin.orders.summary'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('order_number')->label(__('admin.orders.number'))->copyable(),
                    Infolists\Components\TextEntry::make('user.name')->label(__('admin.orders.customer'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('shopper.name')->label(__('admin.custom_orders.shopper'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')->label(__('admin.common.created_at'))->dateTime('Y-m-d H:i'),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('admin.orders.status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => self::customStatusLabel($state))
                        ->color(fn (?string $state) => self::customStatusColor($state)),
                    Infolists\Components\TextEntry::make('payment_status')
                        ->label(__('admin.orders.payment_status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => self::paymentStatusLabel($state))
                        ->color(fn (?string $state) => self::paymentStatusColor($state)),
                    Infolists\Components\TextEntry::make('paid_at')->label(__('admin.orders.paid_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('completed_at')->label(__('admin.orders.delivered_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivery_address')->label(__('admin.orders.address'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('notes')->label(__('admin.custom_orders.notes'))->placeholder('—')->columnSpanFull(),
                ]),
            Infolists\Components\Section::make(__('admin.orders.items'))
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            Infolists\Components\TextEntry::make('product_name')->label(__('admin.orders.product')),
                            Infolists\Components\TextEntry::make('quantity')->label(__('admin.orders.quantity')),
                            Infolists\Components\TextEntry::make('expected_price')
                                ->label(__('admin.custom_orders.expected_price'))
                                ->state(fn ($record) => $record->expected_price_min || $record->expected_price_max
                                    ? number_format((float) $record->expected_price_min, 2).' - '.number_format((float) $record->expected_price_max, 2)
                                    : null)
                                ->placeholder('—'),
                            Infolists\Components\TextEntry::make('unit_price')->label(__('admin.orders.unit_price'))->money('SAR')->placeholder('—'),
                        ]),
                ]),
            Infolists\Components\Section::make(__('admin.orders.amounts'))
                ->columns(5)
                ->schema([
                    Infolists\Components\TextEntry::make('items_cost')
                        ->label(__('admin.custom_orders.final_amount'))
                        ->state(fn (CustomOrder $record) => self::itemsCost($record))
                        ->money('SAR')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('shopper_fees')->label(__('admin.custom_orders.shopper_fees'))->money('SAR')->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivery_fee')->label(__('admin.orders.delivery_fee'))->money('SAR')->placeholder('—'),
                    Infolists\Components\TextEntry::make('tax_amount')->label(__('admin.orders.tax'))->money('SAR')->placeholder('—'),
                    Infolists\Components\TextEntry::make('total_amount')->label(__('admin.orders.total'))->money('SAR')->weight('bold')->placeholder('—'),
                ]),
        ];
    }

    /**
     * Details of one store order (summary, items, amounts).
     *
     * @return array<int, Infolists\Components\Component>
     */
    public static function infolistSchema(): array
    {
        return [
            Infolists\Components\Section::make(__('admin.orders.summary'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('order_number')->label(__('admin.orders.number'))->copyable(),
                    Infolists\Components\TextEntry::make('user.name')->label(__('admin.orders.customer'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('storeProfile.store_name')->label(__('admin.orders.store'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')->label(__('admin.common.created_at'))->dateTime('Y-m-d H:i'),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('admin.orders.status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => self::statusLabel($state))
                        ->color(fn (?string $state) => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('payment_status')
                        ->label(__('admin.orders.payment_status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => self::paymentStatusLabel($state))
                        ->color(fn (?string $state) => self::paymentStatusColor($state)),
                    Infolists\Components\TextEntry::make('payment_method')
                        ->label(__('admin.orders.payment_method'))
                        ->formatStateUsing(fn (?string $state) => self::gatewayLabel($state))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('paid_at')->label(__('admin.orders.paid_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivered_at')->label(__('admin.orders.delivered_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('shipping_address')->label(__('admin.orders.address'))->placeholder('—')->columnSpanFull(),
                ]),
            Infolists\Components\Section::make(__('admin.orders.items'))
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            Infolists\Components\TextEntry::make('product_name')->label(__('admin.orders.product')),
                            Infolists\Components\TextEntry::make('quantity')->label(__('admin.orders.quantity')),
                            Infolists\Components\TextEntry::make('unit_price')->label(__('admin.orders.unit_price'))->money('SAR'),
                            Infolists\Components\TextEntry::make('subtotal')->label(__('admin.orders.line_total'))->money('SAR'),
                        ]),
                ]),
            Infolists\Components\Section::make(__('admin.orders.amounts'))
                ->columns(5)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label(__('admin.orders.subtotal'))->money('SAR'),
                    Infolists\Components\TextEntry::make('shipping_cost')->label(__('admin.orders.delivery_fee'))->money('SAR'),
                    Infolists\Components\TextEntry::make('discount_amount')->label(__('admin.orders.discount'))->money('SAR'),
                    Infolists\Components\TextEntry::make('tax_amount')->label(__('admin.orders.tax'))->money('SAR'),
                    Infolists\Components\TextEntry::make('total_amount')->label(__('admin.orders.total'))->money('SAR')->weight('bold'),
                ]),
        ];
    }
}
