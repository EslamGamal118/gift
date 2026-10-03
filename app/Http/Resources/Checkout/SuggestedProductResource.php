<?php

namespace App\Http\Resources\Checkout;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product suggested under the cart (CartSuggestionService). Expects `storeProfile` loaded.
 *
 * @mixin Product
 */
class SuggestedProductResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $store = $this->storeProfile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'currency' => (string) config('stores.delivery.currency', 'SAR'),
            'image_url' => $this->fileUrl($this->image),
            'rating' => round((float) $this->rating_avg, 1),
            'rating_count' => (int) $this->rating_count,
            'store' => $store ? [
                'id' => $store->id,           // GET /api/v1/stores/{id}
                'name' => $store->store_name,
            ] : null,
        ];
    }
}
