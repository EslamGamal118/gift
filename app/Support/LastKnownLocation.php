<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived memory of a signed-in customer's last GPS position, so a
 * request without coordinates (app resumed, location briefly unavailable)
 * still gets distances for where the customer actually is. Guests are never
 * remembered: they rely on the coordinates they send.
 */
class LastKnownLocation
{
    public function remember(User $user, CustomerLocation $location): void
    {
        $minutes = (int) config('stores.home.location_ttl', 0);

        if ($minutes <= 0) {
            return;
        }

        Cache::put($this->key($user), $location->coordinates(), now()->addMinutes($minutes));
    }

    public function recall(User $user): ?CustomerLocation
    {
        $cached = Cache::get($this->key($user));

        if (! is_array($cached)
            || ! Geo::isValidLatitude($cached['latitude'] ?? null)
            || ! Geo::isValidLongitude($cached['longitude'] ?? null)) {
            return null;
        }

        return new CustomerLocation((float) $cached['latitude'], (float) $cached['longitude'], CustomerLocation::SOURCE_LAST_KNOWN);
    }

    public function forget(User $user): void
    {
        Cache::forget($this->key($user));
    }

    protected function key(User $user): string
    {
        return "customer_location:{$user->getKey()}";
    }
}
