<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Support\OrderDisplay;
use App\Models\Order;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer's store orders (read-only; details open in a modal).
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $icon = 'heroicon-o-shopping-bag';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.orders.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->orders()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.orders.model');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->modifyQueryUsing(fn ($query) => $query->with(['storeProfile', 'user']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('admin.orders.number'))
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('storeProfile.store_name')
                    ->label(__('admin.orders.store'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label(__('admin.orders.total'))
                    ->money(fn (Order $record) => $record->currency ?: 'SAR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::paymentStatusLabel($state))
                    ->color(fn (?string $state) => OrderDisplay::paymentStatusColor($state))
                    ->description(fn (Order $record) => OrderDisplay::gatewayLabel($record->payment_method)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.orders.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::statusLabel($state))
                    ->color(fn (string $state) => OrderDisplay::statusColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.orders.status'))
                    ->options(__('orders.statuses')),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->options(collect(__('admin.orders.payment_statuses'))->only(['pending', 'paid', 'failed', 'cancelled', 'refunded'])),
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
