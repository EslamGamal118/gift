<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use App\Models\StoreProfile;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Status tabs: waiting for review, live, rejected or suspended, unfinished.
     */
    public function getTabs(): array
    {
        $pending = fn (Builder $query) => $query->where('status', StoreProfile::STATUS_PENDING);
        $active = fn (Builder $query) => $query->where('status', StoreProfile::STATUS_APPROVED)
            ->whereHas('user', fn (Builder $u) => $u->where('status', 'active'));
        $rejectedOrSuspended = fn (Builder $query) => $query->where(fn (Builder $w) => $w
            ->where('status', StoreProfile::STATUS_REJECTED)
            ->orWhereHas('user', fn (Builder $u) => $u->where('status', 'blocked')));
        $draft = fn (Builder $query) => $query->where('status', StoreProfile::STATUS_DRAFT);

        $count = fn (\Closure $scope) => $scope(StoreProfile::query())->count();

        return [
            'all' => Tab::make(__('admin.stores.tabs.all'))
                ->icon('heroicon-m-squares-2x2'),
            'pending' => Tab::make(__('admin.stores.tabs.pending'))
                ->icon('heroicon-m-clock')
                ->badge($count($pending) ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing($pending),
            'active' => Tab::make(__('admin.stores.tabs.active'))
                ->icon('heroicon-m-check-badge')
                ->badge($count($active) ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing($active),
            'rejected' => Tab::make(__('admin.stores.tabs.rejected'))
                ->icon('heroicon-m-no-symbol')
                ->badge($count($rejectedOrSuspended) ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing($rejectedOrSuspended),
            'draft' => Tab::make(__('admin.stores.tabs.draft'))
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
