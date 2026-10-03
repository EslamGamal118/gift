<?php

namespace App\Http\Resources\Concerns;

use App\Http\Resources\CaptainProfileResource;
use App\Http\Resources\ShopperProfileResource;
use App\Http\Resources\StoreProfileResource;
use App\Models\User;
use App\Support\ProfileCompletion;
use Illuminate\Http\Resources\Json\JsonResource;


trait DescribesProfileState
{
    /**
     * The role's profile laid out in screen sections (see ProfileSectionsResource),
     * or null for customers / before the basic-info step.
     */
    protected function profileResource(User $user, $profile): ?JsonResource
    {
        if (! $profile) {
            return null;
        }

        $profile->setRelation('user', $user);

        return match ($user->user_type) {
            User::TYPE_STORE   => new StoreProfileResource($profile->loadMissing(['category', 'mainBranch', 'branches'])),
            User::TYPE_CAPTAIN => new CaptainProfileResource($profile->loadMissing('vehicleType')),
            User::TYPE_SHOPPER => new ShopperProfileResource($profile->loadMissing('categories')),
            default            => null,
        };
    }

    /**
     * أعلام موحّدة تساعد التطبيق على تحديد الشاشة التالية بغض النظر عن نوع المستخدم.
     *
     * @return array<string, mixed>
     */
    protected function profileFlags(User $user, $profile): array
    {
        $requiresProfile = $user->requiresProfile();
        $status          = $profile?->status;
        $isApproved      = (bool) $profile?->isApproved();

        return [
            'is_active'            => $user->isActive(),
            'phone_verified'       => $user->hasVerifiedPhone(),
            'requires_profile'     => $requiresProfile,
            'has_profile'          => $profile !== null,
            'profile_status'       => $status,
            // خطوات الإكمال (انظر App\Support\ProfileCompletion)
            'basic_info_completed' => ProfileCompletion::basicInfoCompleted($user, $profile),
            'activity_completed'   => ProfileCompletion::activityCompleted($user, $profile),
            'location_completed'   => ProfileCompletion::locationCompleted($user, $profile),
            'can_submit'           => ProfileCompletion::canSubmit($user, $profile),
            'missing'              => ProfileCompletion::missing($user, $profile),
            // تم إرسال البروفايل للمراجعة أو تمت الموافقة عليه
            'is_profile_completed' => $profile !== null && $status !== 'draft',
            'is_approved'          => $isApproved,
            'is_pending_approval'  => $status === 'pending',
            'is_rejected'          => $status === 'rejected',
            // العميل يعمل مباشرة؛ الأنواع الأخرى بعد الموافقة على البروفايل
            'can_operate'          => $user->isActive() && (! $requiresProfile || $isApproved),
        ];
    }

    /**
     * الخطوة التالية المقترحة للتطبيق:
     *  home | complete_profile | pending_approval | profile_rejected | blocked
     */
    protected function nextStep(User $user, $profile): string
    {
        if (! $user->isActive()) {
            return 'blocked';
        }

        if (! $user->requiresProfile()) {
            return 'home';
        }

        return match ($profile?->status) {
            'approved' => 'home',
            'pending'  => 'pending_approval',
            'rejected' => 'profile_rejected',
            default    => 'complete_profile', // null أو draft
        };
    }
}
