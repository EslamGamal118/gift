<?php

namespace App\Http\Resources;

use App\Http\Resources\Profile\ProfileSectionsResource;
use App\Models\ShopperProfile;

/**
 * Personal shopper profile sections: activity (categories) and identity documents,
 * and map location. Expects `categories` to be loaded.
 *
 * @mixin ShopperProfile
 */
class ShopperProfileResource extends ProfileSectionsResource
{
    protected function details(): array
    {
        return [
            'category_ids'          => $this->categories->pluck('id')->all(),
            'categories'            => CategoryResource::collection($this->categories),
            'national_id_number'    => $this->national_id_number,
            'national_id'           => $this->document('national_id', $this->national_id_file),
            'freelance_certificate' => $this->document('freelance_certificate', $this->freelance_license_file),
            'driving_license'       => $this->document('driving_license', $this->driving_license_file),
        ];
    }

    protected function documentFiles(): array
    {
        return [
            'national_id'           => $this->national_id_file,
            'freelance_certificate' => $this->freelance_license_file,
            'driving_license'       => $this->driving_license_file,
        ];
    }

    protected function location(): array
    {
        return $this->point($this->address, $this->latitude, $this->longitude);
    }
}
