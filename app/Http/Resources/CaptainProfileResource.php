<?php

namespace App\Http\Resources;

use App\Http\Resources\Profile\ProfileSectionsResource;
use App\Models\CaptainProfile;

/**
 * Captain profile sections: vehicle details, documents and map location.
 * Expects `vehicleType` to be loaded.
 *
 * @mixin CaptainProfile
 */
class CaptainProfileResource extends ProfileSectionsResource
{
    protected function details(): array
    {
        return [
            'vehicle_type_id' => $this->vehicle_type_id,
            'vehicle_type'    => $this->vehicleType ? new VehicleTypeResource($this->vehicleType) : null,
            'vehicle_model'   => $this->vehicle_model,
            'plate_number'    => $this->plate_number,
            'vehicle_image'   => $this->document('vehicle_image', $this->vehicle_image_file),
            'plate_image'     => $this->document('plate_image', $this->plate_number_file),
            'driving_license' => $this->document('driving_license', $this->license_file),
        ];
    }

    protected function documentFiles(): array
    {
        return [
            'driving_license'     => $this->license_file,
            'vehicle_image'       => $this->vehicle_image_file,
            'plate_image'         => $this->plate_number_file,
            'commercial_register' => $this->commercial_register_file,
        ];
    }

    protected function location(): array
    {
        return $this->point($this->address, $this->latitude, $this->longitude);
    }
}
