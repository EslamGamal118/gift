<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Product details, then the add-ons a customer can order with it.
 */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('admin.products.tabs.details');
    }

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-gift';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
