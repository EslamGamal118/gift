<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class VehicleTypeSeeder extends Seeder
{
    /**
     * أنواع المركبات المتاحة للكباتن.
     */
    public function run(): void
    {
        $types = [
            ['en' => 'Motorcycle', 'ar' => 'دراجة نارية'],
            ['en' => 'Sedan Car', 'ar' => 'سيارة صالون'],
            ['en' => 'SUV', 'ar' => 'سيارة دفع رباعي'],
            ['en' => 'Pickup Truck', 'ar' => 'سيارة نقل صغيرة'],
            ['en' => 'Refrigerated Van', 'ar' => 'مركبة مبردة'],
        ];

        foreach ($types as $name) {
            VehicleType::create([
                'name' => $name,
                'is_active' => true,
            ]);
        }
    }
}
