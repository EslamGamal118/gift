<?php

namespace App\Jobs;

use App\Models\Gift;
use App\Services\GiftService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Deliver a paid gift to its recipient over WhatsApp. Retried with backoff;
 * the outcome is recorded on the gift (`whatsapp_status`).
 */
class SendGiftWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public int $giftId) {}

    public function handle(GiftService $gifts): void
    {
        $gift = Gift::query()->find($this->giftId);

        if (! $gift || ! $gift->isPaid() || $gift->whatsapp_status === Gift::WHATSAPP_SENT) {
            return;
        }

        $gifts->sendWhatsApp($gift);
    }

    public function failed(Throwable $e): void
    {
        Gift::query()->whereKey($this->giftId)
            ->where('whatsapp_status', '!=', Gift::WHATSAPP_SENT)
            ->update(['whatsapp_status' => Gift::WHATSAPP_FAILED, 'whatsapp_error' => mb_substr($e->getMessage(), 0, 250)]);
    }
}
