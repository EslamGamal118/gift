<?php

namespace App\Filament\Support;

use Filament\Support\Enums\Alignment;

/**
 * Right-to-left helpers for the admin panel.
 *
 * Setting dir="ltr" on a table cell or infolist entry flips the whole cell, so
 * its content leaves the side its header is on. Left-to-right values (phones,
 * IBANs) are isolated inline instead, and the cell keeps the page direction.
 */
class Bidi
{
    // Unicode LEFT-TO-RIGHT ISOLATE ... POP DIRECTIONAL ISOLATE
    private const LTR_START = "\u{2066}";

    private const LTR_END = "\u{2069}";

    public static function isRtl(): bool
    {
        return __('filament-panels::layout.direction') === 'rtl';
    }

    /**
     * Show a value left-to-right without changing the cell's direction.
     */
    public static function ltr(?string $value): ?string
    {
        return filled($value) ? self::LTR_START.$value.self::LTR_END : $value;
    }

    /**
     * Filament renders a labelled actions header with a fixed Alignment::Right,
     * while the buttons follow the page direction (End = left in RTL). This puts
     * the buttons on the header's side in both directions.
     */
    public static function actionsAlignment(): Alignment
    {
        return self::isRtl() ? Alignment::Start : Alignment::End;
    }
}
