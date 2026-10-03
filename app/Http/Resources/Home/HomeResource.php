<?php

namespace App\Http\Resources\Home;

use App\Http\Resources\BannerResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CityResource;
use App\Http\Resources\CompactStoreListingResource;
use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Http\Resources\OnlineGiftResource;
use App\Http\Resources\ProductListingResource;
use App\Models\Gift;
use App\Models\User;
use App\Support\City;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full payload of GET /home, section by section in display order.
 * Wraps the array produced by HomeService::build().
 */
class HomeResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user     = $this->resource['user'];
        $gifts    = $this->resource['online_gifts'];

        return [
            'user'              => $this->user($user),
            'notifications'     => $this->resource['notifications'],
            'incoming_gift'     => $this->incomingGift($this->resource['incoming_gift']),
            'banners'           => BannerResource::collection($this->resource['banners']),
            'categories'        => CategoryResource::collection($this->resource['categories']),
            'custom_order'      => $this->resource['custom_order'] + [
                'title'       => __('home.custom_order.title'),
                'description' => __('home.custom_order.description'),
                'button_text' => __('home.custom_order.button'),
            ],
            'featured_products' => ProductListingResource::collection($this->resource['featured_products']),
            'online_gifts'      => $gifts ? [
                'category' => new CategoryResource($gifts['category']),
                'items'    => OnlineGiftResource::collection($gifts['items']),
            ] : null,
            'nearby_stores'     => [
                'radius_km' => $this->resource['nearby_stores']['radius_km'],
                'items'     => CompactStoreListingResource::collection($this->resource['nearby_stores']['items']),
            ],
        ];
    }

    /**
     * The "You received a new gift" popup: `has_gift` drives it, `gift_details`
     * fills it (the newest gift neither opened nor put off with "Not now"),
     * `actions` are its two buttons. `unopened_count` counts every unopened gift.
     *
     * @param  array{gift: ?Gift, unopened_count: int}  $incoming
     * @return array<string, mixed>
     */
    protected function incomingGift(array $incoming): array
    {
        /** @var Gift|null $gift */
        $gift = $incoming['gift'];
        $sender = $gift?->sender?->name;
        $line = $gift?->order?->items->first();

        return [
            'has_gift' => $gift !== null,
            'unopened_count' => $incoming['unopened_count'],
            'gift_details' => $gift ? [
                'gift_id' => $gift->id,
                'title' => __('home.incoming_gift.title'),
                'subtitle' => $sender ? __('home.incoming_gift.subtitle', ['name' => $sender]) : __('home.incoming_gift.subtitle_any'),
                'sender' => [
                    'id' => $gift->sender_id,
                    'name' => $sender,
                    'avatar' => $this->fileUrl($gift->sender?->avatar),
                ],
                'message' => $gift->gift_message,
                'product' => [
                    'name' => $line?->product_name,
                    'image' => $this->fileUrl($line?->product_image),
                ],
                'store_name' => $gift->store?->storeProfile?->store_name,
                'received_at' => $gift->paid_at?->toIso8601String(),
                'actions' => [
                    'open' => [
                        'label' => __('home.incoming_gift.open_button'),
                        'method' => 'POST',
                        'endpoint' => '/api/v1/gifts/'.$gift->id.'/open',
                    ],
                    'dismiss' => [
                        'label' => __('home.incoming_gift.dismiss_button'),
                        'method' => 'POST',
                        'endpoint' => '/api/v1/gifts/'.$gift->id.'/dismiss',
                    ],
                ],
            ] : null,
        ];
    }

    /**
     * Header block: greeting differs for guests and signed-in users.
     *
     * @return array<string, mixed>
     */
    protected function user(?User $user): array
    {
        if (! $user) {
            return [
                'is_guest' => true,
                'id'       => null,
                'name'     => null,
                'avatar'   => null,
                'greeting' => __('home.greeting_guest'),
            ];
        }

        return [
            'is_guest' => false,
            'id'       => $user->id,
            'name'     => $user->name,
            'avatar'   => $this->fileUrl($user->avatar),
            'greeting' => filled($user->name)
                ? __('home.greeting_user', ['name' => $user->name])
                : __('home.greeting_user_anonymous'),
        ];
    }
}
