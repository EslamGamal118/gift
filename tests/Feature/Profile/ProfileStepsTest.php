<?php

namespace Tests\Feature\Profile;

use App\Models\CaptainProfile;
use App\Models\Category;
use App\Models\ShopperProfile;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileStepsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['user_type' => $role]);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A draft profile as produced by the basic-info step (no step-2 data yet).
     */
    protected function draftProfileFor(User $user): StoreProfile|CaptainProfile|ShopperProfile
    {
        return match ($user->user_type) {
            'store'   => StoreProfile::factory()->draft()->create([
                'user_id' => $user->id, 'category_id' => null, 'logo' => null, 'cover_image' => null,
                'commercial_register_file' => null, 'working_hours' => null, 'description' => null,
            ]),
            'captain' => CaptainProfile::factory()->draft()->create([
                'user_id' => $user->id, 'vehicle_type_id' => null, 'vehicle_model' => null, 'plate_number' => null,
                'plate_number_file' => null, 'license_file' => null, 'plate_image_file' => null,
                'address' => null, 'latitude' => null, 'longitude' => null,
            ]),
            'shopper' => ShopperProfile::factory()->draft()->create([
                'user_id' => $user->id, 'national_id_number' => null, 'national_id_file' => null,
                'driving_license_file' => null, 'freelance_license_file' => null,
                'address' => null, 'latitude' => null, 'longitude' => null,
            ]),
        };
    }

    protected function workingHours(array $overrides = []): array
    {
        $hours = [];

        foreach (StoreProfile::DAYS as $day) {
            $hours[$day] = $day === 'friday'
                ? ['is_open' => false]
                : ['is_open' => true, 'from' => '09:00', 'to' => '23:00'];
        }

        return array_replace_recursive($hours, $overrides);
    }

    /* ----------------------------------------------------------- lookups */

    public function test_categories_endpoint_returns_active_categories_in_request_language(): void
    {
        Category::factory()->create(['name' => ['en' => 'Flowers & Gifts', 'ar' => 'ورود وهدايا']]);
        Category::factory()->create(['name' => ['en' => 'Perfumes', 'ar' => 'عطور']]);
        Category::factory()->inactive()->create(['name' => ['en' => 'Hidden', 'ar' => 'مخفي']]);

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'ورود وهدايا')
            ->assertJsonMissing(['name' => 'مخفي']);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/categories?search=perf')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Perfumes');
    }

    public function test_vehicle_types_endpoint_returns_active_types(): void
    {
        VehicleType::factory()->create(['name' => ['en' => 'Motorcycle', 'ar' => 'دراجة نارية']]);
        VehicleType::factory()->inactive()->create();

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/vehicle-types')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'دراجة نارية');
    }

    /* ----------------------------------------------------- store details */

    public function test_store_can_save_details_with_files_and_working_hours(): void
    {
        $user     = $this->actingAsRole('store');
        $this->draftProfileFor($user);
        $category = Category::factory()->create(['name' => ['en' => 'Sweets', 'ar' => 'حلويات']]);

        $response = $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/profile/store-details', [
            'account_type'        => 'store',
            'store_name'          => 'حلويات الرياض',
            'phone'               => '0501112233',
            'email'               => 'care@example.com',
            'category_id'         => $category->id,
            'description'         => 'أفضل الحلويات الشرقية',
            'logo'                => UploadedFile::fake()->image('logo.png', 300, 300),
            'banner'              => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            'commercial_register' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf'),
            'working_hours'       => json_encode($this->workingHours(['thursday' => ['from' => '16:00', 'to' => '02:00']])),
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'تم حفظ تفاصيل المتجر بنجاح.')
            ->assertJsonPath('data.profile.details.store_name', 'حلويات الرياض')
            ->assertJsonPath('data.profile.details.category_id', $category->id)
            ->assertJsonPath('data.profile.details.category.name', 'حلويات')
            ->assertJsonPath('data.profile.working_hours.days.6', ['day' => 'friday', 'label' => 'الجمعة', 'is_open' => false, 'from' => null, 'to' => null])
            ->assertJsonPath('data.profile.working_hours.days.5', ['day' => 'thursday', 'label' => 'الخميس', 'is_open' => true, 'from' => '16:00', 'to' => '02:00'])
            ->assertJsonPath('data.flags.activity_completed', true)
            ->assertJsonPath('data.flags.location_completed', false)
            ->assertJsonPath('data.flags.can_submit', false)
            ->assertJsonPath('data.flags.missing', ['location' => ['branches']]);

        $profile = StoreProfile::where('user_id', $user->id)->firstOrFail();

        foreach (['logo', 'cover_image', 'commercial_register_file'] as $column) {
            $this->assertStringStartsWith("profiles/store/{$user->id}/", $profile->{$column});
            Storage::disk('public')->assertExists($profile->{$column});
        }
    }

    public function test_store_details_validation_errors_are_localized(): void
    {
        $user = $this->actingAsRole('store');
        $this->draftProfileFor($user);
        Category::factory()->inactive()->create(['id' => 99]);

        $response = $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/profile/store-details', [
            'category_id'   => 99,                  // inactive
            'working_hours' => [
                'saturday' => ['is_open' => true, 'from' => '09:00', 'to' => '09:00'],   // same time
                'sunday'   => ['is_open' => true],                                       // missing times
                'monday'   => ['is_open' => false],
                'tuesday'  => ['is_open' => false],
                'wednesday' => ['is_open' => false],
                'thursday' => ['is_open' => false],
                'friday'   => ['is_open' => false],
                'someday'  => ['is_open' => false],                                      // unknown day
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.errors.category_id.0', 'القيمة المحددة في التصنيف غير موجودة.')
            ->assertJsonPath('data.errors.logo.0', 'الشعار مطلوب.')
            ->assertJsonPath('data.errors.commercial_register.0', 'السجل التجاري مطلوب.');

        // dotted keys can't be addressed through assertJsonPath
        $errors = $response->json('data.errors');
        $this->assertSame('يجب أن يختلف وقت الإغلاق عن وقت الفتح.', $errors['working_hours.saturday.to'][0]);
        $this->assertSame('وقت الفتح ليوم الأحد مطلوب.', $errors['working_hours.sunday.from'][0]);
        $this->assertSame('someday ليس يومًا صالحًا من أيام الأسبوع.', $errors['working_hours.someday'][0]);

        $this->postJson('/api/v1/profile/store-details', ['working_hours' => ['saturday' => ['is_open' => false]]])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.working_hours.0', __('profile.working_hours_all_days'));
    }

    public function test_store_details_are_optional_on_resubmission_when_files_exist(): void
    {
        $user    = $this->actingAsRole('store');
        $profile = StoreProfile::factory()->draft()->create(['user_id' => $user->id]);   // factory has logo & CR
        $oldLogo = $profile->logo;

        $this->postJson('/api/v1/profile/store-details', [
            'store_name'    => 'Riyadh Sweets',
            'phone'         => '0501112233',
            'category_id'   => Category::factory()->create()->id,
            'working_hours' => $this->workingHours(),
        ])->assertOk();

        $this->assertSame($oldLogo, $profile->fresh()->logo);
    }

    public function test_store_details_step_is_rejected_for_other_roles_and_without_basic_info(): void
    {
        $captain = $this->actingAsRole('captain');
        $this->draftProfileFor($captain);

        $this->postJson('/api/v1/profile/store-details', [])
            ->assertStatus(403)
            ->assertJsonPath('message', __('profile.role_not_allowed'))
            ->assertJsonPath('data.account_type', 'captain');

        $this->actingAsRole('store');   // no profile yet

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/profile/store-details', [])
            ->assertStatus(409)
            ->assertJsonPath('message', 'يرجى إكمال خطوة البيانات الأساسية أولًا.');
    }

    /* ---------------------------------------------------------- activity */

    public function test_captain_can_save_vehicle_and_documents(): void
    {
        $user    = $this->actingAsRole('captain');
        $this->draftProfileFor($user);
        $vehicle = VehicleType::factory()->create(['name' => ['en' => 'Sedan Car', 'ar' => 'سيارة صالون']]);

        $response = $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/profile/activity', [
            'account_type'    => 'delivery_captain',
            'vehicle_type_id' => $vehicle->id,
            'vehicle_model'   => 'Hyundai Accent 2021',
            'plate_number'    => '  abc   1234 ',
            'driving_license' => UploadedFile::fake()->image('license.jpg'),
            'plate_image'     => UploadedFile::fake()->image('plate.jpg'),
            'freelance_certificate' => UploadedFile::fake()->create('freelance.pdf', 80, 'application/pdf'),
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Activity details saved successfully.')
            ->assertJsonPath('data.profile.details.vehicle_type.id', $vehicle->id)
            ->assertJsonPath('data.profile.details.vehicle_type.name', 'Sedan Car')
            ->assertJsonPath('data.profile.details.vehicle_model', 'Hyundai Accent 2021')
            ->assertJsonPath('data.profile.details.plate_number', 'ABC 1234')
            ->assertJsonPath('data.flags.activity_completed', true)
            ->assertJsonPath('data.flags.missing', ['location' => ['location']])
            ->assertJsonPath('data.flags.can_submit', false);

        $profile = CaptainProfile::where('user_id', $user->id)->firstOrFail();

        foreach (['license_file', 'plate_number_file', 'commercial_register_file'] as $column) {
            Storage::disk('public')->assertExists($profile->{$column});
        }
    }

    public function test_captain_activity_validation(): void
    {
        $user = $this->actingAsRole('captain');
        $this->draftProfileFor($user);
        VehicleType::factory()->inactive()->create(['id' => 7]);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/profile/activity', [
            'vehicle_type_id' => 7,
            'plate_number'    => 'ABC@123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.vehicle_type_id.0', 'القيمة المحددة في نوع المركبة غير موجودة.')
            ->assertJsonPath('data.errors.vehicle_model.0', 'موديل المركبة مطلوب.')
            ->assertJsonPath('data.errors.plate_number.0', __('profile.invalid_plate_number'))
            ->assertJsonPath('data.errors.driving_license.0', 'رخصة القيادة مطلوب.')
            ->assertJsonPath('data.errors.plate_image.0', 'صورة لوحة المركبة مطلوب.');
    }

    public function test_shopper_can_save_categories_and_documents(): void
    {
        $user       = $this->actingAsRole('shopper');
        $profile    = $this->draftProfileFor($user);
        $categories = Category::factory()->count(3)->create();

        $response = $this->postJson('/api/v1/profile/activity', [
            'account_type'          => 'personal_shopper',
            'category_ids'          => json_encode([$categories[0]->id, $categories[2]->id]),   // JSON string form
            'national_id_number'    => '1012345678',
            'national_id'           => UploadedFile::fake()->image('id.jpg'),
            'driving_license'       => UploadedFile::fake()->image('dl.jpg'),
            'freelance_certificate' => UploadedFile::fake()->create('freelance.pdf', 80, 'application/pdf'),
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.profile.details.categories')
            ->assertJsonPath('data.profile.details.national_id_number', '1012345678')
            ->assertJsonPath('data.flags.activity_completed', true)
            ->assertJsonPath('data.flags.missing', ['location' => ['location']])
            ->assertJsonPath('data.flags.can_submit', false);

        $this->assertEqualsCanonicalizing(
            [$categories[0]->id, $categories[2]->id],
            $profile->categories()->pluck('categories.id')->all()
        );
        $this->assertNotNull($profile->fresh()->driving_license_file);

        // re-submitting with a plain array replaces the selection (sync), files stay optional
        $this->postJson('/api/v1/profile/activity', ['category_ids' => [$categories[1]->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.profile.details.categories');

        $this->assertSame([$categories[1]->id], $profile->categories()->pluck('categories.id')->all());
    }

    public function test_shopper_activity_validation(): void
    {
        $user = $this->actingAsRole('shopper');
        $this->draftProfileFor($user);
        Category::factory()->inactive()->create(['id' => 55]);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/profile/activity', [
            'category_ids'       => [55, 55],
            'national_id_number' => '12',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['category_ids.0', 'national_id_number', 'national_id', 'freelance_certificate']]])
            ->assertJsonPath('data.errors.national_id.0', 'صورة الهوية الوطنية / الإقامة مطلوب.');
    }

    public function test_activity_step_is_not_available_for_stores(): void
    {
        $user = $this->actingAsRole('store');
        $this->draftProfileFor($user);

        $this->postJson('/api/v1/profile/activity', [])->assertStatus(403);
    }

    /* ------------------------------------------------------------ submit */

    public function test_submit_requires_a_complete_profile(): void
    {
        $user = $this->actingAsRole('captain');
        $this->draftProfileFor($user);

        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/profile/submit')
            ->assertStatus(422)
            ->assertJsonPath('message', 'ملفك غير مكتمل. يرجى استكمال البيانات الناقصة قبل الإرسال.')
            ->assertJsonPath('data.missing.activity', ['vehicle_type_id', 'vehicle_model', 'plate_number', 'driving_license', 'plate_image'])
            ->assertJsonMissingPath('data.missing.basic_info');

        $this->assertSame('draft', CaptainProfile::where('user_id', $user->id)->value('status'));
    }

    public function test_submit_moves_completed_profile_to_pending_and_locks_it(): void
    {
        $user = $this->actingAsRole('store');
        $profile = StoreProfile::factory()->rejected()->create(['user_id' => $user->id, 'rejection_reason' => 'blurry']);
        StoreBranch::factory()->main()->create(['store_profile_id' => $profile->id]);

        $this->postJson('/api/v1/profile/submit')
            ->assertOk()
            ->assertJsonPath('message', __('profile.submitted_for_review'))
            ->assertJsonPath('data.profile.status', 'pending')
            ->assertJsonPath('data.flags.is_pending_approval', true)
            ->assertJsonPath('data.flags.can_submit', false)
            ->assertJsonPath('data.next_step', 'pending_approval');

        $profile = StoreProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile->submitted_at);
        $this->assertNull($profile->rejection_reason);

        $user->unsetRelation('storeProfile');
        $this->postJson('/api/v1/profile/submit')->assertStatus(409);
        $this->postJson('/api/v1/profile/store-details', [])->assertStatus(409);
    }

    public function test_profile_show_returns_current_state(): void
    {
        $user = $this->actingAsRole('shopper');
        $this->draftProfileFor($user);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.account_type', 'shopper')
            ->assertJsonPath('data.flags.basic_info_completed', true)
            ->assertJsonPath('data.flags.activity_completed', false)
            ->assertJsonPath('data.flags.missing.activity', ['category_ids', 'national_id', 'freelance_certificate'])
            ->assertJsonPath('data.next_step', 'complete_profile');
    }
}
