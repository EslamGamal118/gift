<?php

namespace Tests\Feature\Profile;

use App\Models\Category;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Step 1 (basic-info) holds the owner's data; step 2 (store-details) holds the
 * store's own name and customer contact on `store_profiles`.
 */
class StoreProfileStepsSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected const IBAN = 'SA0380000000608010167519';

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->owner = User::factory()->storeOwner()->create(['name' => null, 'email' => null]);
        Sanctum::actingAs($this->owner);
    }

    protected function basicInfo(array $overrides = []): array
    {
        return array_merge([
            'account_type'     => 'store',
            'name'             => 'Mohammed Ali',
            'phone'            => $this->owner->phone,
            'email'            => 'owner@example.com',
            'iban'             => self::IBAN,
            'iban_certificate' => UploadedFile::fake()->create('iban.pdf', 200, 'application/pdf'),
        ], $overrides);
    }

    protected function storeDetails(array $overrides = []): array
    {
        $hours = [];
        foreach (StoreProfile::DAYS as $day) {
            $hours[$day] = ['is_open' => true, 'from' => '09:00', 'to' => '23:00'];
        }

        return array_merge([
            'store_name'          => 'Riyadh Sweets',
            'phone'               => '0501112233',
            'email'               => 'Care@RiyadhSweets.sa',
            'category_id'         => Category::factory()->create()->id,
            'logo'                => UploadedFile::fake()->image('logo.png', 300, 300),
            'commercial_register' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf'),
            'working_hours'       => json_encode($hours),
        ], $overrides);
    }

    public function test_basic_info_saves_owner_data_only_and_needs_no_store_fields(): void
    {
        $this->postJson('/api/v1/profile/basic-info', $this->basicInfo([
            'store_name' => 'Ignored Store',
        ]))->assertOk();

        $this->owner->refresh();
        $this->assertSame('Mohammed Ali', $this->owner->name);
        $this->assertSame('owner@example.com', $this->owner->email);

        $profile = StoreProfile::where('user_id', $this->owner->id)->firstOrFail();
        $this->assertSame(self::IBAN, $profile->iban);
        $this->assertNotNull($profile->iban_certificate_file);
        $this->assertNull($profile->store_name);
        $this->assertNull($profile->phone);
        $this->assertNull($profile->email);
    }

    public function test_basic_info_phone_is_the_verified_account_mobile(): void
    {
        $this->postJson('/api/v1/profile/basic-info', $this->basicInfo(['phone' => '0599999999']))
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['phone']]]);

        $this->postJson('/api/v1/profile/basic-info', $this->basicInfo(['phone' => null]))->assertOk();
    }

    public function test_store_details_saves_store_name_and_customer_contact_on_the_store_profile(): void
    {
        $this->postJson('/api/v1/profile/basic-info', $this->basicInfo())->assertOk();

        $this->postJson('/api/v1/profile/store-details', $this->storeDetails())->assertOk();

        $profile = StoreProfile::where('user_id', $this->owner->id)->firstOrFail();
        $this->assertSame('Riyadh Sweets', $profile->store_name);
        $this->assertSame('care@riyadhsweets.sa', $profile->email);
        $this->assertNotSame($this->owner->phone, $profile->phone);
        $this->assertStringEndsWith('501112233', $profile->phone);

        // The owner's account is untouched by step 2
        $this->assertSame('owner@example.com', $this->owner->fresh()->email);
    }

    public function test_store_details_requires_store_name_and_phone(): void
    {
        $this->postJson('/api/v1/profile/basic-info', $this->basicInfo())->assertOk();

        $this->postJson('/api/v1/profile/store-details', $this->storeDetails(['store_name' => null, 'phone' => null]))
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['store_name', 'phone']]]);
    }

    public function test_store_details_accepts_store_prefixed_aliases_and_validates_email_uniqueness(): void
    {
        StoreProfile::factory()->create(['email' => 'taken@example.com']);
        $this->postJson('/api/v1/profile/basic-info', $this->basicInfo())->assertOk();

        $this->postJson('/api/v1/profile/store-details', $this->storeDetails(['email' => 'taken@example.com']))
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['email']]]);

        $payload = $this->storeDetails(['store_phone' => '0502223344', 'store_email' => 'shop@example.com']);
        unset($payload['phone'], $payload['email']);

        $this->postJson('/api/v1/profile/store-details', $payload)->assertOk();

        $profile = StoreProfile::where('user_id', $this->owner->id)->firstOrFail();
        $this->assertStringEndsWith('502223344', $profile->phone);
        $this->assertSame('shop@example.com', $profile->email);
    }

    public function test_unified_update_keeps_owner_email_and_store_contact_apart(): void
    {
        $this->postJson('/api/v1/profile/basic-info', $this->basicInfo())->assertOk();
        $this->postJson('/api/v1/profile/store-details', $this->storeDetails())->assertOk();

        $this->putJson('/api/v1/profile/update', ['email' => 'new-owner@example.com'])->assertOk();

        $profile = StoreProfile::where('user_id', $this->owner->id)->firstOrFail();
        $this->assertSame('new-owner@example.com', $this->owner->fresh()->email);
        $this->assertSame('care@riyadhsweets.sa', $profile->email);

        $this->putJson('/api/v1/profile/update', ['store_email' => 'hello@example.com', 'store_phone' => '0503334455'])->assertOk();

        $profile->refresh();
        $this->assertSame('hello@example.com', $profile->email);
        $this->assertStringEndsWith('503334455', $profile->phone);
        $this->assertSame('new-owner@example.com', $this->owner->fresh()->email);
    }
}
