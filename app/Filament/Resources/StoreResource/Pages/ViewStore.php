<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Widgets\StoreStatsOverview;
use App\Filament\Support\ProfileActions;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Store profile: financial stats on top, then tabs — Overview (infolist),
 * products & add-ons, orders, finance (dues & payouts) and reviews.
 */
class ViewStore extends ViewRecord
{
    protected static string $resource = StoreResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->store_name ?: __('admin.stores.model');
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('admin.stores.tabs.overview');
    }

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-identification';
    }

    protected function getHeaderWidgets(): array
    {
        return [StoreStatsOverview::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 5;
    }

    protected function getHeaderActions(): array
    {
        return [
            ProfileActions::approve(Actions\Action::class, 'stores'),
            ProfileActions::reject(Actions\Action::class, 'stores'),
            Actions\EditAction::make(),
            Actions\ActionGroup::make([
                ProfileActions::suspend(Actions\Action::class, 'stores'),
                ProfileActions::reactivate(Actions\Action::class, 'stores'),
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
