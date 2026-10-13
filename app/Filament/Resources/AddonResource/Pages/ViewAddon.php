<?php

namespace App\Filament\Resources\AddonResource\Pages;

use App\Filament\Resources\AddonResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Add-on details, then the products it is offered with.
 */
class ViewAddon extends ViewRecord
{
    protected static string $resource = AddonResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('admin.addons.tabs.details');
    }

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-sparkles';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
