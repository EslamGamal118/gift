<?php

namespace App\Filament\Resources\ShopperResource\Pages;

use App\Filament\Resources\ShopperResource;
use App\Filament\Resources\ShopperResource\Widgets\ShopperStatsOverview;
use App\Filament\Support\ProfileActions;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Shopper profile: metrics on top, then tabs — Overview & identity (infolist),
 * custom orders, finance (dues & payouts) and reviews.
 */
class ViewShopper extends ViewRecord
{
    protected static string $resource = ShopperResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->user?->name ?: __('admin.shoppers.model');
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('admin.shoppers.tabs.overview');
    }

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-identification';
    }

    protected function getHeaderWidgets(): array
    {
        return [ShopperStatsOverview::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            ProfileActions::approve(Actions\Action::class, 'shoppers'),
            ProfileActions::reject(Actions\Action::class, 'shoppers'),
            Actions\EditAction::make(),
            Actions\ActionGroup::make([
                ProfileActions::suspend(Actions\Action::class, 'shoppers'),
                ProfileActions::reactivate(Actions\Action::class, 'shoppers'),
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
            ]),
        ];
    }
}
