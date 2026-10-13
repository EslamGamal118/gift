<?php

namespace App\Filament\Resources\ShopperResource\Pages;

use App\Filament\Resources\ShopperResource;
use App\Models\ShopperProfile;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Creates the shopper's account (type shopper, signs in by OTP) together with the profile.
 */
class CreateShopper extends CreateRecord
{
    protected static string $resource = ShopperResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($data['status'] !== ShopperProfile::STATUS_REJECTED) {
            $data['rejection_reason'] = null;
        }

        if ($data['status'] !== ShopperProfile::STATUS_DRAFT) {
            $data['submitted_at'] = now();
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): ShopperProfile {
            $account = User::create([
                'name' => $data['owner_name'],
                'phone' => $data['owner_phone'],
                'user_type' => User::TYPE_SHOPPER,
                'status' => 'active',
                'phone_verified_at' => now(),
                // The app shows the account avatar as the shopper's photo
                'avatar' => $data['personal_photo'] ?? null,
            ]);

            unset($data['owner_name'], $data['owner_phone']);

            return ShopperProfile::create($data + ['user_id' => $account->id]);
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
