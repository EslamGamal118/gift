<?php

namespace App\Http\Controllers\Api\V1\Profile\Concerns;

use App\Models\User;

trait LoadsProfileRelations
{
    /**
     * Load the profile and the relations each role's resource renders.
     */
    protected function loadProfileFor(User $user): void
    {
        match ($user->user_type) {
            User::TYPE_STORE   => $user->load(['storeProfile.category', 'storeProfile.mainBranch', 'storeProfile.branches']),
            User::TYPE_CAPTAIN => $user->load('captainProfile.vehicleType'),
            User::TYPE_SHOPPER => $user->load('shopperProfile.categories'),
            default            => null,
        };

        if ($user->isStore() && $user->storeProfile) {
            $user->storeProfile->loadCount('branches');
        }
    }
}
