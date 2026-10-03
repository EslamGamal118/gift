<?php

namespace Database\Seeders;

use App\Models\DeliverySlot;
use Illuminate\Database\Seeder;

class DeliverySlotSeeder extends Seeder
{
    /**
     * Default daily delivery windows: two morning and two evening slots.
     */
    public function run(): void
    {
        $slots = [
            ['period' => 'morning', 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'label' => ['en' => '9:00 AM - 11:00 AM', 'ar' => '9:00 ص - 11:00 ص']],
            ['period' => 'morning', 'start_time' => '11:00:00', 'end_time' => '13:00:00', 'label' => ['en' => '11:00 AM - 1:00 PM', 'ar' => '11:00 ص - 1:00 م']],
            ['period' => 'evening', 'start_time' => '15:00:00', 'end_time' => '17:00:00', 'label' => ['en' => '3:00 PM - 5:00 PM', 'ar' => '3:00 م - 5:00 م']],
            ['period' => 'evening', 'start_time' => '17:00:00', 'end_time' => '19:00:00', 'label' => ['en' => '5:00 PM - 7:00 PM', 'ar' => '5:00 م - 7:00 م']],
        ];

        foreach ($slots as $i => $slot) {
            DeliverySlot::query()->updateOrCreate(
                ['period' => $slot['period'], 'start_time' => $slot['start_time'], 'end_time' => $slot['end_time']],
                ['label' => $slot['label'], 'is_active' => true, 'sort_order' => $i + 1],
            );
        }
    }
}
