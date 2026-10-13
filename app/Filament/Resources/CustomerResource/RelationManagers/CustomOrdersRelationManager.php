<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Support\OrderDisplay;
use App\Models\CustomOrder;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer's personal shopper (custom) orders (read-only; details open in a modal).
 */
class CustomOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'customOrders';

    protected static ?string $icon = 'heroicon-o-shopping-cart';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.custom_orders.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->customOrders()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.custom_orders.model');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->modifyQueryUsing(fn ($query) => $query->with(['shopper', 'user']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('admin.orders.number'))
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('shopper.name')
                    ->label(__('admin.custom_orders.shopper'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label(__('admin.orders.total'))
                    ->money(fn (CustomOrder $record) => $record->currency ?: 'SAR')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label(__('admin.orders.payment_status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::paymentStatusLabel($state))
                    ->color(fn (?string $state) => OrderDisplay::paymentStatusColor($state))
                    ->description(fn (CustomOrder $record) => OrderDisplay::gatewayLabel($record->payment_method)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.orders.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::customStatusLabel($state))
                    ->color(fn (?string $state) => OrderDisplay::customStatusColor($state)),
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
