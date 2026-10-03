<?php

namespace App\Support;

/**
 * Formats amounts for API responses: the raw number for arithmetic, the ISO
 * currency code, and a localized display string ("49.00 SAR" / "49.00 ر.س").
 */
class Money
{
    /**
     * @return array{amount: float, currency: string, formatted: string}
     */
    public static function format(float|int|string|null $amount, ?string $currency = null): array
    {
        $amount   = round((float) $amount, 2);
        $currency = strtoupper($currency ?? (string) config('stores.delivery.currency', 'SAR'));

        return [
            'amount'    => $amount,
            'currency'  => $currency,
            'formatted' => self::label($amount, $currency),
        ];
    }

    /**
     * Display string only, e.g. "49.00 ر.س".
     */
    public static function label(float $amount, ?string $currency = null): string
    {
        $currency = strtoupper($currency ?? (string) config('stores.delivery.currency', 'SAR'));
        $symbol   = __("store.currency.{$currency}");

        // No translation for this code -> fall back to the code itself.
        if ($symbol === "store.currency.{$currency}") {
            $symbol = $currency;
        }

        return __('store.price_format', [
            'amount'   => number_format($amount, 2, '.', ','),
            'currency' => $symbol,
        ]);
    }
}
