<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StoreBranch
 */
class StoreBranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'phone'        => $this->phone,
            'address'      => $this->address,
            'latitude'     => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude'    => $this->longitude !== null ? (float) $this->longitude : null,
            'is_main'      => $this->is_main,
            'is_active'    => $this->is_active,
        ];
    }
}
