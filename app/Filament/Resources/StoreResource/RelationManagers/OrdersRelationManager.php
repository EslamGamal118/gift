<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Filament\Support\OrderDisplay;
use App\Models\Order;
use App\Models\StoreProfile;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Orders placed with the store, with the store's share of each.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $icon = 'heroicon-o-shopping-bag';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.stores.tabs.orders');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->orders()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.orders.model');
    }

    /**
     * Paid-out orders keep their frozen share; earning orders use the current
     * commission rate; unpaid or cancelled orders earn nothing (null).
     */
    public static function storeShare(Order $order, StoreProfile $store): ?float
    {
        if ($order->payoutItem) {
            return (float) $order->payoutItem->store_amount;
        }

        $earning = $order->payment_status === Order::PAYMENT_PAID
            && ! in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_PENDING_PAYMENT], true);

        return $earning ? round((float) $order->subtotal - $store->commissionFor((float) $order->subtotal), 2) : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'payoutItem']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('admin.orders.number'))
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.orders.customer'))
                    ->description(fn (Order $record) => $record->user?->phone)
                    ->searchable(['name', 'phone'])
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('subtotal')
                    ->label(__('admin.orders.subtotal'))
                    ->money(fn (Order $record) => $record->currency ?: 'SAR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('store_share')
                    ->label(__('admin.orders.store_share'))
                    ->state(fn (Order $record) => self::storeShare($record, $this->getOwnerRecord()))
                    ->money('SAR')
                    ->placeholder('—')
                    ->color('success')
                    ->icon(fn (Order $record) => $record->payoutItem ? 'heroicon-m-check-circle' : null)
                    ->tooltip(fn (Order $record) => $record->payoutItem ? __('admin.orders.paid_out') : null),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.orders.delivery_status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::statusLabel($state))
                    ->color(fn (?string $state) => OrderDisplay::statusColor($state)),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::paymentStatusLabel($state))
                    ->color(fn (?string $state) => OrderDisplay::paymentStatusColor($state))
                    ->description(fn (Order $record) => OrderDisplay::gatewayLabel($record->payment_method)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.orders.delivery_status'))
                    ->options(__('orders.statuses')),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->options(collect(__('admin.orders.payment_statuses'))->only(['pending', 'paid', 'failed', 'cancelled', 'refunded'])),
                Tables\Filters\TernaryFilter::make('paid_out')
                    ->label(__('admin.orders.paid_out'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('payoutItem'),
                        false: fn (Builder $query) => $query->whereDoesntHave('payoutItem'),
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(__('admin.common.view_details')),
            ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema(OrderDisplay::infolistSchema());
    }
}
