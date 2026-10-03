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

/**
 * GET /profile lays every role's profile out in the screen sections
 * (basic_info, details, documents, working_hours, location) and
 * /profile/update can change any of them.
 */
class ProfileSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['user_type' => $role, 'name' => 'Owner Name', 'email' => "{$role}@example.com"]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_store_profile_sections(): void
    {
        $user    = $this->actingAsRole('store');
        $profile = StoreProfile::factory()->approved()->create([
            'user_id' => $user->id, 'store_name' => 'Rose Shop', 'phone' => '966511111111', 'email' => 'care@rose.sa',
            'commercial_register_file' => 'profiles/store/cr.pdf', 'iban_certificate_file' => 'profiles/store/iban.png',
        ]);
        StoreBranch::factory()->main()->create(['store_profile_id' => $profile->id, 'address' => 'Olaya, Riyadh', 'latitude' => 24.7, 'longitude' => 46.6]);
        StoreBranch::factory()->create(['store_profile_id' => $profile->id]);

        $response = $this->getJson('/api/v1/profile')->assertOk();

        $response
            ->assertJsonPath('data.user.name', 'Owner Name')
            ->assertJsonPath('data.profile.basic_info.name', 'Owner Name')
            ->assertJsonPath('data.profile.basic_info.phone', $user->phone)
            ->assertJsonPath('data.profile.basic_info.email', 'store@example.com')
            ->assertJsonPath('data.profile.basic_info.iban', $profile->iban)
            ->assertJsonPath('data.profile.basic_info.iban_certificate.file_type', 'image')
            ->assertJsonPath('data.profile.details.store_name', 'Rose Shop')
            ->assertJsonPath('data.profile.details.phone', '966511111111')
            ->assertJsonPath('data.profile.details.email', 'care@rose.sa')
            ->assertJsonPath('data.profile.details.category.id', $profile->category_id)
            ->assertJsonPath('data.profile.details.commercial_register.file_type', 'pdf')
            ->assertJsonPath('data.profile.documents.*.key', ['iban_certificate', 'commercial_register'])
            ->assertJsonPath('data.profile.working_hours.is_set', true)
            ->assertJsonCount(7, 'data.profile.working_hours.days')
            ->assertJsonPath('data.profile.working_hours.days.0.day', 'saturday')
            ->assertJsonPath('data.profile.location.address', 'Olaya, Riyadh')
            ->assertJsonPath('data.profile.location.is_set', true)
            ->assertJsonPath('data.profile.location.main_branch.is_main', true)
            ->assertJsonPath('data.profile.location.branches_count', 2)
            ->assertJsonCount(2, 'data.profile.location.branches');
    }

    public function test_captain_profile_sections(): void
    {
        $user    = $this->actingAsRole('captain');
        $vehicle = VehicleType::factory()->create(['name' => ['en' => 'Van', 'ar' => 'فان']]);
        CaptainProfile::factory()->approved()->create([
            'user_id' => $user->id, 'vehicle_type_id' => $vehicle->id, 'vehicle_model' => 'Hiace', 'plate_number' => 'ABC 1234',
            'vehicle_image_file' => null, 'address' => 'Nassim', 'latitude' => 24.1, 'longitude' => 46.2,
        ]);

        $this->getJson('/api/v1/profile')->assertOk()
            ->assertJsonPath('data.profile.details.vehicle_type.name', 'Van')
            ->assertJsonPath('data.profile.details.vehicle_model', 'Hiace')
            ->assertJsonPath('data.profile.details.plate_number', 'ABC 1234')
            ->assertJsonPath('data.profile.details.vehicle_image.is_uploaded', false)
            ->assertJsonPath('data.profile.details.driving_license.is_uploaded', true)
            ->assertJsonPath('data.profile.documents.*.key', ['iban_certificate', 'driving_license', 'vehicle_image', 'plate_image', 'commercial_register'])
            ->assertJsonPath('data.profile.working_hours', null)
            ->assertJsonPath('data.profile.location', ['address' => 'Nassim', 'latitude' => 24.1, 'longitude' => 46.2, 'is_set' => true]);
    }

    public function test_shopper_profile_sections(): void
    {
        $user    = $this->actingAsRole('shopper');
        $profile = ShopperProfile::factory()->approved()->create(['user_id' => $user->id, 'national_id_number' => '1234567890']);
        $profile->categories()->sync(Category::factory()->count(2)->create()->pluck('id'));

        $this->getJson('/api/v1/profile')->assertOk()
            ->assertJsonCount(2, 'data.profile.details.categories')
            ->assertJsonCount(2, 'data.profile.details.category_ids')
            ->assertJsonPath('data.profile.details.national_id_number', '1234567890')
            ->assertJsonPath('data.profile.documents.*.key', ['iban_certificate', 'national_id', 'freelance_certificate', 'driving_license'])
            ->assertJsonPath('data.profile.working_hours', null);
    }

    public function test_customers_have_no_profile_section(): void
    {
        $this->actingAsRole('customer');

        $this->getJson('/api/v1/profile')->assertOk()
            ->assertJsonPath('data.user.name', 'Owner Name')
            ->assertJsonPath('data.profile', null);
    }

    public function test_store_update_saves_location_on_the_main_branch(): void
    {
        $user    = $this->actingAsRole('store');
        $profile = StoreProfile::factory()->draft()->create(['user_id' => $user->id]);

        $this->putJson('/api/v1/profile/update', ['address' => 'Malqa, Riyadh', 'latitude' => 24.8, 'longitude' => 46.6])
            ->assertOk()
            ->assertJsonPath('data.profile.location.address', 'Malqa, Riyadh')
            ->assertJsonPath('data.profile.location.branches_count', 1)
            ->assertJsonPath('data.flags.location_completed', true);

        $this->putJson('/api/v1/profile/update', ['address' => 'Olaya, Riyadh', 'latitude' => 24.7, 'longitude' => 46.7])
            ->assertOk()
            ->assertJsonPath('data.profile.location.address', 'Olaya, Riyadh')
            ->assertJsonPath('data.profile.location.branches_count', 1);

        $this->assertSame(1, $profile->branches()->main()->count());

        $this->putJson('/api/v1/profile/update', ['address' => 'Olaya, Riyadh'])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['latitude', 'longitude']]]);
    }

    public function test_captain_vehicle_image_upload_sends_approved_profile_back_to_review(): void
    {
        $user    = $this->actingAsRole('captain');
        $profile = CaptainProfile::factory()->approved()->create(['user_id' => $user->id, 'vehicle_image_file' => null]);

        $this->postJson('/api/v1/profile/update', ['vehicle_image' => UploadedFile::fake()->image('car.jpg', 800, 600)])
            ->assertOk()
            ->assertJsonPath('message', __('profile.updated_pending_review'))
            ->assertJsonPath('data.profile.status', 'pending')
            ->assertJsonPath('data.profile.details.vehicle_image.is_uploaded', true)
            ->assertJsonPath('data.profile.details.vehicle_image.file_type', 'image');

        Storage::disk('public')->assertExists($profile->fresh()->vehicle_image_file);

        $this->postJson('/api/v1/profile/update', ['vehicle_image' => UploadedFile::fake()->create('car.pdf', 10, 'application/pdf')])
            ->assertStatus(409);   // pending profiles are locked
    }
}
