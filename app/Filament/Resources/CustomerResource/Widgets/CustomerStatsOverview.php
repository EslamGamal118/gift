<?php

namespace App\Filament\Resources\CustomerResource\Widgets;

use App\Filament\Resources\CustomerResource;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Key metrics at the top of a customer's page.
 */
class CustomerStatsOverview extends StatsOverviewWidget
{
    public ?User $record = null;

    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        /** @var User $customer */
        $customer = CustomerResource::withTotals(User::query()->whereKey($this->record->getKey()))->firstOrFail();

        $reviews = $customer->reviews()
            ->selectRaw('COUNT(*) as total, AVG(store_rating) as store_avg, AVG(products_rating) as products_avg')
            ->toBase()
            ->first();

        $ordersCount = $customer->orders_count + $customer->custom_orders_count;

        return [
            Stat::make(__('admin.customers.stats.orders'), Number::format($ordersCount))
                ->description(__('admin.customers.stats.orders_hint', [
                    'store' => $customer->orders_count,
                    'custom' => $customer->custom_orders_count,
                ]))
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary'),

            Stat::make(__('admin.customers.stats.spent'), Number::currency(CustomerResource::totalSpent($customer), 'SAR', app()->getLocale()))
                ->description(__('admin.customers.stats.spent_hint'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            $reviews->total
                ? Stat::make(__('admin.customers.stats.rating'), number_format((float) $reviews->store_avg, 1).' ★')
                    ->description(__('admin.customers.stats.rating_hint', [
                        'count' => $reviews->total,
                        'products' => number_format((float) $reviews->products_avg, 1).' ★',
                    ]))
                    ->descriptionIcon('heroicon-m-star')
                    ->color('warning')
                : Stat::make(__('admin.customers.stats.rating'), '—')
                    ->description(__('admin.customers.stats.no_reviews'))
                    ->descriptionIcon('heroicon-m-star')
                    ->color('gray'),

            Stat::make(__('admin.customers.stats.status'), CustomerResource::statusOptions()[$customer->status] ?? $customer->status)
                ->description(__('admin.customers.stats.member_since', ['date' => $customer->created_at?->translatedFormat('j F Y')]))
                ->descriptionIcon($customer->isActive() ? 'heroicon-m-check-circle' : 'heroicon-m-no-symbol')
                ->color($customer->isActive() ? 'success' : 'danger'),
        ];
    }
}
