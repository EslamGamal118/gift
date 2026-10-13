<?php

namespace App\Filament\Support;

use App\Models\ShopperProfile;
use App\Models\StoreProfile;

/**
 * Status display of a seller profile (store or personal shopper): its review
 * status (draft | pending | approved | rejected), shown as `suspended` when the
 * owner account is blocked. Labels come from admin.{stores|shoppers}.statuses.
 */
class ProfileStatus
{
    public const SUSPENDED = 'suspended';

    public static function of(StoreProfile|ShopperProfile $profile): string
    {
        return $profile->isSuspended() ? self::SUSPENDED : $profile->status;
    }

    public static function label(string $group, ?string $status): ?string
    {
        return $status ? __("admin.$group.statuses.$status") : null;
    }

    public static function color(?string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'pending' => 'warning',
            'rejected', self::SUSPENDED => 'danger',
            default => 'gray',
        };
    }

    public static function icon(?string $status): string
    {
        return match ($status) {
            'approved' => 'heroicon-m-check-badge',
            'pending' => 'heroicon-m-clock',
            'rejected' => 'heroicon-m-x-circle',
            self::SUSPENDED => 'heroicon-m-no-symbol',
            default => 'heroicon-m-pencil-square',
        };
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, string>
     */
    public static function options(string $group, array $statuses, bool $withSuspended = false): array
    {
        $all = $withSuspended ? [...$statuses, self::SUSPENDED] : $statuses;

        return collect($all)->mapWithKeys(fn (string $s) => [$s => self::label($group, $s)])->all();
    }

    /**
     * Why the profile is rejected or suspended (badge tooltip).
     */
    public static function reason(StoreProfile|ShopperProfile $profile): ?string
    {
        return match (true) {
            $profile->isSuspended() => $profile->suspension_reason,
            $profile->status === 'rejected' => $profile->rejection_reason,
            default => null,
        };
    }
}
