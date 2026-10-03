<?php

namespace Database\Seeders;

use App\Models\CaptainProfile;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class CaptainProfileSeeder extends Seeder
{
    /**
     * حسابات الكباتن مع بروفايلاتها.
     */
    public function run(): void
    {
        // أنواع المركبات مطلوبة مسبقًا (VehicleTypeSeeder)
        $vehicleTypeIds = VehicleType::query()->pluck('id');

        // كابتن ثابت للاختبار
        $user = User::create([
            'name' => 'كابتن تجريبي',
            'phone' => '966520000000',
            'email' => 'captain@gift.test',
            'user_type' => 'captain',
            'status' => 'active',
            'phone_verified_at' => now(),
        ]);

        CaptainProfile::factory()->approved()->create([
            'user_id' => $user->id,
            'vehicle_type_id' => $vehicleTypeIds->first(),
        ]);

        // كباتن معتمدون
        CaptainProfile::factory()
            ->count(10)
            ->approved()
            ->create(['vehicle_type_id' => fn () => $vehicleTypeIds->random()]);

        // كباتن قيد المراجعة
        CaptainProfile::factory()
            ->count(4)
            ->pending()
            ->create(['vehicle_type_id' => fn () => $vehicleTypeIds->random()]);

        // كباتن مسودة
        CaptainProfile::factory()->count(2)->draft()->create([
            'vehicle_type_id' => fn () => $vehicleTypeIds->random(),
        ]);

        // كباتن مرفوضون
        CaptainProfile::factory()->count(2)->rejected()->create([
            'vehicle_type_id' => fn () => $vehicleTypeIds->random(),
        ]);
    }
}
