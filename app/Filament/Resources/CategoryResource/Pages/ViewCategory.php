<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Category details, then tabs: its products and the add-ons linked to it.
 */
class ViewCategory extends ViewRecord
{
    protected static string $resource = CategoryResource::class;

    public function getTitle(): string
    {
        return (string) $this->getRecord()->name;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('admin.categories.tabs.details');
    }

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-tag';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn (Category $record) => CategoryResource::canBeDeleted($record)),
        ];
    }
}
