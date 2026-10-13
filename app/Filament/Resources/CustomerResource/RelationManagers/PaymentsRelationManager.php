<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\PaymentTransaction;
use App\Filament\Support\OrderDisplay;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every gateway transaction of the customer. Transactions have no user_id:
 * they hang off a store order, a custom order or a multi-store checkout, so the
 * table runs its own query (PaymentTransaction::forCustomer) instead of a relation.
 */
class PaymentsRelationManager extends RelationManager
{
    // Nominal only — the table below sets its own query
    protected static string $relationship = 'orders';

    protected static ?string $icon = 'heroicon-o-credit-card';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.payments.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.payments.model');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('admin.payments.title');
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            PaymentTransaction::STATUS_CAPTURED => 'success',
            PaymentTransaction::STATUS_AUTHORIZED => 'info',
            PaymentTransaction::STATUS_INITIATED => 'gray',
            PaymentTransaction::STATUS_REFUNDED => 'warning',
            default => 'danger',
        };
    }

    public static function sourceLabel(PaymentTransaction $record): ?string
    {
        return match (true) {
            $record->order !== null => __('admin.payments.sources.order', ['number' => $record->order->order_number]),
            $record->customOrder !== null => __('admin.payments.sources.custom_order', ['number' => $record->customOrder->order_number]),
            $record->checkoutGroup !== null => __('admin.payments.sources.checkout_group', ['number' => $record->checkoutGroup->reference]),
            default => null,
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => PaymentTransaction::query()
                ->forCustomer($this->getOwnerRecord()->getKey())
                ->with(['order', 'customOrder', 'checkoutGroup']))
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('admin.payments.id'))
                    ->prefix('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('gateway_reference')
                    ->label(__('admin.payments.reference'))
                    ->searchable()
                    ->copyable()
                    ->limit(20)
                    ->tooltip(fn (PaymentTransaction $record) => $record->gateway_reference)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('gateway')
                    ->label(__('admin.payments.gateway'))
                    ->formatStateUsing(fn (?string $state) => OrderDisplay::gatewayLabel($state))
                    ->description(fn (PaymentTransaction $record) => $record->event),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('admin.payments.amount'))
                    ->money(fn (PaymentTransaction $record) => $record->currency ?: 'SAR')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.payments.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.payments.statuses')[$state] ?? $state)
                    ->color(fn (string $state) => self::statusColor($state)),
                Tables\Columns\TextColumn::make('source')
                    ->label(__('admin.payments.source'))
                    ->state(fn (PaymentTransaction $record) => self::sourceLabel($record))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.payments.status'))
                    ->options(__('admin.payments.statuses')),
                Tables\Filters\SelectFilter::make('gateway')
                    ->label(__('admin.payments.gateway'))
                    ->options(collect(__('admin.payments.gateways'))->except('unknown')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label(__('admin.common.view_details')),
            ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('id')->label(__('admin.payments.id'))->prefix('#'),
                    Infolists\Components\TextEntry::make('gateway_reference')->label(__('admin.payments.reference'))->copyable()->placeholder('—'),
                    Infolists\Components\TextEntry::make('gateway')
                        ->label(__('admin.payments.gateway'))
                        ->formatStateUsing(fn (?string $state) => OrderDisplay::gatewayLabel($state)),
                    Infolists\Components\TextEntry::make('event')->label(__('admin.payments.event')),
                    Infolists\Components\TextEntry::make('amount')->label(__('admin.payments.amount'))->money('SAR')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('admin.payments.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __('admin.payments.statuses')[$state] ?? $state)
                        ->color(fn (string $state) => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('source')
                        ->label(__('admin.payments.source'))
                        ->state(fn (PaymentTransaction $record) => self::sourceLabel($record))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')->label(__('admin.common.created_at'))->dateTime('Y-m-d H:i:s'),
                ]),
            Infolists\Components\Section::make(__('admin.payments.payload'))
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('payload')
                        ->hiddenLabel()
                        ->state(fn (PaymentTransaction $record) => json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->fontFamily('mono')
                        ->extraAttributes(['dir' => 'ltr', 'class' => 'whitespace-pre-wrap break-all text-xs']),
                ]),
        ]);
    }
}
