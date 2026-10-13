<?php

namespace App\Filament\Resources\ShopperResource\Widgets;

use App\Models\ShopperProfile;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

/**
 * Key metrics at the top of a shopper's page. Refreshed after a payout.
 */
class ShopperStatsOverview extends StatsOverviewWidget
{
    public ?ShopperProfile $record = null;

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    #[On('finances-updated')]
    public function refreshFinances(): void
    {
        // Re-render; getStats() reloads the totals
    }

    protected function getStats(): array
    {
        $shopper = ShopperProfile::withTrashed()->withFinancials()->findOrFail($this->record->getKey());
        $money = $shopper->financials();
        $sar = fn (float $amount) => Number::currency($amount, 'SAR', app()->getLocale());
        $rate = rtrim(rtrim((string) $shopper->commission_rate, '0'), '.');

        return [
            Stat::make(__('admin.shoppers.stats.orders'), Number::format($money['assigned_orders']))
                ->description(trans_choice('admin.shoppers.stats.orders_hint', $money['completed_orders'], ['count' => $money['completed_orders']]))
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('primary'),

            Stat::make(__('admin.shoppers.stats.earnings'), $sar($money['net_earnings']))
                ->description(__('admin.shoppers.stats.earnings_hint', ['commission' => number_format($money['commission'], 2), 'rate' => $rate]))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make(__('admin.shoppers.stats.available'), $sar($money['available']))
                ->description(trans_choice('admin.shoppers.stats.available_hint', $money['due_orders_count'], ['count' => $money['due_orders_count']])
                    .' · '.__('admin.shoppers.stats.transferred', ['amount' => number_format($money['transferred'], 2)]))
                ->descriptionIcon('heroicon-m-clock')
                ->color($money['available'] > 0 ? 'warning' : 'gray'),

            Stat::make(__('admin.shoppers.stats.rating'), $shopper->rating_count ? number_format((float) $shopper->rating_avg, 1).' ★' : '—')
                ->description(trans_choice('admin.stores.fields.reviews_count', $shopper->rating_count, ['count' => $shopper->rating_count]))
                ->descriptionIcon('heroicon-m-star')
                ->color('warning'),
        ];
    }
}
