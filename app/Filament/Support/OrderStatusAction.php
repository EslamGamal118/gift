<?php

namespace App\Filament\Support;

use App\Exceptions\CustomOrderException;
use App\Exceptions\OrderStateException;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Services\AdminOrderStatusService;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Model;

/**
 * "Update status" for store and personal shopper orders, as a table row
 * action or a page header action. Only the statuses the admin may set from
 * the order's current one are offered (AdminOrderStatusService); the
 * customer is notified of the change.
 */
class OrderStatusAction
{
    public static function table(): TableAction
    {
        return self::configure(TableAction::make('updateStatus'));
    }

    public static function page(): PageAction
    {
        return self::configure(PageAction::make('updateStatus'));
    }

    /**
     * @return array<string, string>  status => label
     */
    public static function options(Model $order): array
    {
        $service = app(AdminOrderStatusService::class);
        $custom = $order instanceof CustomOrder;
        $targets = $custom ? $service->customTargets($order) : $service->storeTargets($order);

        return collect($targets)->mapWithKeys(fn (string $status) => [
            $status => __(($custom ? 'admin.order_management.custom_targets.' : 'admin.order_management.store_targets.').$status),
        ])->all();
    }

    /**
     * @template T of TableAction|PageAction
     *
     * @param  T  $action
     * @return T
     */
    protected static function configure(TableAction|PageAction $action): TableAction|PageAction
    {
        return $action
            ->label(__('admin.order_management.update_status'))
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->visible(fn (Model $record) => self::options($record) !== [])
            ->modalHeading(fn (Model $record) => __('admin.order_management.update_status_heading', ['number' => $record->order_number]))
            ->modalDescription(__('admin.order_management.update_status_hint'))
            ->modalSubmitActionLabel(__('admin.order_management.update_status_submit'))
            ->modalWidth('lg')
            ->form(fn (Model $record) => [
                Forms\Components\Placeholder::make('current_status')
                    ->label(__('admin.order_management.current_status'))
                    ->content($record instanceof CustomOrder
                        ? OrderDisplay::customStatusLabel($record->status)
                        : OrderDisplay::statusLabel($record->status)),
                Forms\Components\Select::make('status')
                    ->label(__('admin.order_management.new_status'))
                    ->options(self::options($record))
                    ->required()
                    ->native(false)
                    ->live(),
                Forms\Components\Textarea::make('reason')
                    ->label(__('admin.order_management.cancellation_reason'))
                    ->helperText(fn () => $record instanceof Order && $record->isPaid()
                        ? __('admin.order_management.refund_hint')
                        : null)
                    ->rows(3)
                    ->maxLength(500)
                    ->required(fn (Forms\Get $get) => in_array($get('status'), [Order::STATUS_CANCELLED, CustomOrder::STATUS_CANCELLED], true))
                    ->visible(fn (Forms\Get $get) => in_array($get('status'), [Order::STATUS_CANCELLED, CustomOrder::STATUS_CANCELLED], true)),
            ])
            ->action(function (Model $record, array $data): void {
                $service = app(AdminOrderStatusService::class);
                $label = self::options($record)[$data['status']] ?? $data['status'];

                try {
                    $record instanceof CustomOrder
                        ? $service->updateCustomOrder($record, $data['status'], auth()->user(), $data['reason'] ?? null)
                        : $service->updateStoreOrder($record, $data['status'], auth()->user(), $data['reason'] ?? null);
                } catch (OrderStateException|CustomOrderException) {
                    // The order moved meanwhile (the store, shopper or delivery company)
                    Notification::make()
                        ->title(__('admin.order_management.not_allowed'))
                        ->danger()
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->title(__('admin.order_management.updated', ['status' => $label]))
                    ->body(__('admin.order_management.customer_notified'))
                    ->success()
                    ->send();
            });
    }
}
