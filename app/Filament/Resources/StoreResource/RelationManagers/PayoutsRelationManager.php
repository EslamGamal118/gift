<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Filament\Resources\StoreResource;
use App\Filament\Support\PayoutForm;
use App\Models\StorePayout;
use App\Services\StorePayoutService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * History of money transferred to the store (the payout ledger). Read-only:
 * a payout is recorded once and never edited.
 */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.payouts.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->payouts()->count();
    }

    protected static function getModelLabel(): ?string
    {
        return __('admin.payouts.model');
    }

    #[On('finances-updated')]
    public function refreshPayouts(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('creator'))
            ->defaultSort('transferred_at', 'desc')
            ->emptyStateHeading(__('admin.payouts.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->prefix('#'),
                Tables\Columns\TextColumn::make('reference')
                    ->label(__('admin.payouts.reference'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('admin.payouts.amount'))
                    ->money(fn (StorePayout $record) => $record->currency)
                    ->weight('bold')
                    ->color('success')
                    ->sortable()
                    ->summarize(Sum::make()->money('SAR')->label(__('admin.payouts.total'))),
                Tables\Columns\TextColumn::make('orders_count')
                    ->label(__('admin.payouts.orders_count'))
                    ->badge()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('method')
                    ->label(__('admin.payouts.method'))
                    ->formatStateUsing(fn (string $state) => PayoutForm::methodOptions()[$state] ?? $state)
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('transferred_at')
                    ->label(__('admin.payouts.transferred_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('receipt_path')
                    ->label(__('admin.payouts.receipt'))
                    ->formatStateUsing(fn () => __('admin.stores.fields.open_file'))
                    ->url(fn (StorePayout $record) => StoreResource::fileUrl($record->receipt_path), shouldOpenInNewTab: true)
                    ->icon('heroicon-m-paper-clip')
                    ->color('primary')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('notes')
                    ->label(__('admin.payouts.notes'))
                    ->limit(40)
                    ->tooltip(fn (StorePayout $record) => $record->notes)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label(__('admin.payouts.created_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('method')
                    ->label(__('admin.payouts.method'))
                    ->options(PayoutForm::methodOptions()),
            ])
            ->headerActions([
                Tables\Actions\Action::make('payoutAll')
                    ->label(__('admin.payouts.process'))
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->visible(fn () => $this->getOwnerRecord()->financials()['available'] > 0)
                    ->modalHeading(__('admin.payouts.process'))
                    ->modalDescription(fn () => __('admin.payouts.process_description', [
                        'amount' => number_format($this->getOwnerRecord()->financials()['available'], 2),
                        'count' => $this->getOwnerRecord()->financials()['due_orders_count'],
                        'store' => $this->getOwnerRecord()->store_name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.payouts.confirm'))
                    ->form(PayoutForm::schema())
                    ->action(fn (array $data) => PayoutForm::process($this, fn () => app(StorePayoutService::class)->payout($this->getOwnerRecord(), null, $data, auth()->user()))),
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
                    Infolists\Components\TextEntry::make('amount')->label(__('admin.payouts.amount'))->money('SAR')->weight('bold'),
                    Infolists\Components\TextEntry::make('method')
                        ->label(__('admin.payouts.method'))
                        ->formatStateUsing(fn (string $state) => PayoutForm::methodOptions()[$state] ?? $state),
                    Infolists\Components\TextEntry::make('transferred_at')->label(__('admin.payouts.transferred_at'))->dateTime('Y-m-d H:i'),
                    Infolists\Components\TextEntry::make('reference')->label(__('admin.payouts.reference'))->copyable()->placeholder('—'),
                    Infolists\Components\TextEntry::make('creator.name')->label(__('admin.payouts.created_by'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('receipt_path')
                        ->label(__('admin.payouts.receipt'))
                        ->formatStateUsing(fn () => __('admin.stores.fields.open_file'))
                        ->url(fn (StorePayout $record) => StoreResource::fileUrl($record->receipt_path), shouldOpenInNewTab: true)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('notes')->label(__('admin.payouts.notes'))->placeholder('—')->columnSpanFull(),
                ]),
            Infolists\Components\Section::make(__('admin.payouts.settled_orders'))
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            Infolists\Components\TextEntry::make('order.order_number')->label(__('admin.orders.number')),
                            Infolists\Components\TextEntry::make('subtotal')->label(__('admin.orders.subtotal'))->money('SAR'),
                            Infolists\Components\TextEntry::make('commission_rate')->label(__('admin.stores.fields.commission_rate'))->suffix('%'),
                            Infolists\Components\TextEntry::make('commission_amount')->label(__('admin.payouts.commission'))->money('SAR'),
                            Infolists\Components\TextEntry::make('store_amount')->label(__('admin.orders.store_share'))->money('SAR'),
                        ]),
                ]),
        ]);
    }
}
