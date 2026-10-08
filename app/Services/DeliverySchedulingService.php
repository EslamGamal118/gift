<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\DeliverySlot;
use App\Models\StoreProfile;
use App\Models\UserAddress;
use App\Support\Geo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Delivery dates / time slots, the instant-delivery option and the fees that
 * go with them. All date maths is done in the store timezone.
 *
 * A cart may hold several stores: one delivery selection applies to all of
 * them, so an option is only offered when every store can honour it, and
 * fees are charged per store (each store's order is delivered separately).
 */
class DeliverySchedulingService
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    public function __construct(protected DeliveryCalculatorService $delivery)
    {
        $this->config = config('checkout.delivery', []);
    }

    /*
    |--------------------------------------------------------------------------
    | Options shown on the "delivery time" screen
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Collection<int, StoreProfile>  $stores  The stores in the cart
     * @return array{
     *     instant: array{available: bool, fee: float, eta_minutes: array{min: int, max: int}},
     *     dates: list<array<string, mixed>>
     * }
     */
    public function options(Collection $stores): array
    {
        $now = $this->now();
        $slots = DeliverySlot::query()->active()->ordered()->get();
        $dates = [];

        for ($i = 0; $i < $this->daysAhead(); $i++) {
            $date = $now->copy()->startOfDay()->addDays($i);

            $dates[] = [
                'date' => $date->toDateString(),
                'label' => $this->dateLabel($i),
                'day_name' => $date->translatedFormat('l'),
                'is_today' => $i === 0,
                'slots' => $slots->map(fn (DeliverySlot $slot) => [
                    'id' => $slot->id,
                    'label' => $slot->label,
                    'period' => $slot->period,
                    'start_time' => substr($slot->start_time, 0, 5),
                    'end_time' => substr($slot->end_time, 0, 5),
                    'time_range' => $slot->timeRange(),
                    'available' => $this->isSlotAvailableForAll($slot, $date, $stores, $now),
                ])->values()->all(),
            ];
        }

        return [
            'instant' => [
                'available' => $this->isInstantAvailableForAll($stores, $now),
                'fee' => round($this->instantFee() * max(1, $stores->count()), 2),
                'eta_minutes' => $this->instantEta(),
            ],
            'dates' => $dates,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Selection
    |--------------------------------------------------------------------------
    */

    /**
     * Validate a selection against availability and persist it on the cart.
     */
    public function select(Cart $cart, string $type, ?string $date, ?int $slotId): Cart
    {
        $stores = $this->storesOf($cart);

        if ($type === Cart::DELIVERY_INSTANT) {
            if (! $this->isInstantAvailableForAll($stores, $this->now())) {
                throw CheckoutException::instantUnavailable();
            }

            $cart->forceFill([
                'delivery_type' => Cart::DELIVERY_INSTANT,
                'delivery_date' => null,
                'delivery_slot_id' => null,
            ])->save();

            return $cart;
        }

        $slot = DeliverySlot::query()->active()->find($slotId);
        $day = $this->parseDate($date);

        if (! $slot || ! $day || ! $this->isSlotAvailableForAll($slot, $day, $stores, $this->now())) {
            throw CheckoutException::slotUnavailable();
        }

        $cart->forceFill([
            'delivery_type' => Cart::DELIVERY_SCHEDULED,
            'delivery_date' => $day->toDateString(),
            'delivery_slot_id' => $slot->id,
        ])->save();

        return $cart;
    }

    /**
     * Re-check a cart's saved selection right before the order is placed.
     */
    public function assertSelectionStillValid(Cart $cart): void
    {
        if (! $cart->hasDeliverySelection()) {
            throw CheckoutException::deliveryRequired();
        }

        $stores = $this->storesOf($cart);

        if ($cart->isInstantDelivery()) {
            if (! $this->isInstantAvailableForAll($stores, $this->now())) {
                throw CheckoutException::instantUnavailable();
            }

            return;
        }

        $slot = $cart->deliverySlot;
        $day = $cart->delivery_date ? $this->parseDate($cart->delivery_date->toDateString()) : null;

        if (! $slot || ! $slot->is_active || ! $day || ! $this->isSlotAvailableForAll($slot, $day, $stores, $this->now())) {
            throw CheckoutException::slotUnavailable();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fees & quotes
    |--------------------------------------------------------------------------
    */

    /**
     * Delivery charges for a store / address / delivery type combination.
     *
     * @return array{
     *     delivery_fee: float, express_fee: float, total: float, currency: string,
     *     distance_km: float|null, eta_minutes: array{min: int, max: int}|null
     * }
     */
    public function quote(?StoreProfile $store, ?UserAddress $address, ?string $type): array
    {
        $distance = $this->distanceKm($store, $address);
        $isInstant = $type === Cart::DELIVERY_INSTANT;

        $deliveryFee = $store ? $this->delivery->fee($store, $distance) : 0.0;
        $expressFee = $isInstant ? $this->instantFee() : 0.0;

        return [
            'delivery_fee' => $deliveryFee,
            'express_fee' => $expressFee,
            'total' => round($deliveryFee + $expressFee, 2),
            'currency' => $this->delivery->currency(),
            'distance_km' => $distance,
            'eta_minutes' => $isInstant ? $this->instantEta() : null,
        ];
    }

    /**
     * Delivery charges of a whole cart: one quote per store, summed.
     *
     * @return array{
     *     delivery_fee: float, express_fee: float, total: float, currency: string,
     *     eta_minutes: array{min: int, max: int}|null,
     *     stores: array<int, array<string, mixed>>
     * }
     */
    public function quoteForCart(Cart $cart, ?UserAddress $address, ?string $type): array
    {
        $stores = $this->storesOf($cart)->map(fn (StoreProfile $store) => $this->quote($store, $address, $type));

        return [
            'delivery_fee' => round($stores->sum('delivery_fee'), 2),
            'express_fee' => round($stores->sum('express_fee'), 2),
            'total' => round($stores->sum('total'), 2),
            'currency' => $this->delivery->currency(),
            'eta_minutes' => $type === Cart::DELIVERY_INSTANT ? $this->instantEta() : null,
            'stores' => $stores->all(),
        ];
    }

    /**
     * The stores in the cart, keyed by merchant account id (`products.store_id`).
     *
     * @return Collection<int, StoreProfile>
     */
    public function storesOf(Cart $cart): Collection
    {
        $cart->loadMissing('items.product.storeProfile');

        return $cart->itemsByStore()
            ->map(fn (Collection $items) => $items->first()->product->storeProfile)
            ->filter();
    }

    /**
     * Snapshot of the selection for an order row.
     *
     * @return array<string, mixed>
     */
    public function orderSnapshot(Cart $cart): array
    {
        if ($cart->isInstantDelivery()) {
            $eta = $this->instantEta();

            return [
                'delivery_type' => Cart::DELIVERY_INSTANT,
                'delivery_date' => $this->now()->toDateString(),
                'delivery_slot_id' => null,
                'delivery_slot_label' => null,
                'delivery_window_start' => $this->now()->addMinutes($eta['min']),
                'delivery_window_end' => $this->now()->addMinutes($eta['max']),
                'estimated_minutes_min' => $eta['min'],
                'estimated_minutes_max' => $eta['max'],
            ];
        }

        $slot = $cart->deliverySlot;
        $day = $this->parseDate($cart->delivery_date->toDateString());

        return [
            'delivery_type' => Cart::DELIVERY_SCHEDULED,
            'delivery_date' => $day->toDateString(),
            'delivery_slot_id' => $slot->id,
            'delivery_slot_label' => $slot->timeRange(),
            'delivery_window_start' => $slot->startsAt($day),
            'delivery_window_end' => $slot->endsAt($day),
            'estimated_minutes_min' => null,
            'estimated_minutes_max' => null,
        ];
    }

    public function instantFee(): float
    {
        if (DeliveryCalculatorService::isFree()) {
            return 0.0;
        }

        return round((float) ($this->config['instant']['fee'] ?? 0), 2);
    }

    /**
     * @return array{min: int, max: int}
     */
    public function instantEta(): array
    {
        return [
            'min' => (int) ($this->config['instant']['eta_min'] ?? 30),
            'max' => (int) ($this->config['instant']['eta_max'] ?? 45),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Availability rules
    |--------------------------------------------------------------------------
    */

    /**
     * Instant delivery needs the feature on and the store open right now.
     */
    public function isInstantAvailable(?StoreProfile $store, Carbon $now): bool
    {
        if (! ($this->config['instant']['enabled'] ?? false)) {
            return false;
        }

        return $store === null || $store->isOpenNow($now);
    }

    /**
     * @param  Collection<int, StoreProfile>  $stores
     */
    public function isInstantAvailableForAll(Collection $stores, Carbon $now): bool
    {
        return $stores->isEmpty()
            ? $this->isInstantAvailable(null, $now)
            : $stores->every(fn (StoreProfile $store) => $this->isInstantAvailable($store, $now));
    }

    /**
     * @param  Collection<int, StoreProfile>  $stores
     */
    public function isSlotAvailableForAll(DeliverySlot $slot, Carbon $date, Collection $stores, Carbon $now): bool
    {
        return $stores->isEmpty()
            ? $this->isSlotAvailable($slot, $date, null, $now)
            : $stores->every(fn (StoreProfile $store) => $this->isSlotAvailable($slot, $date, $store, $now));
    }

    /**
     * A slot is offered when it is in the scheduling window, starts at least the
     * lead time from now, and the store is open for at least part of it that day.
     */
    public function isSlotAvailable(DeliverySlot $slot, Carbon $date, ?StoreProfile $store, Carbon $now): bool
    {
        $date = $date->copy()->setTimezone($this->timezone())->startOfDay();
        $last = $now->copy()->startOfDay()->addDays($this->daysAhead() - 1);

        if ($date->lt($now->copy()->startOfDay()) || $date->gt($last)) {
            return false;
        }

        if ($slot->startsAt($date)->lt($now->copy()->addMinutes($this->leadMinutes()))) {
            return false;
        }

        return $store === null || $this->storeOpenDuring($store, $slot, $date);
    }

    /**
     * Whether the store's weekly schedule overlaps the slot on that day.
     */
    protected function storeOpenDuring(StoreProfile $store, DeliverySlot $slot, Carbon $date): bool
    {
        $hours = $store->working_hours;

        // No schedule configured yet: do not block scheduling.
        if (! is_array($hours) || $hours === []) {
            return true;
        }

        $day = $hours[strtolower($date->englishDayOfWeek)] ?? null;

        if (! is_array($day) || ! filter_var($day['is_open'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $from = $day['from'] ?? null;
        $to = $day['to'] ?? null;

        if (! $from || ! $to) {
            return false;
        }

        $slotStart = substr($slot->start_time, 0, 5);
        $slotEnd = substr($slot->end_time, 0, 5);

        // Overnight schedule (e.g. 16:00 -> 02:00): open from `from` until midnight.
        if ($to < $from) {
            return $slotEnd > $from;
        }

        return $slotStart < $to && $slotEnd > $from;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function distanceKm(?StoreProfile $store, ?UserAddress $address): ?float
    {
        if (! $store || ! $address || ! $address->hasCoordinates()) {
            return null;
        }

        $branch = $store->branches()
            ->active()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->sortBy(fn ($b) => Geo::distanceKm((float) $address->latitude, (float) $address->longitude, (float) $b->latitude, (float) $b->longitude))
            ->first();

        if (! $branch) {
            return null;
        }

        // Road distance, so checkout charges what the store card showed
        return $this->delivery->roadDistanceKm(Geo::distanceKm(
            (float) $address->latitude,
            (float) $address->longitude,
            (float) $branch->latitude,
            (float) $branch->longitude
        ));
    }

    /**
     * Human label for the date picker: today / tomorrow / weekday name.
     */
    protected function dateLabel(int $offset): string
    {
        return match ($offset) {
            0 => __('checkout.today'),
            1 => __('checkout.tomorrow'),
            default => $this->now()->addDays($offset)->translatedFormat('l'),
        };
    }

    protected function parseDate(?string $date): ?Carbon
    {
        if (! $date) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $date, $this->timezone())->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    protected function timezone(): string
    {
        return (string) ($this->config['timezone'] ?? config('app.timezone'));
    }

    protected function daysAhead(): int
    {
        return max(1, (int) ($this->config['days_ahead'] ?? 7));
    }

    protected function leadMinutes(): int
    {
        return max(0, (int) ($this->config['slot_lead_minutes'] ?? 60));
    }
}
