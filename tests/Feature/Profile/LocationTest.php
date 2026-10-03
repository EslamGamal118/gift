<?php

namespace Tests\Feature\Profile;

use App\Models\CaptainProfile;
use App\Models\ShopperProfile;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    protected const BRANCHES = '/api/v1/store/branches';
    protected const LOCATION = '/api/v1/profile/location';

    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['user_type' => $role]);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function branchPayload(array $overrides = []): array
    {
        return array_merge([
            'name'      => 'Olaya Branch',
            'address'   => 'Olaya St, Riyadh',
            'latitude'  => 24.7136,
            'longitude' => 46.6753,
            'phone'     => '0501234567',
        ], $overrides);
    }

    /* ------------------------------------------------------------ branches */

    public function test_store_can_list_create_update_and_delete_branches(): void
    {
        $user  = $this->actingAsRole('store');
        $store = StoreProfile::factory()->draft()->create(['user_id' => $user->id]);

        // first branch becomes main automatically
        $first = $this->postJson(self::BRANCHES, $this->branchPayload())
            ->assertStatus(201)
            ->assertJsonPath('status', 201)
            ->assertJsonPath('message', __('profile.branch_created'))
            ->assertJsonPath('data.name', 'Olaya Branch')
            ->assertJsonPath('data.address', 'Olaya St, Riyadh')
            ->assertJsonPath('data.latitude', 24.7136)
            ->assertJsonPath('data.longitude', 46.6753)
            ->assertJsonPath('data.phone', '966501234567')
            ->assertJsonPath('data.is_main', true)
            ->json('data.id');

        // second branch explicitly marked main takes over
        $second = $this->postJson(self::BRANCHES, $this->branchPayload(['name' => 'Malqa Branch', 'is_main' => true]))
            ->assertStatus(201)
            ->assertJsonPath('data.is_main', true)
            ->json('data.id');

        $this->assertFalse(StoreBranch::find($first)->is_main);
        $this->assertSame(1, $store->branches()->main()->count());

        // list: main first
        $this->getJson(self::BRANCHES)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.0.is_main', true);

        // partial update
        $this->putJson(self::BRANCHES."/{$first}", ['name' => 'Olaya Main', 'is_main' => true])
            ->assertOk()
            ->assertJsonPath('message', __('profile.branch_updated'))
            ->assertJsonPath('data.name', 'Olaya Main')
            ->assertJsonPath('data.address', 'Olaya St, Riyadh')
            ->assertJsonPath('data.is_main', true);

        $this->assertFalse(StoreBranch::find($second)->is_main);

        // a main branch cannot be demoted directly
        $this->putJson(self::BRANCHES."/{$first}", ['is_main' => false])->assertOk()->assertJsonPath('data.is_main', true);

        // deleting the main branch promotes the remaining one
        $this->deleteJson(self::BRANCHES."/{$first}")
            ->assertOk()
            ->assertJsonPath('message', __('profile.branch_deleted'));

        $this->assertDatabaseMissing('store_branches', ['id' => $first]);
        $this->assertTrue(StoreBranch::find($second)->is_main);
    }

    public function test_branches_are_scoped_to_the_authenticated_store(): void
    {
        $other = StoreBranch::factory()->create();   // belongs to a different store

        $user = $this->actingAsRole('store');
        StoreProfile::factory()->draft()->create(['user_id' => $user->id]);

        $this->getJson(self::BRANCHES)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson(self::BRANCHES."/{$other->id}", ['name' => 'Hijacked'])->assertStatus(404)->assertJsonPath('status', 404);
        $this->deleteJson(self::BRANCHES."/{$other->id}")->assertStatus(404);

        $this->assertDatabaseHas('store_branches', ['id' => $other->id, 'name' => $other->name]);
    }

    public function test_branch_validation_is_localized(): void
    {
        $user = $this->actingAsRole('store');
        StoreProfile::factory()->draft()->create(['user_id' => $user->id]);

        $this->withHeader('Accept-Language', 'ar')
            ->postJson(self::BRANCHES, ['latitude' => 120, 'longitude' => 'x', 'phone' => '123'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'البيانات المدخلة غير صالحة.')
            ->assertJsonPath('data.errors.name.0', 'اسم الفرع مطلوب.')
            ->assertJsonPath('data.errors.address.0', 'العنوان مطلوب.')
            ->assertJsonPath('data.errors.latitude.0', 'يجب أن تكون قيمة خط العرض بين -90 و 90.')
            ->assertJsonPath('data.errors.longitude.0', 'يجب أن يكون خط الطول رقمًا.')
            ->assertJsonPath('data.errors.phone.0', 'يرجى إدخال رقم جوال صحيح.');
    }

    public function test_branches_require_store_role_and_basic_info(): void
    {
        $captain = $this->actingAsRole('captain');
        CaptainProfile::factory()->draft()->create(['user_id' => $captain->id]);

        $this->getJson(self::BRANCHES)
            ->assertStatus(403)
            ->assertJsonPath('message', __('profile.role_not_allowed'));

        $this->actingAsRole('store');   // no store profile yet

        $this->withHeader('Accept-Language', 'ar')->postJson(self::BRANCHES, $this->branchPayload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'يرجى إكمال خطوة البيانات الأساسية أولًا.');
    }

    public function test_adding_a_branch_completes_the_store_location_step(): void
    {
        $user = $this->actingAsRole('store');
        StoreProfile::factory()->draft()->create(['user_id' => $user->id]);

        $this->getJson('/api/v1/profile')->assertJsonPath('data.flags.location_completed', false);

        $this->postJson(self::BRANCHES, $this->branchPayload())->assertStatus(201);

        $this->getJson('/api/v1/profile')
            ->assertJsonPath('data.flags.location_completed', true)
            ->assertJsonPath('data.profile.location.branches_count', 1)
            ->assertJsonPath('data.profile.location.main_branch.name', 'Olaya Branch');
    }

    /* ------------------------------------------------------------ location */

    public function test_captain_can_save_and_update_map_location(): void
    {
        $user = $this->actingAsRole('captain');
        CaptainProfile::factory()->draft()->create(['user_id' => $user->id, 'address' => null, 'latitude' => null, 'longitude' => null]);

        $this->getJson('/api/v1/profile')->assertJsonPath('data.flags.location_completed', false);

        $this->withHeader('Accept-Language', 'ar')->postJson(self::LOCATION, [
            'address'   => '  حي النسيم، الرياض ',
            'latitude'  => '24.774265',
            'longitude' => '46.738586',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'تم حفظ الموقع بنجاح.')
            ->assertJsonPath('data.account_type', 'captain')
            ->assertJsonPath('data.profile.location.address', 'حي النسيم، الرياض')
            ->assertJsonPath('data.profile.location.latitude', 24.774265)
            ->assertJsonPath('data.profile.location.longitude', 46.738586)
            ->assertJsonPath('data.flags.location_completed', true);

        // saving again updates in place
        $this->postJson(self::LOCATION, ['address' => 'Jeddah', 'latitude' => 21.4858, 'longitude' => 39.1925])
            ->assertOk()
            ->assertJsonPath('data.profile.location.address', 'Jeddah');

        $this->assertDatabaseCount('captain_profiles', 1);
        $this->assertDatabaseHas('captain_profiles', ['user_id' => $user->id, 'address' => 'Jeddah']);
    }

    public function test_shopper_can_save_map_location_and_validation_is_localized(): void
    {
        $user = $this->actingAsRole('shopper');
        ShopperProfile::factory()->draft()->create(['user_id' => $user->id]);

        $this->withHeader('Accept-Language', 'ar')->postJson(self::LOCATION, ['latitude' => -100])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.address.0', 'العنوان مطلوب.')
            ->assertJsonPath('data.errors.latitude.0', 'يجب أن تكون قيمة خط العرض بين -90 و 90.')
            ->assertJsonPath('data.errors.longitude.0', 'خط الطول مطلوب.');

        $this->postJson(self::LOCATION, ['address' => 'Al Khobar', 'latitude' => 26.2172, 'longitude' => 50.1971])
            ->assertOk()
            ->assertJsonPath('message', __('profile.location_saved'))
            ->assertJsonPath('data.profile.location.address', 'Al Khobar')
            ->assertJsonPath('data.flags.location_completed', true);
    }

    public function test_location_endpoint_rejects_customers_and_requires_basic_info(): void
    {
        $this->actingAsRole('customer');

        $this->postJson(self::LOCATION, ['address' => 'x', 'latitude' => 1, 'longitude' => 1])
            ->assertStatus(403)
            ->assertJsonPath('message', __('profile.role_not_allowed'))
            ->assertJsonPath('data.account_type', 'customer');

        $this->actingAsRole('shopper');   // no profile yet

        $this->postJson(self::LOCATION, ['address' => 'x', 'latitude' => 1, 'longitude' => 1])
            ->assertStatus(409)
            ->assertJsonPath('message', __('profile.basic_info_required'));
    }

    public function test_location_is_locked_while_pending_but_editable_when_approved_or_rejected(): void
    {
        $user    = $this->actingAsRole('shopper');
        $profile = ShopperProfile::factory()->pending()->create(['user_id' => $user->id]);
        $payload = ['address' => 'Dammam', 'latitude' => 26.4207, 'longitude' => 50.0888];

        $this->postJson(self::LOCATION, $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', __('profile.locked_pending'));

        $profile->update(['status' => 'approved']);
        $user->unsetRelation('shopperProfile');

        $this->postJson(self::LOCATION, $payload)
            ->assertOk()
            ->assertJsonPath('data.profile.status', 'approved');

        $profile->update(['status' => 'rejected', 'rejection_reason' => 'Wrong area']);
        $user->unsetRelation('shopperProfile');

        $this->postJson(self::LOCATION, $payload)
            ->assertOk()
            ->assertJsonPath('data.profile.status', 'draft')
            ->assertJsonPath('data.flags.is_rejected', false);

        $this->assertNull($profile->fresh()->rejection_reason);
    }
}
