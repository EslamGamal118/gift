<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn (Category $record) => CategoryResource::canBeDeleted($record)),
        ];
    }

    /**
     * Fill name.ar / name.en with every translation (saving the array back
     * stores them all: HasTranslations::setAttribute).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['name'] = $this->getRecord()->getTranslations('name');

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
