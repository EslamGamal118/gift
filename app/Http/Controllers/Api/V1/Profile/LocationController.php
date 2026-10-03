<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Profile\Concerns\LoadsProfileRelations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\LocationRequest;
use App\Http\Resources\Profile\ProfileStateResource;
use App\Models\CaptainProfile;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
class LocationController extends Controller
{
    use LoadsProfileRelations;

    public function __invoke(LocationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user    = $request->user();
        $profile = $request->profile();

        DB::transaction(function () use ($request, $profile) {
            match (true) {
                $profile instanceof StoreProfile => $this->saveStoreLocation($request, $profile),
                default                          => $this->saveProfileLocation($request, $profile),
            };
            if ($profile->status === 'rejected') {
                $profile->forceFill(['status' => 'draft', 'rejection_reason' => null])->save();
            }
        });

        $this->loadProfileFor($user);

        return ApiResponse::success('profile.location_saved', new ProfileStateResource($user));
    }

    protected function saveProfileLocation(LocationRequest $request, CaptainProfile|ShopperProfile $profile): void
    {
        $profile->fill($request->safe()->only(['address', 'latitude', 'longitude']))->save();
    }

    protected function saveStoreLocation(LocationRequest $request, StoreProfile $store): void
    {
        $store->saveMainLocation($request->safe()->only(['address', 'latitude', 'longitude', 'name', 'phone']));
    }
}
