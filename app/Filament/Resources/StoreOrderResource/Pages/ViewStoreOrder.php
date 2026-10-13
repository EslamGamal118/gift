<?php

namespace App\Filament\Resources\StoreOrderResource\Pages;

use App\Filament\Resources\StoreOrderResource;
use App\Filament\Support\OrderStatusAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One store order: customer and recipient, products with their add-ons,
 * amounts, the customer's review and the status history.
 */
class ViewStoreOrder extends ViewRecord
{
    protected static string $resource = StoreOrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['items.addons', 'gift', 'captain', 'review', 'statusHistories.actor']);
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
