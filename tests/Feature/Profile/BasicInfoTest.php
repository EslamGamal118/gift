<?php

namespace Tests\Feature\Profile;

use App\Models\CaptainProfile;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BasicInfoTest extends TestCase
{
    use RefreshDatabase;

    protected const ENDPOINT = '/api/v1/profile/basic-info';
    protected const IBAN     = 'SA0380000000608010167519';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['user_type' => $role, 'name' => null, 'email' => null] + $attributes);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'account_type'     => $user->user_type,
            'name'             => 'Mohammed Ali',
            'phone'            => $user->phone,
            'email'            => 'mohammed@example.com',
            'iban'             => self::IBAN,
            'iban_certificate' => UploadedFile::fake()->create('iban.pdf', 200, 'application/pdf'),
            'avatar'           => UploadedFile::fake()->image('me.jpg', 400, 400),
        ], $overrides);
    }

    /* ------------------------------------------------------------- captain */

    public function test_captain_can_create_basic_info_with_avatar_and_iban_certificate(): void
    {
        $user = $this->actingAsRole('captain');

        $response = $this->postJson(self::ENDPOINT, $this->payload($user, ['account_type' => 'delivery_captain']));

        $response->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('message', __('profile.basic_info_saved'))
            ->assertJsonPath('data.account_type', 'captain')
            ->assertJsonPath('data.user.name', 'Mohammed Ali')
            ->assertJsonPath('data.user.email', 'mohammed@example.com')
            ->assertJsonPath('data.profile.basic_info.iban', self::IBAN)
            ->assertJsonPath('data.profile.status', 'draft')
            ->assertJsonPath('data.flags.has_profile', true)
            ->assertJsonPath('data.flags.basic_info_completed', true)
            ->assertJsonPath('data.flags.is_profile_completed', false)
            ->assertJsonPath('data.next_step', 'complete_profile');

        $profile = CaptainProfile::where('user_id', $user->id)->firstOrFail();

        $this->assertNotNull($profile->iban_certificate_file);
        $this->assertNotNull($profile->personal_photo);
        $this->assertSame($profile->personal_photo, $user->fresh()->avatar);
        $this->assertStringStartsWith("profiles/captain/{$user->id}/", $profile->personal_photo);
        Storage::disk('public')->assertExists($profile->personal_photo);
        Storage::disk('public')->assertExists($profile->iban_certificate_file);

        $this->assertStringContainsString('/storage/'.$profile->personal_photo, $response->json('data.user.avatar'));
        $this->assertStringContainsString('/storage/'.$profile->iban_certificate_file, $response->json('data.profile.basic_info.iban_certificate.url'));
    }

    public function test_captain_avatar_is_required_on_first_submission(): void
    {
        $user = $this->actingAsRole('captain');

        $this->postJson(self::ENDPOINT, $this->payload($user, ['avatar' => null]))
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonStructure(['data' => ['errors' => ['avatar']]]);
    }

    public function test_profile_image_is_accepted_as_alias_for_avatar(): void
    {
        $user = $this->actingAsRole('shopper');

        $payload = $this->payload($user, ['account_type' => 'personal_shopper', 'avatar' => null]);
        $payload['profile_image'] = UploadedFile::fake()->image('p.png', 300, 300);

        $this->postJson(self::ENDPOINT, $payload)
            ->assertOk()
            ->assertJsonPath('data.account_type', 'shopper')
            ->assertJsonPath('data.flags.basic_info_completed', true);

        $this->assertNotNull(ShopperProfile::where('user_id', $user->id)->value('personal_photo'));
    }

    public function test_individual_phone_must_match_the_verified_account_phone(): void
    {
        $user = $this->actingAsRole('captain');

        $this->withHeader('Accept-Language', 'ar')
            ->postJson(self::ENDPOINT, $this->payload($user, ['phone' => '0599999999']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.phone.0', 'يجب أن يطابق رقم الجوال الرقم المسجّل في حسابك.');
    }

    /* --------------------------------------------------------------- store */

    public function test_store_needs_no_store_fields_and_avatar_is_optional(): void
    {
        $user = $this->actingAsRole('store');

        // Store name / phone / email belong to the store-details step and are not saved here
        $this->postJson(self::ENDPOINT, $this->payload($user, [
            'avatar' => null,
            'email'  => 'store@example.com',
        ]))
            ->assertOk()
            ->assertJsonPath('data.profile.details.store_name', null)
            ->assertJsonPath('data.profile.details.phone', null)
            ->assertJsonPath('data.profile.details.email', null)
            ->assertJsonPath('data.user.email', 'store@example.com')
            ->assertJsonPath('data.flags.basic_info_completed', true);

        $this->assertNull($user->fresh()->avatar);
    }

    /* --------------------------------------------------------- validation */

    public function test_common_fields_are_validated_with_localized_messages(): void
    {
        $user = $this->actingAsRole('captain');

        $response = $this->withHeader('Accept-Language', 'ar')->postJson(self::ENDPOINT, [
            'account_type'     => 'captain',
            'email'            => 'not-an-email',
            'iban_certificate' => UploadedFile::fake()->create('cert.exe', 10, 'application/octet-stream'),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'البيانات المدخلة غير صالحة.')
            ->assertJsonPath('data.errors.name.0', 'الاسم مطلوب.')
            ->assertJsonMissingPath('data.errors.phone')   // optional: the OTP-verified mobile
            ->assertJsonPath('data.errors.email.0', 'يجب أن يكون البريد الإلكتروني بريدًا إلكترونيًا صالحًا.')
            ->assertJsonPath('data.errors.iban.0', 'رقم الآيبان مطلوب.')
            ->assertJsonPath('data.errors.avatar.0', 'الصورة الشخصية مطلوب.');

        $this->assertStringContainsString('شهادة الآيبان', $response->json('data.errors.iban_certificate.0'));
        $this->assertDatabaseCount('captain_profiles', 0);
    }

    public function test_account_type_must_match_the_authenticated_account(): void
    {
        $user = $this->actingAsRole('captain');

        $this->postJson(self::ENDPOINT, $this->payload($user, ['account_type' => 'store', 'store_name' => 'X']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.account_type.0', __('profile.account_type_mismatch', ['registered_as' => 'captain']));
    }

    public function test_customers_cannot_submit_basic_info(): void
    {
        $user = $this->actingAsRole('customer');

        $this->postJson(self::ENDPOINT, $this->payload($user, ['account_type' => 'client']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.account_type.0', __('profile.not_applicable'));
    }

    public function test_requires_authentication(): void
    {
        $this->postJson(self::ENDPOINT, ['account_type' => 'captain'])
            ->assertStatus(401)
            ->assertJsonPath('status', 401);
    }

    public function test_email_must_be_unique_within_the_same_role_only(): void
    {
        User::factory()->create(['user_type' => 'captain', 'email' => 'taken@example.com']);
        User::factory()->create(['user_type' => 'store', 'email' => 'other-role@example.com']);

        $user = $this->actingAsRole('captain');

        $this->postJson(self::ENDPOINT, $this->payload($user, ['email' => 'taken@example.com']))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['email']]]);

        // same email used by a *store* account is fine for a captain
        $this->postJson(self::ENDPOINT, $this->payload($user, ['email' => 'other-role@example.com']))
            ->assertOk();
    }

    /* -------------------------------------------------------------- update */

    public function test_resubmitting_updates_existing_draft_and_replaces_old_files(): void
    {
        $user = $this->actingAsRole('shopper');

        $this->postJson(self::ENDPOINT, $this->payload($user))->assertOk();

        $profile  = ShopperProfile::where('user_id', $user->id)->firstOrFail();
        $oldPhoto = $profile->personal_photo;
        $oldCert  = $profile->iban_certificate_file;

        // files are optional on update because they're already stored
        $this->postJson(self::ENDPOINT, $this->payload($user, [
            'name'             => 'Updated Name',
            'iban'             => 'SA4420000001234567891234',
            'avatar'           => null,
            'iban_certificate' => UploadedFile::fake()->image('iban-v2.png', 600, 800),
        ]))
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Updated Name')
            ->assertJsonPath('data.profile.basic_info.iban', 'SA4420000001234567891234');

        $profile->refresh();

        $this->assertDatabaseCount('shopper_profiles', 1);
        $this->assertSame($oldPhoto, $profile->personal_photo);            // kept
        $this->assertNotSame($oldCert, $profile->iban_certificate_file);   // replaced
        Storage::disk('public')->assertExists($profile->iban_certificate_file);
        Storage::disk('public')->assertMissing($oldCert);                  // old file cleaned up after commit
    }

    public function test_rejected_profile_returns_to_draft_after_editing(): void
    {
        $user = $this->actingAsRole('captain', ['avatar' => 'profiles/captain/x/old.jpg']);
        CaptainProfile::factory()->rejected()->create([
            'user_id'          => $user->id,
            'personal_photo'   => 'profiles/captain/x/old.jpg',
            'rejection_reason' => 'IBAN certificate unreadable',
        ]);

        $this->postJson(self::ENDPOINT, $this->payload($user, ['avatar' => null]))
            ->assertOk()
            ->assertJsonPath('data.profile.status', 'draft')
            ->assertJsonPath('data.flags.is_rejected', false)
            ->assertJsonPath('data.next_step', 'complete_profile');

        $this->assertNull(CaptainProfile::where('user_id', $user->id)->value('rejection_reason'));
    }

    public function test_pending_and_approved_profiles_are_locked(): void
    {
        $user = $this->actingAsRole('store');
        StoreProfile::factory()->pending()->create(['user_id' => $user->id]);

        $this->withHeader('Accept-Language', 'ar')
            ->postJson(self::ENDPOINT, $this->payload($user, ['store_name' => 'Locked Store']))
            ->assertStatus(409)
            ->assertJsonPath('status', 409)
            ->assertJsonPath('message', 'ملفك قيد المراجعة ولا يمكن تعديله حاليًا.')
            ->assertJsonPath('data.profile_status', 'pending');

        StoreProfile::where('user_id', $user->id)->update(['status' => 'approved']);
        $user->unsetRelation('storeProfile'); // actingAs keeps the same instance; drop the cached relation

        $this->withHeader('Accept-Language', 'en')
            ->postJson(self::ENDPOINT, $this->payload($user, ['store_name' => 'Locked Store']))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Your profile is approved. Please contact support to change your basic information.')
            ->assertJsonPath('data.profile_status', 'approved');
    }
}
