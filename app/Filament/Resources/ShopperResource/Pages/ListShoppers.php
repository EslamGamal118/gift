<?php

namespace App\Filament\Resources\ShopperResource\Pages;

use App\Filament\Resources\ShopperResource;
use App\Models\ShopperProfile;
use Closure;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListShoppers extends ListRecords
{
    protected static string $resource = ShopperResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->icon('heroicon-o-user-plus'),
        ];
    }

    /**
     * Status tabs: applications to review, active, rejected or suspended, unfinished.
     * (Closures take `$query`: Filament injects arguments by name.)
     */
    public function getTabs(): array
    {
        $pending = fn (Builder $query) => $query->where('shopper_profiles.status', ShopperProfile::STATUS_PENDING);
        $active = fn (Builder $query) => $query->where('shopper_profiles.status', ShopperProfile::STATUS_APPROVED)
            ->whereHas('user', fn (Builder $u) => $u->where('status', 'active'));
        $rejectedOrSuspended = fn (Builder $query) => $query->where(fn (Builder $w) => $w
            ->where('shopper_profiles.status', ShopperProfile::STATUS_REJECTED)
            ->orWhereHas('user', fn (Builder $u) => $u->where('status', 'blocked')));
        $draft = fn (Builder $query) => $query->where('shopper_profiles.status', ShopperProfile::STATUS_DRAFT);

        $count = fn (Closure $scope) => $scope(ShopperProfile::query())->count();

        return [
            'all' => Tab::make(__('admin.shoppers.tabs.all'))
                ->icon('heroicon-m-squares-2x2'),
            'pending' => Tab::make(__('admin.shoppers.tabs.pending'))
                ->icon('heroicon-m-clock')
                ->badge($count($pending) ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing($pending),
            'active' => Tab::make(__('admin.shoppers.tabs.active'))
                ->icon('heroicon-m-check-badge')
                ->badge($count($active) ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing($active),
            'rejected' => Tab::make(__('admin.shoppers.tabs.rejected'))
                ->icon('heroicon-m-no-symbol')
                ->badge($count($rejectedOrSuspended) ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing($rejectedOrSuspended),
            'draft' => Tab::make(__('admin.shoppers.tabs.draft'))
                ->icon('heroicon-m-pencil-square')
                ->badge($count($draft) ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing($draft),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
