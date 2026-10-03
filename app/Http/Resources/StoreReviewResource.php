<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A customer review as shown on the store screen. The reviewer's name is
 * shortened to "First L." so reviews never expose full identities.
 *
 * @mixin \App\Models\StoreReview
 */
class StoreReviewResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->whenLoaded('user', fn () => $this->user, null);

        return [
            'id'         => $this->id,
            'rating'     => (int) $this->rating,
            'comment'    => $this->comment,
            'user'       => [
                'id'     => $this->user_id,
                'name'   => $this->displayName($user?->name),
                'avatar' => $this->fileUrl($user?->avatar),
            ],
            'is_verified_purchase' => $this->order_id !== null,
            'created_at'           => $this->created_at?->toIso8601String(),
            'created_at_human'     => $this->created_at?->diffForHumans(),
        ];
    }

    /**
     * "Sara Al-Ahmad" -> "Sara A."
     */
    protected function displayName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return __('store.reviews.anonymous');
        }

        $parts = preg_split('/\s+/u', $name) ?: [$name];

        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0].' '.Str::substr(end($parts), 0, 1).'.';
    }
}
