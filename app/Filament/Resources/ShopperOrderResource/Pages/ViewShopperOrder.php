<?php

namespace App\Filament\Resources\ShopperOrderResource\Pages;

use App\Filament\Resources\ShopperOrderResource;
use App\Filament\Support\OrderStatusAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One personal shopper order: customer and recipient, delivery and pickup
 * addresses, requested items, the shopper's financial breakdown and the
 * customer's review.
 */
class ViewShopperOrder extends ViewRecord
{
    protected static string $resource = ShopperOrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['items', 'pickupAddress', 'review']);
    }

    public function getTitle(): string
    {
        return __('admin.order_management.order_title', ['number' => $this->getRecord()->order_number]);
    }

    protected function getHeaderActions(): array
    {
        return [
            OrderStatusAction::page(),
        ];
    }
}
