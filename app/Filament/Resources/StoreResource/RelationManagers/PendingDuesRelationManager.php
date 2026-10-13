<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Filament\Support\PayoutForm;
use App\Models\Order;
use App\Models\StoreProfile;
use App\Services\StorePayoutService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Delivered, paid orders not paid out to the store yet — its available
 * balance — and the action to pay them out (all of them, or a selection).
 */
class PendingDuesRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $icon = 'heroicon-o-clock';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.payouts.pending_title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = app(StorePayoutService::class)->dueOrders($ownerRecord)->count();

        return $count ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.orders.model');
    }

    #[On('finances-updated')]
    public function refreshDues(): void
    {
        $this->resetTable();
    }

    protected function store(): StoreProfile
    {
        return $this->getOwnerRecord();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(StorePayoutService::class)->dueOrders($this->store())->with('user'))
            ->recordTitleAttribute('order_number')
            ->heading(__('admin.payouts.pending_title'))
            ->description(fn () => __('admin.payouts.pending_description', [
                'amount' => number_format($this->store()->financials()['available'], 2),
                'rate' => rtrim(rtrim((string) $this->store()->commission_rate, '0'), '.'),
            ]))
            ->defaultSort('delivered_at')
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
                Tables\Columns\TextColumn::make('delivered_at')
                    ->label(__('admin.orders.delivered_at'))
                    ->dateTime('Y-m-d H:i')
                    ->description(fn (Order $record) => $record->delivered_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('subtotal')
                    ->label(__('admin.orders.subtotal'))
                    ->money('SAR')
                    ->summarize(Sum::make()->money('SAR')->label(__('admin.payouts.total'))),
                Tables\Columns\TextColumn::make('commission')
                    ->label(__('admin.payouts.commission'))
                    ->state(fn (Order $record) => $this->store()->commissionFor((float) $record->subtotal))
                    ->money('SAR')
                    ->color('danger'),
                Tables\Columns\TextColumn::make('store_share')
                    ->label(__('admin.orders.store_share'))
                    ->state(fn (Order $record) => round((float) $record->subtotal - $this->store()->commissionFor((float) $record->subtotal), 2))
                    ->money('SAR')
                    ->weight('bold')
                    ->color('success'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('payoutAll')
                    ->label(__('admin.payouts.process'))
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->visible(fn () => $this->store()->financials()['available'] > 0)
                    ->modalHeading(__('admin.payouts.process'))
                    ->modalDescription(fn () => __('admin.payouts.process_description', [
                        'amount' => number_format($this->store()->financials()['available'], 2),
                        'count' => $this->store()->financials()['due_orders_count'],
                        'store' => $this->store()->store_name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.payouts.confirm'))
                    ->form(PayoutForm::schema())
                    ->action(fn (array $data) => PayoutForm::process($this, fn () => app(StorePayoutService::class)->payout($this->store(), null, $data, auth()->user()))),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('payoutSelected')
                    ->label(__('admin.payouts.process_selected'))
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->modalHeading(__('admin.payouts.process_selected'))
                    ->modalDescription(fn (Collection $records) => __('admin.payouts.process_description', [
                        'amount' => number_format($records->sum(fn (Order $o) => (float) $o->subtotal - $this->store()->commissionFor((float) $o->subtotal)), 2),
                        'count' => $records->count(),
                        'store' => $this->store()->store_name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.payouts.confirm'))
                    ->form(PayoutForm::schema())
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records, array $data) => PayoutForm::process($this, fn () => app(StorePayoutService::class)->payout($this->store(), $records->modelKeys(), $data, auth()->user()))),
            ]);
    }
}
