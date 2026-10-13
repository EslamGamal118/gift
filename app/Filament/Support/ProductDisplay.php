<?php

namespace App\Filament\Support;

use App\Models\Product;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock status of a product (in stock / low / out / expired), shared by the
 * products resource and the store page.
 */
class ProductDisplay
{
    /** At or below this stock a product is flagged as running low. */
    public const LOW_STOCK = 5;

    public static function stockState(Product $product): string
    {
        return match (true) {
            $product->isExpired() => 'expired',
            $product->stock_quantity <= 0 => 'out',
            $product->stock_quantity <= self::LOW_STOCK => 'low',
            default => 'in',
        };
    }

    public static function stockLabel(Product $product): string
    {
        return __('admin.products.stock.'.self::stockState($product), ['count' => $product->stock_quantity]);
    }

    public static function stockColor(string $state): string
    {
        return match ($state) {
            'in' => 'success',
            'low' => 'warning',
            default => 'danger',
        };
    }

    public static function stockColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('stock_state')
            ->label(__('admin.products.stock_status'))
            ->state(fn (Product $record) => self::stockState($record))
            ->badge()
            ->formatStateUsing(fn (string $state, Product $record) => self::stockLabel($record))
            ->color(fn (string $state) => self::stockColor($state));
    }

    public static function stockFilter(): Tables\Filters\SelectFilter
    {
        return Tables\Filters\SelectFilter::make('stock')
            ->label(__('admin.products.stock_status'))
            ->options(__('admin.products.stock_filter'))
            ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                'in' => $query->where('products.stock_quantity', '>', self::LOW_STOCK),
                'low' => $query->whereBetween('products.stock_quantity', [1, self::LOW_STOCK]),
                'out' => $query->where('products.stock_quantity', '<=', 0),
                'expired' => $query->whereDate('products.expiry_date', '<', now()->toDateString()),
                default => $query,
            });
    }

    /**
     * Shown in the app: the store's owner is active and its profile approved (Product::visible).
     */
    public static function isListed(Product $product): bool
    {
        return Product::query()->visible()->whereKey($product->getKey())->exists();
    }
}
