<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use App\Models\StoreProfile;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Creates the owner account (type store, signs in by OTP) together with the store.
 */
class CreateStore extends CreateRecord
{
    protected static string $resource = StoreResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['working_hours'] = StoreResource::normalizeWorkingHours($data['working_hours'] ?? null);

        if ($data['status'] !== StoreProfile::STATUS_REJECTED) {
            $data['rejection_reason'] = null;
        }

        if ($data['status'] !== StoreProfile::STATUS_DRAFT) {
            $data['submitted_at'] = now();
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): StoreProfile {
            $owner = User::create([
                'name' => $data['owner_name'],
                'phone' => $data['owner_phone'],
                'user_type' => User::TYPE_STORE,
                'status' => 'active',
                'phone_verified_at' => now(),
            ]);

            unset($data['owner_name'], $data['owner_phone']);

            return StoreProfile::create($data + ['user_id' => $owner->id]);
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
