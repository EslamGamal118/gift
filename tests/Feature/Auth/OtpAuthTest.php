<?php

namespace Tests\Feature\Auth;

use App\Models\CaptainProfile;
use App\Models\Category;
use App\Models\FcmToken;
use App\Models\PhoneVerification;
use App\Models\ShopperProfile;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\ForJawalyProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    protected const MOBILE = '966512345678';

    /** Keys that only belong to verify-otp and must not be forwarded to send-otp. */
    protected const VERIFY_ONLY_KEYS = ['otp', 'fcm_token', 'device_type', 'device_id', 'device_name'];

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml sets APP_ENV=testing which is a "testing environment" for OTP
        config(['otp.testing_code' => '1234']);

        RateLimiter::clear('otp:send:'.self::MOBILE);
        RateLimiter::clear('otp:verify:'.self::MOBILE);
    }

    protected function tearDown(): void
    {
        // model listeners registered by individual tests must not leak into the next test
        User::flushEventListeners();

        parent::tearDown();
    }

    /**
     * Request a fresh OTP (bypassing the resend cooldown so tests can chain calls).
     * Registration carries a name by default, as the API now requires.
     */
    protected function sendOtp(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'mobile'    => self::MOBILE,
            'action'    => 'register',
            'user_type' => 'client',
        ], $overrides);

        if (($payload['action'] ?? null) === 'register' && ! array_key_exists('name', $payload)) {
            $payload['name'] = 'Ahmed';
        }

        RateLimiter::clear('otp:send:'.\App\Support\PhoneNumber::normalize($payload['mobile'] ?? null));

        return $this->postJson('/api/v1/auth/send-otp', $payload);
    }

    /**
     * Verify with a freshly requested OTP (every OTP is single-use, so each verify needs its own).
     * The send-otp call mirrors the verify payload, which is how a real client behaves.
     */
    protected function verify(array $overrides = [], bool $requestFreshOtp = true): \Illuminate\Testing\TestResponse
    {
        if ($requestFreshOtp) {
            $this->sendOtp(Arr::except($overrides, self::VERIFY_ONLY_KEYS))->assertOk();
        }

        return $this->postJson('/api/v1/auth/verify-otp', array_merge([
            'mobile'    => self::MOBILE,
            'otp'       => '1234',
            'action'    => 'register',
            'user_type' => 'client',
        ], $overrides));
    }

    /**
     * A pending verification row as if send-otp had just run (used where the cooldown
     * or an explicit code matters).
     */
    protected function pendingVerification(array $attributes = []): PhoneVerification
    {
        return PhoneVerification::factory()->forPhone(self::MOBILE)->create(array_merge([
            'otp_code' => '1234',
        ], $attributes));
    }

    /* ---------------------------------------------------------------- send-otp */

    public function test_send_otp_requires_action_and_user_type(): void
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile' => self::MOBILE])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['action', 'user_type']]]);

        $this->sendOtp(['action' => 'signup'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['action']]]);

        $this->sendOtp(['user_type' => 'wizard'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['user_type']]]);

        // admin is not registrable through the app
        $this->sendOtp(['user_type' => 'admin'])->assertStatus(422);

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_send_otp_requires_a_name_for_registration_only(): void
    {
        $this->sendOtp(['name' => null])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['name']]]);

        User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'customer']);

        $this->sendOtp(['action' => 'login'])->assertOk();
    }

    public function test_send_otp_stores_the_registration_name_and_uses_the_fixed_code_in_testing_env(): void
    {
        Http::fake();

        $this->sendOtp(['mobile' => '0512345678', 'name' => 'Ahmed'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.mobile', self::MOBILE)
            ->assertJsonPath('data.country_code', '966')
            ->assertJsonPath('data.action', 'register')
            ->assertJsonPath('data.user_type', 'customer')
            ->assertJsonPath('data.is_registered', false)
            ->assertJsonPath('data.registered_roles', [])
            ->assertJsonPath('data.otp', '1234');

        $this->assertDatabaseHas('phone_verifications', [
            'country_code' => '966',
            'phone'        => self::MOBILE,
            'name'         => 'Ahmed',
            'otp_code'     => '1234',
            'verified_at'  => null,
        ]);

        // the account itself is only created once the number is verified
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_send_otp_rejects_registration_when_the_account_already_exists(): void
    {
        User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'customer']);

        $this->sendOtp(['action' => 'register', 'user_type' => 'client'])
            ->assertStatus(409)
            ->assertJsonPath('status', 409)
            ->assertJsonPath('message', __('auth.account_exists'))
            ->assertJsonPath('data.account_type', 'customer')
            ->assertJsonPath('data.action', 'login');

        // another role on the same number is still free to register
        $this->sendOtp(['action' => 'register', 'user_type' => 'store'])->assertOk();

        $this->assertDatabaseCount('phone_verifications', 1);
    }

    public function test_send_otp_rejects_login_when_the_account_does_not_exist(): void
    {
        User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'store']);

        $this->sendOtp(['action' => 'login', 'user_type' => 'client'])
            ->assertStatus(404)
            ->assertJsonPath('status', 404)
            ->assertJsonPath('message', __('auth.account_not_found'))
            ->assertJsonPath('data.account_type', 'customer')
            ->assertJsonPath('data.action', 'register');

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_send_otp_lists_all_roles_registered_for_the_mobile(): void
    {
        User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'store']);
        User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'captain']);

        $this->sendOtp(['action' => 'login', 'user_type' => 'store'])
            ->assertOk()
            ->assertJsonPath('data.is_registered', true)
            ->assertJsonPath('data.registered_roles', ['captain', 'store']);

        $this->assertDatabaseHas('phone_verifications', ['phone' => self::MOBILE, 'name' => null]);
    }

    public function test_send_otp_rejects_invalid_mobile(): void
    {
        $this->sendOtp(['mobile' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonStructure(['data' => ['errors' => ['mobile']]]);
    }

    public function test_send_otp_enforces_resend_cooldown(): void
    {
        $this->postJson('/api/v1/auth/send-otp', [
            'mobile' => self::MOBILE, 'action' => 'register', 'user_type' => 'client', 'name' => 'Ahmed',
        ])->assertOk();

        $this->postJson('/api/v1/auth/send-otp', [
            'mobile' => self::MOBILE, 'action' => 'register', 'user_type' => 'client', 'name' => 'Ahmed',
        ])
            ->assertStatus(429)
            ->assertJsonStructure(['data' => ['retry_after']]);
    }

    public function test_send_otp_in_production_generates_random_code_and_calls_4jawaly(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'services.forjawaly.key'    => 'key',
            'services.forjawaly.secret' => 'secret',
            'services.forjawaly.sender' => 'Gift',
            'otp.length'                => 6,
        ]);

        Http::fake(['api-sms.4jawaly.com/*' => Http::response(['code' => 200, 'job_id' => 'abc'], 200)]);

        $this->sendOtp()
            ->assertOk()
            ->assertJsonMissingPath('data.otp');

        $verification = PhoneVerification::where('phone', self::MOBILE)->first();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $verification->otp_code);
        $this->assertNotSame('1234', $verification->otp_code);

        Http::assertSent(fn ($request) => str_contains($request->url(), '4jawaly')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('key:secret'))
            && $request['messages'][0]['numbers'] === [self::MOBILE]
            && $request['messages'][0]['sender'] === 'Gift'
            && str_contains($request['messages'][0]['text'], $verification->otp_code));
    }

    public function test_send_otp_in_production_returns_502_when_sms_fails(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.forjawaly.key' => 'key', 'services.forjawaly.secret' => 'secret', 'services.forjawaly.sender' => 'Gift']);

        Http::fake(['api-sms.4jawaly.com/*' => Http::response(['code' => 401], 401)]);

        $this->sendOtp()
            ->assertStatus(502)
            ->assertJsonPath('status', 502);

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_provider_returns_false_when_not_configured(): void
    {
        config(['services.forjawaly.key' => null, 'services.forjawaly.secret' => null]);
        Http::fake();

        $this->assertFalse((new ForJawalyProvider())->send(self::MOBILE, 'hello'));
        Http::assertNothingSent();
    }

    /* -------------------------------------------------------------- verify-otp */

    public function test_verify_otp_requires_action_and_user_type(): void
    {
        $this->postJson('/api/v1/auth/verify-otp', ['mobile' => self::MOBILE, 'otp' => '1234'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['action', 'user_type']]]);

        $this->verify(['user_type' => 'admin'], requestFreshOtp: false)->assertStatus(422);
        $this->verify(['user_type' => 'wizard'], requestFreshOtp: false)->assertStatus(422);
        $this->verify(['action' => 'signup'], requestFreshOtp: false)->assertStatus(422);
    }

    public function test_verify_otp_registers_the_account_with_the_name_captured_at_send_time(): void
    {
        // the name travels with send-otp; verify-otp does not need to repeat it
        $this->sendOtp(['mobile' => '+966 51 234 5678', 'name' => 'Ahmed'])->assertOk();

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'mobile'    => '+966 51 234 5678',
            'otp'       => '1234',
            'action'    => 'register',
            'user_type' => 'client',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.account_type', 'customer')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.phone', self::MOBILE)
            ->assertJsonPath('data.user.name', 'Ahmed')
            ->assertJsonPath('data.user.phone_verified', true)
            ->assertJsonPath('data.profile', null)
            ->assertJsonPath('data.flags.requires_profile', false)
            ->assertJsonPath('data.flags.fcm_token_bound', false)
            ->assertJsonPath('data.flags.can_operate', true)
            ->assertJsonPath('data.next_step', 'home');

        // the phone was verified before the account came into existence
        $verification = PhoneVerification::where('phone', self::MOBILE)->first();
        $this->assertNotNull($verification->verified_at);
        $this->assertTrue(User::forPhoneAndRole(self::MOBILE, 'customer')->first()->hasVerifiedPhone());

        $token = $response->json('data.token');
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withToken($token)->getJson('/api/v1/user')->assertOk()->assertJsonPath('phone', self::MOBILE);
    }

    public function test_verify_otp_rejects_registration_when_the_account_already_exists(): void
    {
        $this->pendingVerification();
        User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'customer', 'name' => 'Existing']);

        $this->verify(requestFreshOtp: false)
            ->assertStatus(409)
            ->assertJsonPath('message', __('auth.account_exists'))
            ->assertJsonPath('data.action', 'login');

        // the code is untouched: a rejected action must not spend it
        $this->assertDatabaseHas('phone_verifications', ['phone' => self::MOBILE, 'verified_at' => null]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verify_otp_rejects_login_when_the_account_does_not_exist(): void
    {
        $this->pendingVerification();

        $this->verify(['action' => 'login'], requestFreshOtp: false)
            ->assertStatus(404)
            ->assertJsonPath('message', __('auth.account_not_found'))
            ->assertJsonPath('data.action', 'register');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('phone_verifications', ['phone' => self::MOBILE, 'verified_at' => null]);
    }

    public function test_same_mobile_can_register_a_separate_account_per_role(): void
    {
        $this->verify(['user_type' => 'client'])->assertOk()->assertJsonPath('data.is_new_user', true);
        $this->verify(['user_type' => null, 'role' => 'store'])->assertOk()->assertJsonPath('data.is_new_user', true)->assertJsonPath('data.account_type', 'store');
        $this->verify(['user_type' => 'captain'])->assertOk()->assertJsonPath('data.is_new_user', true);
        $this->verify(['account_type' => 'personal_shopper', 'user_type' => null])->assertOk()->assertJsonPath('data.is_new_user', true)->assertJsonPath('data.account_type', 'shopper');

        $this->assertDatabaseCount('users', 4);
        $this->assertSame(
            ['captain', 'customer', 'shopper', 'store'],
            User::where('phone', self::MOBILE)->pluck('user_type')->sort()->values()->all()
        );

        // the same role now has to log in instead of registering
        $this->verify(['action' => 'login', 'user_type' => 'store'])->assertOk()->assertJsonPath('data.is_new_user', false);
        $this->assertDatabaseCount('users', 4);
    }

    public function test_verify_otp_logs_into_the_account_matching_the_selected_role(): void
    {
        $client = User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'customer', 'name' => 'Client Me']);
        $store  = User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'store', 'name' => 'Store Me']);

        $this->verify(['action' => 'login', 'user_type' => 'store'])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', false)
            ->assertJsonPath('data.user.id', $store->id)
            ->assertJsonPath('data.user.name', 'Store Me');

        $this->verify(['action' => 'login', 'user_type' => 'client'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $client->id);

        $this->assertSame(1, $store->tokens()->count());
        $this->assertSame(1, $client->tokens()->count());
    }

    public function test_verify_otp_binds_fcm_token_to_the_authenticated_account(): void
    {
        $this->verify([
            'user_type'   => 'client',
            'fcm_token'   => 'fcm-abc',
            'device_type' => 'android',
            'device_id'   => 'device-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.flags.fcm_token_bound', true);

        $client = User::where('phone', self::MOBILE)->where('user_type', 'customer')->first();
        $this->assertDatabaseHas('fcm_tokens', ['user_id' => $client->id, 'token' => 'fcm-abc', 'device_type' => 'android', 'device_id' => 'device-1']);

        // same device registers the store account: token is re-bound, not duplicated
        $this->verify(['user_type' => 'store', 'fcm_token' => 'fcm-abc', 'device_type' => 'android', 'device_id' => 'device-1'])->assertOk();

        $store = User::where('phone', self::MOBILE)->where('user_type', 'store')->first();
        $this->assertDatabaseCount('fcm_tokens', 1);
        $this->assertDatabaseHas('fcm_tokens', ['user_id' => $store->id, 'token' => 'fcm-abc']);

        // FCM refreshed the token on the same device: old one is replaced
        $this->verify(['action' => 'login', 'user_type' => 'store', 'fcm_token' => 'fcm-new', 'device_id' => 'device-1'])->assertOk();
        $this->assertDatabaseCount('fcm_tokens', 1);
        $this->assertSame('fcm-new', FcmToken::first()->token);
    }

    public function test_verify_otp_rejects_wrong_code_and_locks_after_max_attempts(): void
    {
        config(['otp.max_attempts' => 2]);
        $this->sendOtp()->assertOk();

        $this->verify(['otp' => '0000'], requestFreshOtp: false)->assertStatus(422);
        $this->verify(['otp' => '0000'], requestFreshOtp: false)->assertStatus(422);

        $this->verify(requestFreshOtp: false)->assertStatus(429)->assertJsonStructure(['data' => ['retry_after']]);

        // no account is created while the number is unverified
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verify_otp_in_production_validates_against_database_code(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->pendingVerification(['otp_code' => '5566', 'name' => 'Ahmed']);

        $this->verify(['otp' => '1234'], requestFreshOtp: false)->assertStatus(422);   // fixed testing code must NOT work
        $this->verify(['otp' => '5566'], requestFreshOtp: false)
            ->assertOk()
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.user.name', 'Ahmed');

        $this->assertNotNull(PhoneVerification::where('otp_code', '5566')->first()->verified_at);

        // no replay: the account exists now, so the same code cannot register again
        $this->verify(['otp' => '5566'], requestFreshOtp: false)
            ->assertStatus(409)
            ->assertJsonPath('message', __('auth.account_exists'));

        // and it cannot be replayed as a login either
        $this->verify(['otp' => '5566', 'action' => 'login'], requestFreshOtp: false)
            ->assertStatus(422)
            ->assertJsonPath('message', __('auth.otp_already_used'));
    }

    public function test_verify_otp_in_production_rejects_expired_code(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->pendingVerification(['otp_code' => '5566', 'expires_at' => now()->subMinute()]);

        $this->verify(['otp' => '5566'], requestFreshOtp: false)
            ->assertStatus(422)
            ->assertJsonPath('message', __('auth.otp_expired'));

        $this->assertDatabaseCount('users', 0);
    }

    public function test_verify_otp_logs_in_existing_store_with_profile_and_branches(): void
    {
        $user    = User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'store']);
        $profile = StoreProfile::factory()->approved()->create(['user_id' => $user->id]);
        StoreBranch::factory()->main()->create(['store_profile_id' => $profile->id]);
        StoreBranch::factory()->create(['store_profile_id' => $profile->id]);

        $this->verify(['action' => 'login', 'user_type' => 'store'])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', false)
            ->assertJsonPath('data.account_type', 'store')
            ->assertJsonPath('data.profile.id', $profile->id)
            ->assertJsonPath('data.profile.location.branches_count', 2)
            ->assertJsonPath('data.profile.location.main_branch.is_main', true)
            ->assertJsonCount(2, 'data.profile.location.branches')
            ->assertJsonPath('data.flags.can_operate', true)
            ->assertJsonPath('data.next_step', 'home');
    }

    public function test_verify_otp_logs_in_captain_with_translated_vehicle_type(): void
    {
        $user    = User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'captain']);
        $vehicle = VehicleType::factory()->create(['name' => ['en' => 'Motorcycle', 'ar' => 'دراجة نارية']]);
        CaptainProfile::factory()->pending()->create(['user_id' => $user->id, 'vehicle_type_id' => $vehicle->id]);

        $this->withHeader('Accept-Language', 'ar')
            ->verify(['action' => 'login', 'user_type' => 'delivery_captain'])
            ->assertOk()
            ->assertJsonPath('message', 'تم تسجيل الدخول بنجاح.')
            ->assertJsonPath('data.account_type', 'captain')
            ->assertJsonPath('data.profile.details.vehicle_type.name', 'دراجة نارية')
            ->assertJsonPath('data.flags.is_pending_approval', true)
            ->assertJsonPath('data.next_step', 'pending_approval');
    }

    public function test_verify_otp_logs_in_shopper_with_categories(): void
    {
        $user    = User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'shopper']);
        $profile = ShopperProfile::factory()->rejected()->create(['user_id' => $user->id, 'rejection_reason' => 'Blurry ID']);
        $profile->categories()->attach(Category::factory()->count(2)->create());

        $this->verify(['action' => 'login', 'user_type' => 'personal_shopper'])
            ->assertOk()
            ->assertJsonPath('data.account_type', 'shopper')
            ->assertJsonCount(2, 'data.profile.details.categories')
            ->assertJsonPath('data.profile.rejection_reason', 'Blurry ID')
            ->assertJsonPath('data.flags.is_rejected', true)
            ->assertJsonPath('data.next_step', 'profile_rejected');
    }

    public function test_verify_otp_new_store_account_needs_profile(): void
    {
        $this->verify(['user_type' => 'store'])
            ->assertOk()
            ->assertJsonPath('data.profile', null)
            ->assertJsonPath('data.flags.requires_profile', true)
            ->assertJsonPath('data.flags.has_profile', false)
            ->assertJsonPath('data.flags.can_operate', false)
            ->assertJsonPath('data.next_step', 'complete_profile');
    }

    public function test_blocked_role_does_not_block_other_roles_on_same_mobile(): void
    {
        User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'store', 'status' => 'blocked']);
        User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'customer']);

        $this->verify(['action' => 'login', 'user_type' => 'store'])
            ->assertStatus(403)
            ->assertJsonPath('data.account_type', 'store');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->verify(['action' => 'login', 'user_type' => 'client'])->assertOk();
    }

    /* ---------------------------------------------- single-use OTP & idempotency */

    public function test_otp_cannot_be_verified_without_requesting_one_first(): void
    {
        $this->verify(requestFreshOtp: false)
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonPath('message', __('auth.invalid_otp'));

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_same_otp_cannot_be_replayed_after_a_successful_verification(): void
    {
        $this->verify()->assertOk()->assertJsonPath('data.is_new_user', true);
        $this->assertDatabaseHas('phone_verifications', ['phone' => self::MOBILE, 'otp_code' => '1234']);
        $this->assertNotNull(PhoneVerification::first()->verified_at);

        // replay as a login for the account just created
        $this->verify(['action' => 'login'], requestFreshOtp: false)
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonPath('message', __('auth.otp_already_used'));

        // replay for another role reuses the same consumed verification
        $this->verify(['user_type' => 'store'], requestFreshOtp: false)
            ->assertStatus(422)
            ->assertJsonPath('message', __('auth.otp_already_used'));

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_requesting_a_new_otp_invalidates_the_previous_pending_one(): void
    {
        $this->sendOtp()->assertOk();
        $first = PhoneVerification::latest('id')->first();

        $this->sendOtp()->assertOk();

        $this->assertDatabaseCount('phone_verifications', 2);
        $this->assertFalse(PhoneVerification::whereKey($first->id)->pending()->exists());
        $this->assertSame(1, PhoneVerification::forPhone(self::MOBILE)->pending()->count());

        // the latest code still works exactly once
        $this->verify(requestFreshOtp: false)->assertOk();
        $this->verify(['action' => 'login'], requestFreshOtp: false)->assertStatus(422);
    }

    public function test_logging_in_again_reuses_the_existing_account_and_updates_it(): void
    {
        $first = $this->verify(['name' => 'Ahmed', 'fcm_token' => 'fcm-1'])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.user.name', 'Ahmed');

        $second = $this->verify(['action' => 'login', 'name' => 'Ahmed Ali', 'fcm_token' => 'fcm-2'])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', false)
            ->assertJsonPath('data.user.id', $first->json('data.user.id'))
            ->assertJsonPath('data.user.name', 'Ahmed Ali');

        // still exactly one account for (mobile, role), a fresh token each login, FCM re-bound
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertNotSame($first->json('data.token'), $second->json('data.token'));

        $user = User::forPhoneAndRole(self::MOBILE, 'customer')->first();
        $this->assertEqualsCanonicalizing(['fcm-1', 'fcm-2'], $user->fcmTokens()->pluck('token')->all());
    }

    public function test_login_without_name_keeps_existing_name(): void
    {
        User::factory()->create(['phone' => self::MOBILE, 'user_type' => 'customer', 'name' => 'Keep Me']);

        $this->verify(['action' => 'login'])->assertOk()->assertJsonPath('data.user.name', 'Keep Me');
    }

    public function test_concurrent_registration_for_same_mobile_and_role_does_not_create_duplicates(): void
    {
        // Simulate a rival request winning the race: it inserts the same (mobile, role) account
        // after our SELECT ... FOR UPDATE found nothing but before our INSERT hits the unique index.
        $rival = null;

        User::creating(function (User $user) use (&$rival) {
            if ($rival === null) {
                $rival = User::withoutEvents(fn () => User::factory()->create([
                    'phone'     => $user->phone,
                    'user_type' => $user->user_type,
                    'name'      => 'Rival',
                ]));
            }
        });

        $this->verify(['name' => 'Me', 'fcm_token' => 'fcm-race'])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', false)
            ->assertJsonPath('data.user.id', $rival->id)
            ->assertJsonPath('data.user.name', 'Me');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('fcm_tokens', ['user_id' => $rival->id, 'token' => 'fcm-race']);
    }
}
