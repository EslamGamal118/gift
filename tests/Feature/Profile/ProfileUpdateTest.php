<?php

namespace Tests\Feature\Profile;

use App\Models\CaptainProfile;
use App\Models\Category;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected const UPDATE = '/api/v1/profile/update';
    protected const STATUS = '/api/v1/profile/status';
    protected const LOGOUT = '/api/v1/auth/logout';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['user_type' => $role] + $attributes);

        Sanctum::actingAs($user);

        return $user;
    }

    /* -------------------------------------------------------------- update */

    public function test_client_can_update_name_email_and_avatar(): void
    {
        $user = $this->actingAsRole('customer', ['name' => 'Old', 'email' => 'old@example.com']);

        $this->withHeader('Accept-Language', 'ar')->postJson(self::UPDATE, [
            'name'   => '  Sara  ',
            'email'  => 'Sara@Example.com',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث الملف الشخصي بنجاح.')
            ->assertJsonPath('data.account_type', 'customer')
            ->assertJsonPath('data.user.name', 'Sara')
            ->assertJsonPath('data.user.email', 'sara@example.com')
            ->assertJsonPath('data.profile', null)
            ->assertJsonPath('data.next_step', 'home');

        Storage::disk('public')->assertExists($user->fresh()->avatar);

        // fields not sent are left untouched, unknown role fields are ignored
        $this->putJson(self::UPDATE, ['iban' => 'SA00', 'store_name' => 'x'])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Sara');
    }

    public function test_store_partial_update_with_files_and_working_hours(): void
    {
        $user    = $this->actingAsRole('store');
        $profile = StoreProfile::factory()->draft()->create(['user_id' => $user->id, 'description' => 'old']);
        $oldLogo = $profile->logo;
        $newCat  = Category::factory()->create(['name' => ['en' => 'Toys', 'ar' => 'ألعاب']]);

        $hours = [];
        foreach (StoreProfile::DAYS as $day) {
            $hours[$day] = ['is_open' => $day !== 'friday', 'from' => '10:00', 'to' => '22:00'];
        }

        $this->putJson(self::UPDATE, [
            'store_name'    => 'New Name',
            'category_id'   => $newCat->id,
            'logo'          => UploadedFile::fake()->image('logo.png'),
            'working_hours' => json_encode($hours),
        ])
            ->assertOk()
            ->assertJsonPath('data.profile.details.store_name', 'New Name')
            ->assertJsonPath('data.profile.details.category.id', $newCat->id)
            ->assertJsonPath('data.profile.details.description', 'old')
            ->assertJsonPath('data.profile.working_hours.days.6.is_open', false)
            ->assertJsonPath('data.profile.working_hours.days.2.from', '10:00')
            ->assertJsonPath('data.profile.status', 'draft');

        $profile->refresh();
        $this->assertNotSame($oldLogo, $profile->logo);
        Storage::disk('public')->assertExists($profile->logo);

        // invalid partial working hours are rejected
        $this->withHeader('Accept-Language', 'ar')
            ->putJson(self::UPDATE, ['working_hours' => ['saturday' => ['is_open' => true, 'from' => '09:00', 'to' => '09:00']]])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.working_hours.0', __('profile.working_hours_all_days'));
    }

    public function test_captain_document_change_on_approved_profile_triggers_re_review(): void
    {
        $user    = $this->actingAsRole('captain');
        $profile = CaptainProfile::factory()->approved()->create(['user_id' => $user->id, 'vehicle_model' => 'Old Model']);

        // cosmetic change: stays approved
        $this->postJson(self::UPDATE, ['vehicle_model' => 'Camry 2024'])
            ->assertOk()
            ->assertJsonPath('message', __('profile.updated'))
            ->assertJsonPath('data.profile.details.vehicle_model', 'Camry 2024')
            ->assertJsonPath('data.profile.status', 'approved')
            ->assertJsonPath('data.flags.can_operate', true);

        // compliance change: back to review
        $this->withHeader('Accept-Language', 'ar')->postJson(self::UPDATE, [
            'plate_number'    => 'xyz 9876',
            'driving_license' => UploadedFile::fake()->image('license.jpg'),
        ])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث الملف الشخصي، وتم إرسال المستندات المعدّلة للمراجعة.')
            ->assertJsonPath('data.profile.details.plate_number', 'XYZ 9876')
            ->assertJsonPath('data.profile.status', 'pending')
            ->assertJsonPath('data.flags.can_operate', false)
            ->assertJsonPath('data.next_step', 'pending_approval');

        // now locked until reviewed
        $this->postJson(self::UPDATE, ['vehicle_model' => 'Another'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('profile.locked_pending'));

        $this->assertSame('pending', $profile->fresh()->status);
    }

    public function test_shopper_can_update_categories_location_and_documents(): void
    {
        $user    = $this->actingAsRole('shopper');
        $profile = ShopperProfile::factory()->rejected()->create(['user_id' => $user->id, 'rejection_reason' => 'blurry']);
        $cats    = Category::factory()->count(2)->create();

        $this->postJson(self::UPDATE, [
            'category_ids' => $cats->pluck('id')->implode(','),
            'address'      => 'Jeddah',
            'latitude'     => 21.4858,
            'longitude'    => 39.1925,
            'national_id'  => UploadedFile::fake()->image('id.png'),
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.profile.details.categories')
            ->assertJsonPath('data.profile.location.address', 'Jeddah')
            ->assertJsonPath('data.profile.status', 'draft')
            ->assertJsonPath('data.flags.is_rejected', false);

        $this->assertNull($profile->fresh()->rejection_reason);

        // coordinates must come together with the address
        $this->postJson(self::UPDATE, ['latitude' => 1])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['address', 'longitude']]]);
    }

    public function test_update_validation_and_guards(): void
    {
        $this->actingAsRole('captain');   // no profile yet

        $this->postJson(self::UPDATE, ['name' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('profile.basic_info_required'));

        $user = $this->actingAsRole('store');
        StoreProfile::factory()->draft()->create(['user_id' => $user->id]);
        User::factory()->create(['user_type' => 'store', 'email' => 'taken@example.com']);

        $this->withHeader('Accept-Language', 'ar')->postJson(self::UPDATE, [
            'name'  => 'A',
            'email' => 'taken@example.com',
            'store_phone' => '12',
            'logo'  => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.name.0', 'يجب ألّا يقل عدد أحرف الاسم عن 2 حرفًا.')
            ->assertJsonPath('data.errors.email.0', 'البريد الإلكتروني مستخدم من قبل.')
            ->assertJsonPath('data.errors.store_phone.0', 'يرجى إدخال رقم جوال صحيح.')
            ->assertJsonPath('data.errors.logo.0', 'يجب أن يكون الشعار صورة.');
    }

    public function test_update_status_and_logout_require_authentication(): void
    {
        $this->postJson(self::UPDATE)->assertStatus(401)->assertJsonPath('status', 401);
        $this->getJson(self::STATUS)->assertStatus(401);
        $this->postJson(self::LOGOUT)->assertStatus(401);
    }

    /* -------------------------------------------------------------- status */

    public function test_status_returns_state_for_every_role(): void
    {
        $this->actingAsRole('customer');
        $this->getJson(self::STATUS)
            ->assertOk()
            ->assertJsonPath('data.account_type', 'customer')
            ->assertJsonPath('data.flags.requires_profile', false)
            ->assertJsonPath('data.flags.can_operate', true)
            ->assertJsonPath('data.next_step', 'home');

        $captain = $this->actingAsRole('captain');
        $this->getJson(self::STATUS)
            ->assertOk()
            ->assertJsonPath('data.flags.has_profile', false)
            ->assertJsonPath('data.flags.missing.basic_info', ['profile'])
            ->assertJsonPath('data.next_step', 'complete_profile');

        $vehicle = VehicleType::factory()->create(['name' => ['en' => 'Van', 'ar' => 'فان']]);
        CaptainProfile::factory()->pending()->create(['user_id' => $captain->id, 'vehicle_type_id' => $vehicle->id]);
        $captain->unsetRelation('captainProfile');

        $this->withHeader('Accept-Language', 'ar')->getJson(self::STATUS)
            ->assertOk()
            ->assertJsonPath('data.profile.details.vehicle_type.name', 'فان')
            ->assertJsonPath('data.flags.is_pending_approval', true)
            ->assertJsonPath('data.flags.basic_info_completed', true)
            ->assertJsonPath('data.flags.activity_completed', true)
            ->assertJsonPath('data.flags.location_completed', true)
            ->assertJsonPath('data.next_step', 'pending_approval');

        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.account_type', 'captain');
        $this->flushHeaders();
        Sanctum::actingAs(User::factory()->create(['user_type' => 'store', 'status' => 'blocked']));
        $this->getJson(self::STATUS)->assertOk()->assertJsonPath('data.next_step', 'blocked');
    }

    /* -------------------------------------------------------------- logout */

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create(['user_type' => 'customer']);
        $user->registerFcmToken('fcm-phone', 'android', 'dev-1');
        $user->registerFcmToken('fcm-tablet', 'android', 'dev-2');

        $phone  = $user->createToken('phone')->plainTextToken;
        $tablet = $user->createToken('tablet')->plainTextToken;

        $this->withToken($phone)->withHeader('Accept-Language', 'ar')
            ->postJson(self::LOGOUT, ['fcm_token' => 'fcm-phone'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('message', 'تم تسجيل الخروج بنجاح.');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('fcm_tokens', ['token' => 'fcm-phone']);
        $this->assertDatabaseHas('fcm_tokens', ['token' => 'fcm-tablet']);

        // the revoked token no longer authenticates; the other device still does
        // (the guard caches the resolved user per app instance, so reset it between requests)
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson(self::STATUS)->assertStatus(401)->assertJsonPath('status', 401);

        $this->app['auth']->forgetGuards();
        $this->withToken($tablet)->getJson(self::STATUS)->assertOk();
    }
}
