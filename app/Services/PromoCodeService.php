<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;

/**
 * Validates promo codes against a cart and computes the discount.
 *
 * A cart may span several stores. A code restricted to one store discounts
 * only that store's share; a platform-wide code is computed on the whole
 * subtotal and split between the stores' orders pro rata.
 */
class PromoCodeService
{
    /**
     * Resolve a code the customer typed and make sure it can be used on this cart.
     *
     * @throws CheckoutException
     */
    public function resolve(string $code, User $user, ?int $storeId, float $subtotal): PromoCode
    {
        $promo = PromoCode::query()->code($code)->first()
            ?? throw CheckoutException::promoInvalid('not_found');

        $this->assertUsable($promo, $user, $storeId, $subtotal);

        return $promo;
    }

    /**
     * Re-validate a code already attached to the cart (it may have expired since).
     *
     * @throws CheckoutException
     */
    public function assertUsable(PromoCode $promo, User $user, ?int $storeId, float $subtotal): void
    {
        if (! $promo->is_active || ($promo->starts_at !== null && $promo->starts_at->isFuture())) {
            throw CheckoutException::promoInvalid('inactive');
        }

        if ($promo->isExpired()) {
            throw CheckoutException::promoInvalid('expired');
        }

        if (! $promo->appliesToStore($storeId)) {
            throw CheckoutException::promoInvalid('wrong_store');
        }

        if ($promo->min_order_amount !== null && $subtotal < (float) $promo->min_order_amount) {
            throw new CheckoutException('checkout.promo.min_order', 422, [
                'reason' => 'min_order',
                'min_order_amount' => (float) $promo->min_order_amount,
            ], ['amount' => number_format((float) $promo->min_order_amount, 2)]);
        }

        if ($promo->hasReachedUsageLimit()) {
            throw CheckoutException::promoInvalid('usage_limit');
        }

        if ($promo->usage_limit_per_user !== null && $this->usageByUser($promo, $user) >= $promo->usage_limit_per_user) {
            throw CheckoutException::promoInvalid('user_limit');
        }
    }

    /**
     * resolve() for a multi-store cart.
     *
     * @param  array<int, float>  $storeSubtotals  merchant account id => that store's subtotal
     *
     * @throws CheckoutException
     */
    public function resolveForCart(string $code, User $user, array $storeSubtotals): PromoCode
    {
        $promo = PromoCode::query()->code($code)->first()
            ?? throw CheckoutException::promoInvalid('not_found');

        $this->assertUsableForCart($promo, $user, $storeSubtotals);

        return $promo;
    }

    /**
     * assertUsable() for a multi-store cart: the code must apply to at least
     * one of its stores, and the minimum order is checked against the
     * subtotal of the stores it applies to.
     *
     * @param  array<int, float>  $storeSubtotals
     *
     * @throws CheckoutException
     */
    public function assertUsableForCart(PromoCode $promo, User $user, array $storeSubtotals): void
    {
        $eligible = $this->eligibleSubtotals($promo, $storeSubtotals);

        if ($eligible === []) {
            throw CheckoutException::promoInvalid('wrong_store');
        }

        $this->assertUsable($promo, $user, array_key_first($eligible), array_sum($eligible));
    }

    public function discount(?PromoCode $promo, float $subtotal): float
    {
        return $promo ? $promo->discountFor($subtotal) : 0.0;
    }

    /**
     * The discount of a multi-store cart, split per store: computed once on
     * the eligible subtotal (so caps and fixed amounts apply once, not per
     * store), then shared pro rata. The last eligible store takes the rounding
     * remainder so the shares add up exactly.
     *
     * @param  array<int, float>  $storeSubtotals
     * @return array<int, float> merchant account id => discount (0 for stores the code does not cover)
     */
    public function allocate(?PromoCode $promo, array $storeSubtotals): array
    {
        $shares = array_fill_keys(array_keys($storeSubtotals), 0.0);

        if (! $promo) {
            return $shares;
        }

        $eligible = $this->eligibleSubtotals($promo, $storeSubtotals);
        $base = array_sum($eligible);
        $total = $this->discount($promo, $base);

        if ($total <= 0 || $base <= 0) {
            return $shares;
        }

        $left = $total;
        $lastId = array_key_last($eligible);

        foreach ($eligible as $storeId => $subtotal) {
            $share = $storeId === $lastId ? round($left, 2) : round($total * $subtotal / $base, 2);
            $shares[$storeId] = min($share, $subtotal);
            $left -= $shares[$storeId];
        }

        return $shares;
    }

    /**
     * @param  array<int, float>  $storeSubtotals
     * @return array<int, float>
     */
    protected function eligibleSubtotals(PromoCode $promo, array $storeSubtotals): array
    {
        return array_filter($storeSubtotals, fn ($subtotal, $storeId) => $promo->appliesToStore((int) $storeId), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Count the customer's redemptions (checkouts that were not cancelled). A
     * multi-store checkout puts the code on each of its orders but is one use.
     */
    protected function usageByUser(PromoCode $promo, User $user): int
    {
        $orders = fn () => Order::query()
            ->where('promo_code_id', $promo->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', Order::STATUS_CANCELLED);

        return $orders()->whereNull('checkout_group_id')->count()
            + $orders()->whereNotNull('checkout_group_id')->distinct()->count('checkout_group_id');
    }
}
