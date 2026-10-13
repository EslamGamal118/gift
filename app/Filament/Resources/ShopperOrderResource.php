<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopperOrderResource\Pages;
use App\Filament\Resources\ShopperResource\RelationManagers\CustomOrdersRelationManager;
use App\Filament\Support\Bidi;
use App\Filament\Support\OrderDisplay;
use App\Filament\Support\OrderStatusAction;
use App\Filament\Support\ReviewDisplay;
use App\Models\CustomOrder;
use App\Models\CustomOrderItem;
use App\Models\ShopperProfile;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Personal shopper (custom) orders. Drafts (never confirmed by the customer)
 * are not listed. Read-only apart from the admin's status change.
 *
 * Money: final_amount = items cost + shopper fee; the platform's commission
 * is taken from the fee only, at the shopper's rate (frozen once paid out).
 */
class ShopperOrderResource extends Resource
{
    protected static ?string $model = CustomOrder::class;

    protected static ?string $slug = 'shopper-orders';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function getModelLabel(): string
    {
        return __('admin.custom_orders.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.custom_orders.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.custom_orders.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.orders_group');
    }

    public static function getNavigationBadge(): ?string
    {
        $new = CustomOrder::query()->where('status', CustomOrder::STATUS_PENDING)->count();

        return $new ? (string) $new : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.order_management.new_badge');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', '!=', CustomOrder::STATUS_DRAFT)
            ->with(['user', 'shopper', 'shopperProfile', 'payoutItem']);
    }

    /**
     * Platform commission on the shopper's fee, or null without a shopper / fee.
     */
    public static function commission(CustomOrder $order): ?float
    {
        return $order->shopperProfile ? CustomOrdersRelationManager::platformFee($order, $order->shopperProfile) : null;
    }

    /**
     * What the shopper gets: items cost + fee - commission (frozen once paid out).
     */
    public static function shopperNet(CustomOrder $order): ?float
    {
        if ($order->payoutItem) {
            return (float) $order->payoutItem->shopper_amount;
        }

        return $order->shopperProfile && $order->final_amount !== null ? $order->shopperProfile->shareOf($order)['shopper_amount'] : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('admin.orders.number'))
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.order_management.customer_name'))
                    ->description(fn (CustomOrder $record) => Bidi::ltr($record->user?->phone))
                    ->searchable(['name', 'phone'])
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('shopper.name')
                    ->label(__('admin.order_management.shopper_name'))
                    ->searchable()
                    ->placeholder(__('admin.order_management.no_shopper')),
                Tables\Columns\TextColumn::make('items_cost')
                    ->label(__('admin.order_management.items_cost'))
                    ->state(fn (CustomOrder $record) => OrderDisplay::itemsCost($record))
                    ->money('SAR')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('shopper_fees')
                    ->label(__('admin.custom_orders.shopper_fees'))
                    ->money('SAR')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('commission')
                    ->label(__('admin.order_management.platform_commission'))
                    ->state(fn (CustomOrder $record) => self::commission($record))
                    ->money('SAR')
                    ->color('success')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label(__('admin.order_management.grand_total'))
                    ->money('SAR')
                    ->weight('bold')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::paymentStatusLabel($state))
                    ->color(fn (?string $state) => OrderDisplay::paymentStatusColor($state)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.orders.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::customStatusLabel($state))
                    ->color(fn (?string $state) => OrderDisplay::customStatusColor($state)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                OrderDisplay::statusGroupFilter(OrderDisplay::CUSTOM_STATUS_GROUPS),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->multiple()
                    ->options(collect(__('admin.orders.payment_statuses'))->only(['pending', 'paid', 'failed', 'refund_pending', 'refunded', 'refund_failed'])),
                Tables\Filters\SelectFilter::make('shopper_id')
                    ->label(__('admin.order_management.shopper_name'))
                    ->options(fn () => ShopperProfile::query()->with('user:id,name')->get()->pluck('user.name', 'user_id')->filter()->sort()->all())
                    ->searchable(),
                OrderDisplay::dateRangeFilter(),
            ])
            ->filtersFormColumns(2)
            ->actionsColumnLabel(__('admin.common.actions'))
            ->actionsAlignment(Bidi::actionsAlignment()->value)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label(__('admin.common.view_details')),
                    OrderStatusAction::table(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.orders.summary'))
                ->icon('heroicon-o-clipboard-document-list')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('order_number')->label(__('admin.orders.number'))->copyable()->weight('bold'),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('admin.orders.status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => OrderDisplay::customStatusLabel($state))
                        ->color(fn (?string $state) => OrderDisplay::customStatusColor($state)),
                    Infolists\Components\TextEntry::make('payment_status')
                        ->label(__('admin.orders.payment_status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => OrderDisplay::paymentStatusLabel($state))
                        ->color(fn (?string $state) => OrderDisplay::paymentStatusColor($state)),
                    Infolists\Components\TextEntry::make('payment_method')
                        ->label(__('admin.orders.payment_method'))
                        ->formatStateUsing(fn (?string $state) => OrderDisplay::gatewayLabel($state))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('shopper.name')
                        ->label(__('admin.order_management.shopper_name'))
                        ->url(fn (CustomOrder $record) => $record->shopperProfile ? ShopperResource::getUrl('view', ['record' => $record->shopperProfile]) : null)
                        ->color('primary')
                        ->placeholder(__('admin.order_management.no_shopper')),
                    Infolists\Components\TextEntry::make('assignment_mode')
                        ->label(__('admin.order_management.assignment_mode'))
                        ->formatStateUsing(fn (?string $state) => __("admin.order_management.assignment_modes.$state"))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')->label(__('admin.common.created_at'))->dateTime('Y-m-d H:i'),
                    Infolists\Components\TextEntry::make('completed_at')->label(__('admin.orders.delivered_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('cancellation_reason')
                        ->label(__('admin.order_management.cancellation_reason'))
                        ->color('danger')
                        ->columnSpanFull()
                        ->visible(fn (CustomOrder $record) => $record->status === CustomOrder::STATUS_CANCELLED),
                ]),

            Infolists\Components\Section::make(__('admin.order_management.customer_recipient'))
                ->icon('heroicon-o-user')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('user.name')
                        ->label(__('admin.order_management.customer_name'))
                        ->url(fn (CustomOrder $record) => $record->user ? CustomerResource::getUrl('view', ['record' => $record->user]) : null)
                        ->color('primary')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('user.phone')
                        ->label(__('admin.order_management.customer_phone'))
                        ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('user.email')->label(__('admin.order_management.customer_email'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivery_name')->label(__('admin.order_management.recipient_name'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivery_phone')
                        ->label(__('admin.order_management.recipient_phone'))
                        ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivery_time')
                        ->label(__('admin.order_management.delivery_time'))
                        ->state(fn (CustomOrder $record) => trim(($record->delivery_date?->format('Y-m-d') ?? $record->delivery_at?->format('Y-m-d H:i') ?? '').' '.($record->delivery_slot_label ?? '')) ?: null)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('notes')->label(__('admin.custom_orders.notes'))->placeholder('—')->columnSpan(2),
                    Infolists\Components\TextEntry::make('confirmation_notes')->label(__('admin.order_management.confirmation_notes'))->placeholder('—'),
                ]),

            Infolists\Components\Grid::make(2)
                ->schema([
                    Infolists\Components\Section::make(__('admin.order_management.delivery_address'))
                        ->icon('heroicon-o-map-pin')
                        ->columnSpan(1)
                        ->schema([
                            Infolists\Components\TextEntry::make('delivery_location_name')->label(__('admin.order_management.location_name'))->placeholder('—'),
                            Infolists\Components\TextEntry::make('delivery_address')->label(__('admin.orders.address'))->placeholder('—'),
                            Infolists\Components\TextEntry::make('delivery_coordinates')
                                ->label(__('admin.order_management.coordinates'))
                                ->state(fn (CustomOrder $record) => OrderDisplay::coordinates($record->delivery_latitude, $record->delivery_longitude))
                                ->url(fn (CustomOrder $record) => OrderDisplay::mapUrl($record->delivery_latitude, $record->delivery_longitude), shouldOpenInNewTab: true)
                                ->icon('heroicon-m-map')
                                ->color('primary')
                                ->placeholder('—'),
                        ]),
                    Infolists\Components\Section::make(__('admin.order_management.pickup_address'))
                        ->icon('heroicon-o-building-storefront')
                        ->columnSpan(1)
                        ->schema([
                            Infolists\Components\TextEntry::make('pickupAddress.location_name')->label(__('admin.order_management.location_name'))->placeholder('—'),
                            Infolists\Components\TextEntry::make('pickup_address')
                                ->label(__('admin.orders.address'))
                                ->state(fn (CustomOrder $record) => $record->pickupAddress
                                    ? collect([$record->pickupAddress->building_number, $record->pickupAddress->street, $record->pickupAddress->district, $record->pickupAddress->city])->filter()->implode('، ')
                                    : null)
                                ->placeholder(__('admin.order_management.no_pickup')),
                            Infolists\Components\TextEntry::make('pickup_coordinates')
                                ->label(__('admin.order_management.coordinates'))
                                ->state(fn (CustomOrder $record) => OrderDisplay::coordinates($record->pickupAddress?->latitude, $record->pickupAddress?->longitude))
                                ->url(fn (CustomOrder $record) => OrderDisplay::mapUrl($record->pickupAddress?->latitude, $record->pickupAddress?->longitude), shouldOpenInNewTab: true)
                                ->icon('heroicon-m-map')
                                ->color('primary')
                                ->placeholder('—'),
                            Infolists\Components\TextEntry::make('driver')
                                ->label(__('admin.order_management.driver'))
                                ->state(fn (CustomOrder $record) => collect([$record->delivery_driver['name'] ?? null, Bidi::ltr($record->delivery_driver['phone'] ?? null)])->filter()->implode(' - ') ?: null)
                                ->placeholder('—'),
                        ]),
                ]),

            Infolists\Components\Section::make(__('admin.order_management.requested_items'))
                ->icon('heroicon-o-list-bullet')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            Infolists\Components\TextEntry::make('product_name')
                                ->label(__('admin.orders.product'))
                                ->weight('medium')
                                ->helperText(fn (CustomOrderItem $record) => $record->description ? str($record->description)->limit(80) : null),
                            Infolists\Components\TextEntry::make('quantity')->label(__('admin.orders.quantity')),
                            Infolists\Components\TextEntry::make('expected_price')
                                ->label(__('admin.custom_orders.expected_price'))
                                ->state(fn (CustomOrderItem $record) => $record->expected_price_min || $record->expected_price_max
                                    ? number_format((float) $record->expected_price_min, 2).' - '.number_format((float) $record->expected_price_max, 2)
                                    : null)
                                ->placeholder('—'),
                            Infolists\Components\TextEntry::make('unit_price')
                                ->label(__('admin.order_management.paid_unit_price'))
                                ->money('SAR')
                                ->placeholder(__('admin.order_management.not_purchased')),
                            Infolists\Components\TextEntry::make('line_total')
                                ->label(__('admin.orders.line_total'))
                                ->state(fn (CustomOrderItem $record) => $record->totalPrice())
                                ->money('SAR')
                                ->placeholder('—'),
                        ]),
                ]),

            Infolists\Components\Section::make(__('admin.order_management.shopper_finance'))
                ->icon('heroicon-o-banknotes')
                ->description(__('admin.order_management.shopper_finance_hint'))
                ->headerActions([
                    Infolists\Components\Actions\Action::make('invoice')
                        ->label(__('admin.order_management.view_invoice'))
                        ->icon('heroicon-o-document-text')
                        ->color('gray')
                        ->url(fn (CustomOrder $record) => $record->invoiceUrl(), shouldOpenInNewTab: true)
                        ->visible(fn (CustomOrder $record) => filled($record->invoiceUrl())),
                ])
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('items_cost')
                        ->label(__('admin.order_management.items_cost'))
                        ->state(fn (CustomOrder $record) => OrderDisplay::itemsCost($record))
                        ->money('SAR')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('shopper_fees')->label(__('admin.custom_orders.shopper_fees'))->money('SAR')->placeholder('—'),
                    Infolists\Components\TextEntry::make('commission')
                        ->label(__('admin.order_management.platform_commission'))
                        ->state(fn (CustomOrder $record) => self::commission($record))
                        ->money('SAR')
                        ->color('danger')
                        ->helperText(fn (CustomOrder $record) => $record->payoutItem
                            ? __('admin.order_management.commission_frozen')
                            : ($record->shopperProfile ? __('admin.order_management.commission_rate', ['rate' => rtrim(rtrim((string) $record->shopperProfile->commission_rate, '0'), '.')]) : null))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('shopper_net')
                        ->label(__('admin.order_management.shopper_net'))
                        ->state(fn (CustomOrder $record) => self::shopperNet($record))
                        ->money('SAR')
                        ->color('success')
                        ->helperText(fn (CustomOrder $record) => $record->payoutItem ? __('admin.orders.paid_out') : null)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivery_fee')->label(__('admin.orders.delivery_fee'))->money('SAR')->placeholder('—'),
                    Infolists\Components\TextEntry::make('tax_amount')->label(__('admin.orders.tax'))->money('SAR')->placeholder('—'),
                    Infolists\Components\TextEntry::make('total_amount')
                        ->label(__('admin.order_management.grand_total'))
                        ->money('SAR')
                        ->weight('bold')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                        ->color('primary')
                        ->placeholder(__('admin.order_management.not_priced'))
                        ->columnSpan(2),
                ]),

            ReviewDisplay::section(__('admin.order_reviews.shopper_rating')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShopperOrders::route('/'),
            'view' => Pages\ViewShopperOrder::route('/{record}'),
        ];
    }
}
