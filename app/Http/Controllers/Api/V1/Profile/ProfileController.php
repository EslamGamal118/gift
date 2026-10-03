<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Exceptions\ProfileLockedException;
use App\Exceptions\ProfileStepException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Profile\Concerns\LoadsProfileRelations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ActivityRequest;
use App\Http\Requests\Profile\BasicInfoRequest;
use App\Http\Requests\Profile\ProfileUpdateRequest;
use App\Http\Requests\Profile\StoreDetailsRequest;
use App\Http\Resources\Profile\ProfileStateResource;
use App\Models\CaptainProfile;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\FileUploadService;
use App\Support\ProfileCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ProfileController extends Controller
{
    use LoadsProfileRelations;

    /**
     * Profile columns that require a new review when changed on an approved profile.
     *
     * @var array<int, string>
     */
    protected const REVIEW_COLUMNS = [
        'iban', 'iban_certificate_file', 'commercial_register_file',
        'vehicle_type_id', 'plate_number', 'license_file', 'plate_number_file', 'vehicle_image_file',
        'national_id_number', 'national_id_file', 'freelance_license_file',
    ];

    public function __construct(
        protected FileUploadService $files,
    ) {
    }

    /**
     * GET /api/v1/profile/status  (alias: GET /api/v1/profile)
     *
     * Current account state, verification flags, completion steps and the next required step.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->loadProfileFor($user);

        return ApiResponse::success('messages.success', new ProfileStateResource($user));
    }

    /**
     * POST /api/v1/profile/basic-info
     *
     * Step 1: creates the profile as a draft or updates it, storing the avatar and IBAN certificate.
     * Profiles under review or approved are rejected with 409 by BasicInfoRequest before validation.
     */
    public function basicInfo(BasicInfoRequest $request): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $role     = $user->user_type;
        $relation = $user->profileRelation();
        $profile  = $user->{$relation};

        $directory = $this->directoryFor($user);

        DB::transaction(function () use ($request, $user, $role, $relation, $profile, $directory) {
            $userData = [
                'name'  => $request->validated('name'),
                'email' => $request->validated('email'),
            ];

            if ($avatar = $request->avatarFile()) {
                $userData['avatar'] = $this->files->replace($avatar, $directory, $user->avatar);
            }

            $user->fill($userData)->save();

            $profileData = ['iban' => $request->validated('iban')];

            if ($certificate = $request->ibanCertificateFile()) {
                $profileData['iban_certificate_file'] = $this->files->replace(
                    $certificate,
                    $directory,
                    $profile?->iban_certificate_file
                );
            }

            // Store name / customer phone / contact email belong to step 2 (store-details).
            // Captains and shoppers share the account avatar as their personal photo.
            if ($role !== User::TYPE_STORE) {
                $profileData += array_filter(['personal_photo' => $user->avatar]);
            }

            $profileData += $this->reopenIfRejected($profile);

            $user->{$relation}()->updateOrCreate(['user_id' => $user->id], $profileData);
        });

        $this->loadProfileFor($user);

        return ApiResponse::success('profile.basic_info_saved', new ProfileStateResource($user));
    }

    /**
     * POST /api/v1/profile/store-details
     *
     * Step 2 for stores: store name, customer phone / email, category, description, logo, cover,
     * commercial register and working hours - all saved on the store profile.
     */
    public function storeDetails(StoreDetailsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var StoreProfile $profile */
        $profile   = $request->profile();
        $directory = $this->directoryFor($user);

        DB::transaction(function () use ($request, $profile, $directory) {
            $data = [
                'store_name'    => $request->validated('store_name'),
                'phone'         => $request->validated('phone'),
                'email'         => $request->validated('email'),
                'category_id'   => $request->validated('category_id'),
                'description'   => $request->validated('description'),
                'working_hours' => $request->normalizedWorkingHours(),
            ];

            $data += $this->storeUploads($directory, $profile, [
                'logo'                     => $request->logoFile(),
                'cover_image'              => $request->coverFile(),
                'commercial_register_file' => $request->commercialRegisterFile(),
            ]);

            $data += $this->reopenIfRejected($profile);

            $profile->fill($data)->save();
        });

        $this->loadProfileFor($user);

        return ApiResponse::success('profile.store_details_saved', new ProfileStateResource($user));
    }

    /**
     * POST /api/v1/profile/activity
     *
     * Step 2 for captains (vehicle & documents) and personal shoppers (categories & documents).
     */
    public function activity(ActivityRequest $request): JsonResponse
    {
        /** @var User $user */
        $user      = $request->user();
        $profile   = $request->profile();
        $directory = $this->directoryFor($user);

        DB::transaction(function () use ($request, $profile, $directory) {
            if ($profile instanceof CaptainProfile) {
                $data = [
                    'vehicle_type_id' => $request->validated('vehicle_type_id'),
                    'vehicle_model'   => $request->validated('vehicle_model'),
                    'plate_number'    => $request->validated('plate_number'),
                ];

                $data += $this->storeUploads($directory, $profile, [
                    'license_file'             => $request->file('driving_license'),
                    'plate_number_file'        => $request->file('plate_image'),
                    'vehicle_image_file'       => $request->file('vehicle_image'),
                    'commercial_register_file' => $request->commercialRegisterFile(),
                ]);
            } else {
                /** @var ShopperProfile $profile */
                $data = [
                    'national_id_number' => $request->validated('national_id_number'),
                ];

                $data += $this->storeUploads($directory, $profile, [
                    'national_id_file'       => $request->file('national_id'),
                    'driving_license_file'   => $request->file('driving_license'),
                    'freelance_license_file' => $request->file('freelance_certificate'),
                ]);

                $profile->categories()->sync($request->validated('category_ids'));
            }

            $data += $this->reopenIfRejected($profile);

            $profile->fill($data)->save();
        });

        $this->loadProfileFor($user);

        return ApiResponse::success('profile.activity_saved', new ProfileStateResource($user));
    }

    /**
     * POST|PUT /api/v1/profile/update
     *
     * Unified partial update for every account type. Only the fields present in the request
     * are changed. On an approved profile, changing a compliance field (documents, IBAN,
     * vehicle identity, national ID) sends the profile back to review.
     */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user      = $request->user();
        $profile   = $request->existingProfile();
        $directory = $this->directoryFor($user);

        $requiresReview = DB::transaction(function () use ($request, $user, $profile, $directory) {
            $userData = $request->safe()->only(['name', 'email']);

            if ($avatar = $request->avatarFile()) {
                $userData['avatar'] = $this->files->replace($avatar, $directory, $user->avatar);
            }

            $user->fill($userData)->save();

            if (! $profile) {
                return false;
            }

            $data = $this->profileUpdateData($request, $user, $profile, $directory);

            if ($profile instanceof ShopperProfile && $request->has('category_ids')) {
                $profile->categories()->sync($request->validated('category_ids'));
            }

            $profile->fill($data);

            $requiresReview = $profile->status === 'approved' && $profile->isDirty(self::REVIEW_COLUMNS);

            if ($requiresReview) {
                $profile->forceFill(['status' => 'pending', 'submitted_at' => now(), 'rejection_reason' => null]);
            } else {
                $profile->forceFill($this->reopenIfRejected($profile));
            }

            $profile->save();

            return $requiresReview;
        });

        $this->loadProfileFor($user);

        return ApiResponse::success(
            $requiresReview ? 'profile.updated_pending_review' : 'profile.updated',
            new ProfileStateResource($user)
        );
    }

    /**
     * POST /api/v1/profile/submit
     *
     * Sends a complete profile for review (draft / rejected -> pending).
     */
    public function submit(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->requiresProfile()) {
            return ApiResponse::error('profile.not_applicable', null, 422);
        }

        $profile = $user->profile() ?? throw ProfileStepException::basicInfoRequired();

        if (in_array($profile->status, ['pending', 'approved'], true)) {
            throw ProfileLockedException::forStatus($profile->status);
        }

        if ($missing = ProfileCompletion::missing($user, $profile)) {
            throw ProfileStepException::incomplete($missing);
        }

        $profile->forceFill([
            'status'           => 'pending',
            'submitted_at'     => now(),
            'rejection_reason' => null,
        ])->save();

        $this->loadProfileFor($user);

        return ApiResponse::success('profile.submitted_for_review', new ProfileStateResource($user));
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Role-specific column changes for the unified update (scalars present in the request + uploads).
     *
     * @return array<string, mixed>
     */
    protected function profileUpdateData(ProfileUpdateRequest $request, User $user, $profile, string $directory): array
    {
        $data = $request->safe()->only(['iban']);

        $uploads = ['iban_certificate_file' => $request->file('iban_certificate')];

        if ($profile instanceof StoreProfile) {
            $data += $request->safe()->only(['store_name', 'category_id', 'description']);

            // Store contact - `email` in this request is the owner's (users table)
            if ($request->has('store_phone')) {
                $data['phone'] = $request->validated('store_phone');
            }

            if ($request->has('store_email')) {
                $data['email'] = $request->validated('store_email');
            }

            if ($request->has('working_hours')) {
                $data['working_hours'] = $request->normalizedWorkingHours();
            }

            // A store's map location is its main branch, not a store_profiles column
            if ($request->filled('address')) {
                $profile->saveMainLocation($request->safe()->only(['address', 'latitude', 'longitude']));
            }

            $uploads += [
                'logo'                     => $request->file('logo'),
                'cover_image'              => $request->coverFile(),
                'commercial_register_file' => $request->commercialRegisterFile(),
            ];
        } elseif ($profile instanceof CaptainProfile) {
            $data += $request->safe()->only(['vehicle_type_id', 'vehicle_model', 'plate_number', 'address', 'latitude', 'longitude']);

            $uploads += [
                'license_file'             => $request->file('driving_license'),
                'plate_number_file'        => $request->file('plate_image'),
                'vehicle_image_file'       => $request->file('vehicle_image'),
                'commercial_register_file' => $request->commercialRegisterFile(),
            ];
        } else {
            $data += $request->safe()->only(['national_id_number', 'address', 'latitude', 'longitude']);

            $uploads += [
                'national_id_file'       => $request->file('national_id'),
                'driving_license_file'   => $request->file('driving_license'),
                'freelance_license_file' => $request->file('freelance_certificate'),
            ];
        }

        // Captains and shoppers share the account avatar as their personal photo.
        if (! $profile instanceof StoreProfile && $request->avatarFile()) {
            $data['personal_photo'] = $user->avatar;
        }

        return $data + $this->storeUploads($directory, $profile, $uploads);
    }

    protected function directoryFor(User $user): string
    {
        return "profiles/{$user->user_type}/{$user->id}";
    }

    /**
     * Store the provided uploads (key = column) and replace the previous file after commit.
     *
     * @param  array<string, \Illuminate\Http\UploadedFile|null>  $uploads
     * @return array<string, string>
     */
    protected function storeUploads(string $directory, $profile, array $uploads): array
    {
        $data = [];

        foreach (array_filter($uploads) as $column => $file) {
            $data[$column] = $this->files->replace($file, $directory, $profile->{$column});
        }

        return $data;
    }

    /**
     * A rejected profile returns to draft on any edit so it can be resubmitted.
     */
    protected function reopenIfRejected($profile): array
    {
        return $profile?->status === 'rejected'
            ? ['status' => 'draft', 'rejection_reason' => null]
            : [];
    }
}
