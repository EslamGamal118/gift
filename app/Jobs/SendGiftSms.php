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
 * Text a paid, unclaimed gift's claim code to the recipient's phone. Retried
 * with backoff; the outcome is recorded on the gift (`sms_status`).
 */
class SendGiftSms implements ShouldQueue
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

        if (! $gift || ! $gift->isPaid() || $gift->is_claimed || ! $gift->claim_pin || $gift->sms_status === Gift::SMS_SENT) {
            return;
        }

        $gifts->sendSms($gift);
    }

    public function failed(Throwable $e): void
    {
        Gift::query()->whereKey($this->giftId)
            ->where('sms_status', '!=', Gift::SMS_SENT)
            ->update(['sms_status' => Gift::SMS_FAILED]);
    }
}
