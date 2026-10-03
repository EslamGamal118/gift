<?php

namespace App\Http\Requests\Catalog\Concerns;

use App\Models\User;
use App\Support\City;
use App\Support\CustomerLocation;
use App\Support\Geo;
use App\Support\LastKnownLocation;
use Illuminate\Validation\Rule;

/**
 * Resolves the customer's position for distance calculations, in this order:
 *
 *   1. `latitude` / `longitude` query parameters (device GPS)          -> exact
 *   2. `city` query parameter (manual selection from config/cities.php) -> approximate
 *   2b. last GPS position of the signed-in customer, cached by step 1     -> exact
 *       (only for requests that opt in with remembersLocation())
 *   3. default saved address of the authenticated customer              -> exact
 *   4. saved position of the authenticated captain / shopper profile    -> exact
 *   5. nothing (guest who denied location and picked no city)           -> null
 *
 * Callers that must always have a position (the home screen) can ask for
 * `locationOrDefaultCity()` which falls back to the configured default city.
 */
trait ResolvesCustomerLocation
{
    protected ?CustomerLocation $resolvedLocation = null;

    protected bool $locationResolved = false;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function locationRules(): array
    {
        return [
            'latitude'  => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'city'      => ['nullable', 'string', Rule::in(City::keys())],
        ];
    }

    public function location(): ?CustomerLocation
    {
        if (! $this->locationResolved) {
            $this->resolvedLocation = $this->resolveLocation();
            $this->locationResolved = true;
        }

        return $this->resolvedLocation;
    }

    /**
     * Same as location() but falls back to the default city, so a position is
     * available unless no cities are configured at all.
     */
    public function locationOrDefaultCity(): ?CustomerLocation
    {
        if ($location = $this->location()) {
            return $location;
        }

        $city = City::default();

        return $city ? CustomerLocation::fromCity($city) : null;
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public function coordinates(): ?array
    {
        return $this->location()?->coordinates();
    }

    protected function resolveLocation(): ?CustomerLocation
    {
        $lat = $this->validated('latitude');
        $lng = $this->validated('longitude');

        /** @var User|null $user */
        $user = $this->user('sanctum');

        if (Geo::isValidLatitude($lat) && Geo::isValidLongitude($lng)) {
            $location = CustomerLocation::gps((float) $lat, (float) $lng);

            if ($user && $this->remembersLocation()) {
                app(LastKnownLocation::class)->remember($user, $location);
            }

            return $location;
        }

        if ($city = City::find($this->validated('city'))) {
            return CustomerLocation::fromCity($city);
        }

        if (! $user) {
            return null;
        }

        if ($this->remembersLocation() && ($lastKnown = app(LastKnownLocation::class)->recall($user))) {
            return $lastKnown;
        }

        $address = $user->addresses()->default()->first();

        if ($address && $address->hasCoordinates()) {
            return new CustomerLocation(
                (float) $address->latitude,
                (float) $address->longitude,
                CustomerLocation::SOURCE_ADDRESS,
                City::find($address->city),
            );
        }

        $profile = $user->profile();

        if ($profile && Geo::isValidLatitude($profile->latitude ?? null) && Geo::isValidLongitude($profile->longitude ?? null)) {
            return new CustomerLocation((float) $profile->latitude, (float) $profile->longitude, CustomerLocation::SOURCE_PROFILE);
        }

        return null;
    }

    /**
     * Whether GPS coordinates sent by a signed-in customer are cached and
     * reused (step 2b) when a later request comes without them.
     */
    protected function remembersLocation(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function locationAttributes(): array
    {
        return [
            'latitude'  => __('validation.attributes.latitude'),
            'longitude' => __('validation.attributes.longitude'),
            'city'      => __('validation.attributes.city'),
        ];
    }
}
