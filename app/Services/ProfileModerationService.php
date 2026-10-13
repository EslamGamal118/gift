<?php

namespace App\Services;

use App\Models\NotificationContent;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin decisions on a seller profile (store or personal shopper): approve or
 * reject it, suspend or reactivate it. Suspension blocks the owner account
 * (users.status), which hides the profile from the app and stops the owner
 * from signing in. The owner gets an inbox notification + push for every
 * decision (notifications.{store|shopper}_{approved|rejected|suspended|reactivated}).
 */
class ProfileModerationService
{
    public function __construct(protected NotificationService $notifications) {}

    public function approve(StoreProfile|ShopperProfile $profile): void
    {
        $profile->update(['status' => 'approved', 'rejection_reason' => null]);

        $this->notify($profile, 'approved');
    }

    public function reject(StoreProfile|ShopperProfile $profile, string $reason): void
    {
        $profile->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        $this->notify($profile, 'rejected', ['reason' => $reason]);
    }

    public function suspend(StoreProfile|ShopperProfile $profile, string $reason): void
    {
        DB::transaction(function () use ($profile, $reason): void {
            $profile->user()->update(['status' => 'blocked']);
            $profile->update(['suspension_reason' => $reason, 'suspended_at' => now()]);
        });
        $profile->load('user');

        // Sent even though the owner is blocked: it lands in the inbox and as a push
        $this->notify($profile, 'suspended', ['reason' => $reason]);
    }

    public function reactivate(StoreProfile|ShopperProfile $profile): void
    {
        DB::transaction(function () use ($profile): void {
            $profile->user()->update(['status' => 'active']);
            $profile->update(['suspension_reason' => null, 'suspended_at' => null]);
        });
        $profile->load('user');

        $this->notify($profile, 'reactivated');
    }

    /**
     * @param  array<string, string>  $params
     */
    protected function notify(StoreProfile|ShopperProfile $profile, string $decision, array $params = []): void
    {
        $kind = $profile instanceof StoreProfile ? 'store' : 'shopper';
        $name = $profile instanceof StoreProfile ? (string) $profile->store_name : (string) $profile->user?->name;

        try {
            $this->notifications->sendFromSystem(
                recipients: $profile->user()->first(),
                titleKey: "notifications.{$kind}_{$decision}_title",
                bodyKey: "notifications.{$kind}_{$decision}_body",
                titleParams: ['store_name' => $name],
                bodyParams: $params + ['store_name' => $name],
                type: NotificationContent::TYPE_ACCOUNT_STATUS,
                data: ["{$kind}_profile_id" => (string) $profile->id, 'status' => $decision],
            );
        } catch (Throwable $e) {
            // The decision stands even if the notification fails
            Log::error('Profile moderation notification failed', ['kind' => $kind, 'profile_id' => $profile->id, 'decision' => $decision, 'error' => $e->getMessage()]);
        }
    }
}
