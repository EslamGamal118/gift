<?php

namespace App\Filament\Resources\AddonResource\Pages;

use App\Filament\Resources\AddonResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAddon extends CreateRecord
{
    protected static string $resource = AddonResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
