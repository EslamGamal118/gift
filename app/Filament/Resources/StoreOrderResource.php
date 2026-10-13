<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreOrderResource\Pages;
use App\Filament\Support\Bidi;
use App\Filament\Support\Lookups;
use App\Filament\Support\OrderDisplay;
use App\Filament\Support\OrderStatusAction;
use App\Filament\Support\ReviewDisplay;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\OrderStatusHistory;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Orders placed with stores (the `orders` table): one per store of a checkout.
 * Read-only apart from the admin's status change (OrderStatusAction).
 */
class StoreOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'store-orders';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function getModelLabel(): string
    {
        return __('admin.orders.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.orders.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.orders.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.orders_group');
    }

    public static function getNavigationBadge(): ?string
    {
        $new = Order::query()->where('status', Order::STATUS_PENDING)->paid()->count();

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
        return parent::getEloquentQuery()->with(['user', 'storeProfile']);
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
                    ->description(fn (Order $record) => Bidi::ltr($record->user?->phone))
                    ->searchable(['name', 'phone'])
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('storeProfile.store_name')
                    ->label(__('admin.order_management.store_name'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('subtotal')
                    ->label(__('admin.orders.subtotal'))
                    ->money(fn (Order $record) => $record->currency ?: 'SAR')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('shipping_cost')
                    ->label(__('admin.orders.delivery_fee'))
                    ->money(fn (Order $record) => $record->currency ?: 'SAR')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label(__('admin.order_management.grand_total'))
                    ->money(fn (Order $record) => $record->currency ?: 'SAR')
                    ->weight('bold')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::paymentStatusLabel($state))
                    ->color(fn (?string $state) => OrderDisplay::paymentStatusColor($state)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.orders.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::statusLabel($state))
                    ->color(fn (?string $state) => OrderDisplay::statusColor($state)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                OrderDisplay::statusGroupFilter(OrderDisplay::STORE_STATUS_GROUPS),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->multiple()
                    ->options(collect(__('admin.orders.payment_statuses'))->only(['pending', 'paid', 'failed', 'cancelled', 'refunded'])),
                Tables\Filters\SelectFilter::make('store_id')
                    ->label(__('admin.order_management.store_name'))
                    ->options(fn () => Lookups::stores())
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
                        ->formatStateUsing(fn (?string $state) => OrderDisplay::statusLabel($state))
                        ->color(fn (?string $state) => OrderDisplay::statusColor($state)),
                    Infolists\Components\TextEntry::make('payment_status')
                        ->label(__('admin.orders.payment_status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => OrderDisplay::paymentStatusLabel($state))
                        ->color(fn (?string $state) => OrderDisplay::paymentStatusColor($state)),
                    Infolists\Components\TextEntry::make('payment_method')
                        ->label(__('admin.orders.payment_method'))
                        ->formatStateUsing(fn (?string $state) => OrderDisplay::gatewayLabel($state))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('storeProfile.store_name')
                        ->label(__('admin.order_management.store_name'))
                        ->url(fn (Order $record) => $record->storeProfile ? StoreResource::getUrl('view', ['record' => $record->storeProfile]) : null)
                        ->color('primary')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')->label(__('admin.common.created_at'))->dateTime('Y-m-d H:i'),
                    Infolists\Components\TextEntry::make('paid_at')->label(__('admin.orders.paid_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivered_at')->label(__('admin.orders.delivered_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('cancellation_reason')
                        ->label(__('admin.order_management.cancellation_reason'))
                        ->color('danger')
                        ->columnSpanFull()
                        ->visible(fn (Order $record) => $record->isCancelled()),
                ]),

            Infolists\Components\Section::make(__('admin.order_management.customer_recipient'))
                ->icon('heroicon-o-user')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('user.name')
                        ->label(__('admin.order_management.customer_name'))
                        ->url(fn (Order $record) => $record->user ? CustomerResource::getUrl('view', ['record' => $record->user]) : null)
                        ->color('primary')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('user.phone')
                        ->label(__('admin.order_management.customer_phone'))
                        ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('user.email')->label(__('admin.order_management.customer_email'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('recipient_name')
                        ->label(__('admin.order_management.recipient_name'))
                        ->state(fn (Order $record) => $record->gift?->recipient_name ?? $record->shipping_name)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('recipient_phone')
                        ->label(__('admin.order_management.recipient_phone'))
                        ->state(fn (Order $record) => Bidi::ltr($record->gift?->recipient_phone ?? $record->shipping_phone))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('gift_message')->label(__('admin.order_management.gift_message'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('online_gift')
                        ->hiddenLabel()
                        ->state(__('admin.order_management.online_gift_hint'))
                        ->icon('heroicon-o-gift')
                        ->color('info')
                        ->columnSpanFull()
                        ->visible(fn (Order $record) => $record->gift !== null),
                ]),

            Infolists\Components\Section::make(__('admin.order_management.delivery'))
                ->icon('heroicon-o-map-pin')
                ->columns(3)
                ->visible(fn (Order $record) => $record->gift === null)
                ->schema([
                    Infolists\Components\TextEntry::make('shipping_location_name')->label(__('admin.order_management.location_name'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('shipping_address')->label(__('admin.orders.address'))->columnSpan(2)->placeholder('—'),
                    Infolists\Components\TextEntry::make('coordinates')
                        ->label(__('admin.order_management.coordinates'))
                        ->state(fn (Order $record) => OrderDisplay::coordinates($record->shipping_latitude, $record->shipping_longitude))
                        ->url(fn (Order $record) => OrderDisplay::mapUrl($record->shipping_latitude, $record->shipping_longitude), shouldOpenInNewTab: true)
                        ->icon('heroicon-m-map')
                        ->color('primary')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivery_type')
                        ->label(__('admin.order_management.delivery_type'))
                        ->formatStateUsing(fn (?string $state) => __("admin.order_management.delivery_types.$state"))
                        ->badge()
                        ->color(fn (?string $state) => $state === 'instant' ? 'warning' : 'gray'),
                    Infolists\Components\TextEntry::make('delivery_time')
                        ->label(__('admin.order_management.delivery_time'))
                        ->state(fn (Order $record) => trim(($record->delivery_date?->format('Y-m-d') ?? '').' '.($record->delivery_slot_label ?? '')) ?: null)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('captain.name')->label(__('admin.order_management.captain'))->placeholder('—'),
                ]),

            Infolists\Components\Section::make(__('admin.order_management.products_addons'))
                ->icon('heroicon-o-gift')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            Infolists\Components\TextEntry::make('product_name')->label(__('admin.orders.product'))->weight('medium'),
                            Infolists\Components\TextEntry::make('quantity')->label(__('admin.orders.quantity')),
                            Infolists\Components\TextEntry::make('unit_price')->label(__('admin.orders.unit_price'))->money('SAR'),
                            Infolists\Components\TextEntry::make('addons')
                                ->label(__('admin.order_management.addons'))
                                ->state(fn (OrderItem $record) => $record->addons->map(fn (OrderItemAddon $addon) => __('admin.order_management.addon_line', [
                                    'name' => $addon->name,
                                    'quantity' => $addon->quantity,
                                    'price' => number_format((float) $addon->subtotal, 2),
                                ]))->all())
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->placeholder(__('admin.order_management.no_addons')),
                            Infolists\Components\TextEntry::make('subtotal')->label(__('admin.orders.line_total'))->money('SAR')->weight('bold'),
                        ]),
                ]),

            Infolists\Components\Section::make(__('admin.orders.amounts'))
                ->icon('heroicon-o-banknotes')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label(__('admin.orders.subtotal'))->money('SAR'),
                    Infolists\Components\TextEntry::make('delivery_fee')->label(__('admin.orders.delivery_fee'))->money('SAR'),
                    Infolists\Components\TextEntry::make('express_fee')
                        ->label(__('admin.order_management.express_fee'))
                        ->money('SAR')
                        ->visible(fn (Order $record) => (float) $record->express_fee > 0),
                    Infolists\Components\TextEntry::make('discount_amount')
                        ->label(__('admin.orders.discount'))
                        ->money('SAR')
                        ->helperText(fn (Order $record) => $record->promo_code ? __('admin.order_management.promo_code', ['code' => $record->promo_code]) : null),
                    Infolists\Components\TextEntry::make('tax_amount')
                        ->label(__('admin.orders.tax'))
                        ->money('SAR')
                        ->helperText(fn (Order $record) => __('admin.order_management.tax_rate', ['rate' => rtrim(rtrim(number_format((float) $record->tax_rate * 100, 2), '0'), '.')])),
                    Infolists\Components\TextEntry::make('total_amount')
                        ->label(__('admin.order_management.grand_total'))
                        ->money('SAR')
                        ->weight('bold')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                        ->color('primary'),
                ]),

            ReviewDisplay::section(__('admin.order_reviews.store_rating')),

            Infolists\Components\Section::make(__('admin.order_management.history'))
                ->icon('heroicon-o-clock')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Infolists\Components\RepeatableEntry::make('statusHistories')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            Infolists\Components\TextEntry::make('to_status')
                                ->label(__('admin.orders.status'))
                                ->badge()
                                ->formatStateUsing(fn (?string $state) => OrderDisplay::statusLabel($state))
                                ->color(fn (?string $state) => OrderDisplay::statusColor($state)),
                            Infolists\Components\TextEntry::make('actor_type')
                                ->label(__('admin.order_management.by'))
                                ->state(fn (OrderStatusHistory $record) => self::actorLabel($record)),
                            Infolists\Components\TextEntry::make('reason')->label(__('admin.order_management.reason'))->placeholder('—'),
                            Infolists\Components\TextEntry::make('created_at')->label(__('admin.common.created_at'))->dateTime('Y-m-d H:i'),
                        ])
                        ->placeholder(__('admin.order_management.no_history')),
                ]),
        ]);
    }

    /**
     * Who moved the order: changes made from the panel are recorded as the
     * system, with the admin's id.
     */
    public static function actorLabel(OrderStatusHistory $history): string
    {
        if ($history->actor_type === Order::ACTOR_SYSTEM && $history->actor_id && ($admin = $history->actor) && $admin->isAdmin()) {
            return __('admin.order_management.actors.admin', ['name' => $admin->name]);
        }

        return __('admin.order_management.actors.'.$history->actor_type);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStoreOrders::route('/'),
            'view' => Pages\ViewStoreOrder::route('/{record}'),
        ];
    }
}
