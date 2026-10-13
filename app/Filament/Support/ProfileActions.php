<?php

namespace App\Filament\Support;

use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Services\ProfileModerationService;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;

/**
 * Approve / reject / suspend / reactivate a store or a personal shopper.
 * Used in the tables and on the record pages, so each builder takes the action
 * class to create (Filament\Tables\Actions\Action or Filament\Actions\Action),
 * and the translation group of the resource (`stores` or `shoppers`).
 */
class ProfileActions
{
    protected static function name(StoreProfile|ShopperProfile $profile): string
    {
        return $profile instanceof StoreProfile ? (string) $profile->store_name : (string) $profile->user?->name;
    }

    /**
     * @param  class-string<TableAction|PageAction>  $class
     */
    public static function approve(string $class, string $group): TableAction|PageAction
    {
        return $class::make('approve')
            ->label(__("admin.$group.actions.approve"))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (StoreProfile|ShopperProfile $record) => ! $record->trashed() && $record->status !== 'approved')
            ->requiresConfirmation()
            ->modalHeading(fn (StoreProfile|ShopperProfile $record) => __("admin.$group.actions.approve_heading", ['name' => self::name($record)]))
            ->modalDescription(__("admin.$group.actions.approve_description"))
            ->action(function (StoreProfile|ShopperProfile $record) use ($group): void {
                app(ProfileModerationService::class)->approve($record);
                Notification::make()->title(__("admin.$group.actions.approved"))->success()->send();
            });
    }

    /**
     * @param  class-string<TableAction|PageAction>  $class
     */
    public static function reject(string $class, string $group): TableAction|PageAction
    {
        return $class::make('reject')
            ->label(__("admin.$group.actions.reject"))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (StoreProfile|ShopperProfile $record) => ! $record->trashed() && in_array($record->status, ['pending', 'draft', 'approved'], true))
            ->modalHeading(fn (StoreProfile|ShopperProfile $record) => __("admin.$group.actions.reject_heading", ['name' => self::name($record)]))
            ->modalSubmitActionLabel(__("admin.$group.actions.reject"))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__("admin.$group.fields.rejection_reason"))
                    ->helperText(__("admin.$group.actions.reason_shown_to_owner"))
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (StoreProfile|ShopperProfile $record, array $data) use ($group): void {
                app(ProfileModerationService::class)->reject($record, $data['reason']);
                Notification::make()->title(__("admin.$group.actions.rejected"))->success()->send();
            });
    }

    /**
     * @param  class-string<TableAction|PageAction>  $class
     */
    public static function suspend(string $class, string $group): TableAction|PageAction
    {
        return $class::make('suspend')
            ->label(__("admin.$group.actions.suspend"))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (StoreProfile|ShopperProfile $record) => ! $record->trashed() && ! $record->isSuspended())
            ->modalHeading(fn (StoreProfile|ShopperProfile $record) => __("admin.$group.actions.suspend_heading", ['name' => self::name($record)]))
            ->modalDescription(__("admin.$group.actions.suspend_description"))
            ->modalSubmitActionLabel(__("admin.$group.actions.suspend"))
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__("admin.$group.fields.suspension_reason"))
                    ->helperText(__("admin.$group.actions.reason_shown_to_owner"))
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (StoreProfile|ShopperProfile $record, array $data) use ($group): void {
                app(ProfileModerationService::class)->suspend($record, $data['reason']);
                Notification::make()->title(__("admin.$group.actions.suspended"))->success()->send();
            });
    }

    /**
     * @param  class-string<TableAction|PageAction>  $class
     */
    public static function reactivate(string $class, string $group): TableAction|PageAction
    {
        return $class::make('reactivate')
            ->label(__("admin.$group.actions.reactivate"))
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->visible(fn (StoreProfile|ShopperProfile $record) => ! $record->trashed() && $record->isSuspended())
            ->requiresConfirmation()
            ->modalDescription(__("admin.$group.actions.reactivate_description"))
            ->action(function (StoreProfile|ShopperProfile $record) use ($group): void {
                app(ProfileModerationService::class)->reactivate($record);
                Notification::make()->title(__("admin.$group.actions.reactivated"))->success()->send();
            });
    }
}
