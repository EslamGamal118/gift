<?php

namespace App\Filament\Resources\ShopperResource\Pages;

use App\Filament\Resources\ShopperResource;
use App\Models\ShopperProfile;
use App\Services\ProfileModerationService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShopper extends EditRecord
{
    protected static string $resource = ShopperResource::class;

    protected ?string $statusBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['status'] !== ShopperProfile::STATUS_REJECTED) {
            $data['rejection_reason'] = null;
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->statusBeforeSave = $this->getRecord()->getOriginal('status');
    }

    /**
     * A status changed from the form is a moderation decision too: notify the shopper.
     */
    protected function afterSave(): void
    {
        /** @var ShopperProfile $shopper */
        $shopper = $this->getRecord();

        if ($shopper->status === $this->statusBeforeSave) {
            return;
        }

        match ($shopper->status) {
            ShopperProfile::STATUS_APPROVED => app(ProfileModerationService::class)->approve($shopper),
            ShopperProfile::STATUS_REJECTED => app(ProfileModerationService::class)->reject($shopper, (string) $shopper->rejection_reason),
            default => null,
        };
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
