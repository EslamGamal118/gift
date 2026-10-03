<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\Concerns\DescribesProfileState;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /profile and every profile write endpoint:
 *
 *   user       the account (users table)
 *   profile    the role profile in screen sections - basic_info, details, documents,
 *              working_hours, location (null for customers / before basic-info)
 *   flags      completion / review state
 *   next_step  home | complete_profile | pending_approval | profile_rejected | blocked
 */
class ProfileStateResource extends JsonResource
{
    use DescribesProfileState;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user    = $this->resource;
        $profile = $user->profile();

        return [
            'account_type' => $user->user_type,
            'user'         => new UserResource($user),
            'profile'      => $this->profileResource($user, $profile),
            'flags'        => $this->profileFlags($user, $profile),
            'next_step'    => $this->nextStep($user, $profile),
        ];
    }
}
