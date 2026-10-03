<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFavoriteState;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\StoreProfile;
use App\Services\DeliveryCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * Customer-facing store header (GET /stores/{id}): only what the store
 * screen shows, including delivery fee, delivery time and distance. The dynamic sections (tabs, products, reviews) are appended
 * by the controller. The star breakdown lives on GET /stores/{id}/reviews.
 *
 * @mixin StoreProfile
 */
class StoreDetailsResource extends JsonResource
{
    use ResolvesFavoriteState, ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'user_id'     => $this->user_id,
            'name'        => $this->store_name,
            'description' => $this->description,
            'logo'        => $this->fileUrl($this->logo),
            'cover_image' => $this->fileUrl($this->cover_image),
            'phone'       => $this->phone,
            'category'    => new CategoryResource($this->whenLoaded('category')),
            'is_featured' => (bool) $this->is_featured,
            'is_favorite' => $this->isFavorite(),
            'is_open'     => $this->isOpenNow(),
            'rating'      => [
                'average' => round((float) $this->rating_avg, 1),
                'count'   => (int) $this->rating_count,
            ],
        ] + $this->deliveryMetrics();
    }

    /**
     * The three header metrics, same keys as the store cards. Never fails:
     * without a customer position or located branch the distance is 0 and
     * fee / time fall back to the base fee and preparation time; anything
     * that cannot be computed is 0.
     *
     * @return array{delivery_fee: float, delivery_time_minutes: int, distance_in_km: float}
     */
    protected function deliveryMetrics(): array
    {
        $distance = $this->resource->getAttribute('distance_km');
        $distance = $distance !== null ? (float) $distance : null;

        try {
            $quote = app(DeliveryCalculatorService::class)->quote($this->resource, $distance);
        } catch (Throwable $e) {
            report($e);
            $quote = [];
        }

        return [
            'delivery_fee'          => (float) ($quote['fee'] ?? 0),
            'delivery_time_minutes' => (int) ($quote['time']['min'] ?? 0),
            'distance_in_km'        => $distance ?? 0.0,
        ];
    }
}
