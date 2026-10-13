<?php

namespace App\Filament\Resources\StoreResource\Widgets;

use App\Models\StoreProfile;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

/**
 * Financial summary at the top of a store's page. Refreshed after a payout.
 */
class StoreStatsOverview extends StatsOverviewWidget
{
    public ?StoreProfile $record = null;

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 5;
    }

    #[On('finances-updated')]
    public function refreshFinances(): void
    {
        // Re-render; getStats() reloads the totals
    }

    protected function getStats(): array
    {
        $store = StoreProfile::withTrashed()->withFinancials()->findOrFail($this->record->getKey());
        $money = $store->financials();
        $sar = fn (float $amount) => Number::currency($amount, 'SAR', app()->getLocale());

        return [
            Stat::make(__('admin.stores.stats.sales'), $sar($money['total_sales']))
                ->description(__('admin.stores.stats.sales_hint'))
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary'),

            Stat::make(__('admin.stores.stats.commission'), $sar($money['commission']))
                ->description(__('admin.stores.stats.commission_hint', ['rate' => rtrim(rtrim((string) $store->commission_rate, '0'), '.')]))
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('info'),

            Stat::make(__('admin.stores.stats.available'), $sar($money['available']))
                ->description(trans_choice('admin.stores.stats.available_hint', $money['due_orders_count'], ['count' => $money['due_orders_count']]))
                ->descriptionIcon('heroicon-m-clock')
                ->color($money['available'] > 0 ? 'warning' : 'gray'),

            Stat::make(__('admin.stores.stats.transferred'), $sar($money['transferred']))
                ->description(trans_choice('admin.stores.stats.transferred_hint', $store->payouts()->count(), ['count' => $store->payouts()->count()]))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make(__('admin.stores.stats.rating'), $store->rating_count ? number_format((float) $store->rating_avg, 1).' ★' : '—')
                ->description(trans_choice('admin.stores.fields.reviews_count', $store->rating_count, ['count' => $store->rating_count]))
                ->descriptionIcon('heroicon-m-star')
                ->color('warning'),
        ];
    }
}
