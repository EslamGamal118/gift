<?php

namespace App\Filament\Resources\ShopperResource\RelationManagers;

use App\Filament\Support\PayoutForm;
use App\Models\CustomOrder;
use App\Models\ShopperProfile;
use App\Services\ShopperPayoutService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Completed, paid custom orders not paid out to the shopper yet — the
 * available balance — and the action to pay them out (all, or a selection).
 */
class PendingDuesRelationManager extends RelationManager
{
    protected static string $relationship = 'customOrders';

    protected static ?string $icon = 'heroicon-o-clock';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.payouts.pending_title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = app(ShopperPayoutService::class)->dueOrders($ownerRecord)->count();

        return $count ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.custom_orders.model');
    }

    #[On('finances-updated')]
    public function refreshDues(): void
    {
        $this->resetTable();
    }

    protected function shopper(): ShopperProfile
    {
        return $this->getOwnerRecord();
    }

    public function table(Table $table): Table
    {
        $share = fn (CustomOrder $record, string $key) => $this->shopper()->shareOf($record)[$key];
        $rate = fn () => rtrim(rtrim((string) $this->shopper()->commission_rate, '0'), '.');

        return $table
            ->query(fn () => app(ShopperPayoutService::class)->dueOrders($this->shopper())->with('user'))
            ->recordTitleAttribute('order_number')
            ->heading(__('admin.payouts.pending_title'))
            ->description(fn () => __('admin.shoppers.payouts.pending_description', [
                'amount' => number_format($this->shopper()->financials()['available'], 2),
                'rate' => $rate(),
            ]))
            ->defaultSort('completed_at')
            ->emptyStateHeading(__('admin.payouts.nothing_due'))
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('admin.orders.number'))
                    ->searchable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.orders.customer'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('completed_at')
                    ->label(__('admin.orders.delivered_at'))
                    ->dateTime('Y-m-d H:i')
                    ->description(fn (CustomOrder $record) => $record->completed_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('items_cost')
                    ->label(__('admin.shoppers.payouts.items_cost'))
                    ->state(fn (CustomOrder $record) => $share($record, 'items_cost'))
                    ->money('SAR'),
                Tables\Columns\TextColumn::make('shopper_fees')
                    ->label(__('admin.custom_orders.shopper_fees'))
                    ->money('SAR'),
                Tables\Columns\TextColumn::make('commission')
                    ->label(__('admin.payouts.commission'))
                    ->state(fn (CustomOrder $record) => $share($record, 'commission_amount'))
                    ->money('SAR')
                    ->color('danger'),
                Tables\Columns\TextColumn::make('shopper_amount')
                    ->label(__('admin.shoppers.payouts.shopper_amount'))
                    ->state(fn (CustomOrder $record) => $share($record, 'shopper_amount'))
                    ->money('SAR')
                    ->weight('bold')
                    ->color('success'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('payoutAll')
                    ->label(__('admin.payouts.process'))
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->visible(fn () => $this->shopper()->financials()['available'] > 0)
                    ->modalHeading(__('admin.payouts.process'))
                    ->modalDescription(fn () => __('admin.payouts.process_description', [
                        'amount' => number_format($this->shopper()->financials()['available'], 2),
                        'count' => $this->shopper()->financials()['due_orders_count'],
                        'store' => $this->shopper()->user?->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.payouts.confirm'))
                    ->form(PayoutForm::schema())
                    ->action(fn (array $data) => PayoutForm::process($this, fn () => app(ShopperPayoutService::class)->payout($this->shopper(), null, $data, auth()->user()))),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('payoutSelected')
                    ->label(__('admin.payouts.process_selected'))
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->modalHeading(__('admin.payouts.process_selected'))
                    ->modalDescription(fn (Collection $records) => __('admin.payouts.process_description', [
                        'amount' => number_format($records->sum(fn (CustomOrder $o) => $share($o, 'shopper_amount')), 2),
                        'count' => $records->count(),
                        'store' => $this->shopper()->user?->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.payouts.confirm'))
                    ->form(PayoutForm::schema())
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records, array $data) => PayoutForm::process($this, fn () => app(ShopperPayoutService::class)->payout($this->shopper(), $records->modelKeys(), $data, auth()->user()))),
            ]);
    }
}
