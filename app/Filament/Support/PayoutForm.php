<?php

namespace App\Filament\Support;

use App\Models\StorePayout;
use Closure;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use RuntimeException;

/**
 * The "process payout" modal shared by stores and personal shoppers: how the
 * money was sent. The amount is never typed: it is what the settled orders are
 * worth to the payee (see StorePayoutService / ShopperPayoutService).
 */
class PayoutForm
{
    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('method')
                    ->label(__('admin.payouts.method'))
                    ->options(self::methodOptions())
                    ->default(StorePayout::METHOD_BANK_TRANSFER)
                    ->required(),
                Forms\Components\DateTimePicker::make('transferred_at')
                    ->label(__('admin.payouts.transferred_at'))
                    ->default(now())
                    ->maxDate(now()->endOfDay())
                    ->seconds(false)
                    ->required(),
                Forms\Components\TextInput::make('reference')
                    ->label(__('admin.payouts.reference'))
                    ->helperText(__('admin.payouts.reference_hint'))
                    ->maxLength(100)
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('receipt_path')
                    ->label(__('admin.payouts.receipt'))
                    ->disk('public')
                    ->directory('payouts/receipts')
                    ->acceptedFileTypes(['application/pdf', 'image/*'])
                    ->maxSize(5120)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('notes')
                    ->label(__('admin.payouts.notes'))
                    ->rows(2)
                    ->columnSpanFull(),
            ]),
        ];
    }

    /**
     * Payout methods (the same for stores and shoppers).
     *
     * @return array<string, string>
     */
    public static function methodOptions(): array
    {
        return collect(StorePayout::METHODS)->mapWithKeys(fn (string $m) => [$m => __("admin.payouts.methods.$m")])->all();
    }

    /**
     * Run a payout and tell the admin (and the page widgets) how it went.
     *
     * @param  Closure(): Model  $run  creates the payout (amount, orders_count), or throws RuntimeException
     */
    public static function process(Component $livewire, Closure $run): void
    {
        try {
            $payout = $run();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title(__('admin.payouts.done', [
                'amount' => number_format((float) $payout->amount, 2),
                'count' => $payout->orders_count,
            ]))
            ->success()
            ->send();

        $livewire->dispatch('finances-updated');
    }
}
