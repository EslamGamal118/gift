<?php

namespace App\Support;

use App\Models\CaptainProfile;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;

/**
 * Completion rules for each profile step, per role.
 * Used by the response flags and by the submit-for-review check.
 */
class ProfileCompletion
{
    /**
     * Fields still missing from step 1 (basic information).
     *
     * @return array<int, string>
     */
    public static function basicInfoMissing(User $user, StoreProfile|CaptainProfile|ShopperProfile|null $profile): array
    {
        if (! $user->requiresProfile()) {
            return [];
        }

        if (! $profile) {
            return ['profile'];
        }

        $missing = [];

        blank($user->name)                    && $missing[] = 'name';
        blank($user->email)                   && $missing[] = 'email';
        blank($profile->iban)                 && $missing[] = 'iban';
        blank($profile->iban_certificate_file) && $missing[] = 'iban_certificate';

        // Store name / contact are part of step 2 for stores
        if (! $user->isStore()) {
            blank($profile->personal_photo) && $missing[] = 'avatar';
        }

        return $missing;
    }

    /**
     * Fields still missing from step 2 (store details / vehicle & documents / categories & documents).
     *
     * @return array<int, string>
     */
    public static function activityMissing(User $user, StoreProfile|CaptainProfile|ShopperProfile|null $profile): array
    {
        if (! $user->requiresProfile()) {
            return [];
        }

        if (! $profile) {
            return ['profile'];
        }

        $missing = [];

        if ($profile instanceof StoreProfile) {
            blank($profile->store_name)                 && $missing[] = 'store_name';
            blank($profile->phone)                      && $missing[] = 'phone';
            blank($profile->category_id)                && $missing[] = 'category_id';
            blank($profile->logo)                       && $missing[] = 'logo';
            blank($profile->commercial_register_file)   && $missing[] = 'commercial_register';
            blank($profile->working_hours)              && $missing[] = 'working_hours';
        } elseif ($profile instanceof CaptainProfile) {
            blank($profile->vehicle_type_id)   && $missing[] = 'vehicle_type_id';
            blank($profile->vehicle_model)     && $missing[] = 'vehicle_model';
            blank($profile->plate_number)      && $missing[] = 'plate_number';
            blank($profile->license_file)      && $missing[] = 'driving_license';
            blank($profile->plate_number_file) && $missing[] = 'plate_image';
        } else {
            $profile->categories()->doesntExist()   && $missing[] = 'category_ids';
            blank($profile->national_id_file)       && $missing[] = 'national_id';
            blank($profile->freelance_license_file) && $missing[] = 'freelance_certificate';
        }

        return $missing;
    }

    /**
     * Fields still missing from step 3 (location): at least one branch for stores,
     * a confirmed map location for captains and shoppers.
     *
     * @return array<int, string>
     */
    public static function locationMissing(User $user, StoreProfile|CaptainProfile|ShopperProfile|null $profile): array
    {
        if (! $user->requiresProfile()) {
            return [];
        }

        if (! $profile) {
            return ['profile'];
        }

        if ($profile instanceof StoreProfile) {
            return $profile->branches()->doesntExist() ? ['branches'] : [];
        }

        $hasLocation = filled($profile->address)
            && $profile->latitude !== null
            && $profile->longitude !== null;

        return $hasLocation ? [] : ['location'];
    }

    public static function basicInfoCompleted(User $user, $profile): bool
    {
        return self::basicInfoMissing($user, $profile) === [];
    }

    public static function activityCompleted(User $user, $profile): bool
    {
        return self::activityMissing($user, $profile) === [];
    }

    public static function locationCompleted(User $user, $profile): bool
    {
        return self::locationMissing($user, $profile) === [];
    }

    /**
     * Everything still missing before the profile can be submitted for review.
     *
     * @return array<string, array<int, string>>
     */
    public static function missing(User $user, $profile): array
    {
        return array_filter([
            'basic_info' => self::basicInfoMissing($user, $profile),
            'activity'   => self::activityMissing($user, $profile),
            'location'   => self::locationMissing($user, $profile),
        ]);
    }

    /**
     * Whether the profile is complete and in a submittable state (draft or rejected).
     */
    public static function canSubmit(User $user, $profile): bool
    {
        return $profile !== null
            && in_array($profile->status, ['draft', 'rejected'], true)
            && self::missing($user, $profile) === [];
    }
}