<?php

namespace App\Filament\Resources\ShopperResource\RelationManagers;

use App\Filament\Support\OrderDisplay;
use App\Models\CustomOrder;
use App\Models\ShopperProfile;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom (personal shopper) orders assigned to the shopper.
 */
class CustomOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'customOrders';

    protected static ?string $icon = 'heroicon-o-shopping-cart';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.shoppers.tabs.orders');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->customOrders()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.custom_orders.model');
    }

    /**
     * The shopper's price for the order: their accepted bid when the order went
     * to bidding, otherwise the priced invoice (items + fee). Null until priced.
     */
    public static function quotedPrice(CustomOrder $order): ?float
    {
        $bid = $order->accepted_bid_amount ?? null;

        return $bid !== null ? (float) $bid : ($order->final_amount !== null ? (float) $order->final_amount : null);
    }

    /**
     * Platform commission on the shopper's fee: frozen once paid out, otherwise
     * at the shopper's current rate. Null while the order has no fee.
     */
    public static function platformFee(CustomOrder $order, ShopperProfile $shopper): ?float
    {
        if ($order->payoutItem) {
            return (float) $order->payoutItem->commission_amount;
        }

        return $order->shopper_fees === null ? null : $shopper->shareOf($order)['commission_amount'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['user', 'items', 'payoutItem'])
                ->addSelect(['accepted_bid_amount' => fn ($bids) => $bids->from('custom_order_bids')
                    ->select('amount')
                    ->whereColumn('custom_order_bids.custom_order_id', 'custom_orders.id')
                    ->whereColumn('custom_order_bids.shopper_id', 'custom_orders.shopper_id')
                    ->where('custom_order_bids.status', 'accepted')
                    ->limit(1)]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('admin.orders.number'))
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.orders.customer'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('items_summary')
                    ->label(__('admin.shoppers.orders.items'))
                    ->state(fn (CustomOrder $record) => trans_choice('admin.shoppers.orders.items_count', $record->items->count(), ['count' => $record->items->count()]))
                    ->description(fn (CustomOrder $record) => str($record->items->pluck('product_name')->join('، '))->limit(50))
                    ->wrap(),
                Tables\Columns\TextColumn::make('quoted_price')
                    ->label(__('admin.shoppers.orders.quoted_price'))
                    ->state(fn (CustomOrder $record) => self::quotedPrice($record))
                    ->money('SAR')
                    ->description(fn (CustomOrder $record) => $record->shopper_fees !== null
                        ? __('admin.custom_orders.shopper_fees').': '.number_format((float) $record->shopper_fees, 2)
                        : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('platform_fee')
                    ->label(__('admin.shoppers.orders.platform_fee'))
                    ->state(fn (CustomOrder $record) => self::platformFee($record, $this->getOwnerRecord()))
                    ->money('SAR')
                    ->color('danger')
                    ->placeholder('—'),
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
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.orders.status'))
                    ->options(__('custom_orders.statuses')),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->options(__('admin.orders.payment_statuses')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(__('admin.common.view_details')),
            ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema(OrderDisplay::customOrderInfolistSchema());
    }
}
