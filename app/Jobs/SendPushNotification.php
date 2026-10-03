<?php

namespace App\Jobs;

use App\Models\NotificationContent;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Push an already-stored notification to the recipients' devices.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  list<int>  $recipientIds
     */
    public function __construct(
        public int $contentId,
        public array $recipientIds,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $content = NotificationContent::query()->find($this->contentId);

        if (! $content || $this->recipientIds === []) {
            return;
        }

        $recipients = User::query()->whereKey($this->recipientIds)->get();

        $notifications->pushToDevices($content, $recipients);
    }
}
