<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Store card at the top of the product details screen. The "Visit Store"
 * button opens GET /stores/{id}.
 *
 * @mixin \App\Models\StoreProfile
 */
class ProductStoreResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->store_name,
            'logo'        => $this->fileUrl($this->logo),
            'description' => $this->description,
        ];
    }
}
