<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use App\Models\StoreProfile;
use App\Services\ProfileModerationService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

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
        $data['working_hours'] = StoreResource::normalizeWorkingHours($data['working_hours'] ?? null);

        if ($data['status'] !== StoreProfile::STATUS_REJECTED) {
            $data['rejection_reason'] = null;
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->statusBeforeSave = $this->getRecord()->getOriginal('status');
    }

    /**
     * A status changed from the form is a moderation decision too: notify the owner.
     */
    protected function afterSave(): void
    {
        /** @var StoreProfile $store */
        $store = $this->getRecord();

        if ($store->status === $this->statusBeforeSave) {
            return;
        }

        match ($store->status) {
            StoreProfile::STATUS_APPROVED => app(ProfileModerationService::class)->approve($store),
            StoreProfile::STATUS_REJECTED => app(ProfileModerationService::class)->reject($store, (string) $store->rejection_reason),
            default => null,
        };
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
