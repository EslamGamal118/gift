<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Widgets\CustomerStatsOverview;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Customer profile: stats on top, then tabs — Overview (infolist), orders,
 * personal shopper orders, payments and reviews (relation managers).
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name ?: $this->getRecord()->phone;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('admin.customers.overview');
    }

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-identification';
    }

    protected function getHeaderWidgets(): array
    {
        return [CustomerStatsOverview::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\Action::make('toggleStatus')
                ->label(fn (User $record) => $record->isActive() ? __('admin.customers.actions.block') : __('admin.customers.actions.unblock'))
                ->icon(fn (User $record) => $record->isActive() ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                ->color(fn (User $record) => $record->isActive() ? 'danger' : 'success')
                ->requiresConfirmation()
                ->action(function (User $record): void {
                    CustomerResource::toggleStatus($record);
                    $this->dispatch('$refresh');
                }),
        ];
    }
}
