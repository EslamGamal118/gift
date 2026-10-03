<?php

namespace App\Http\Resources;

use App\Models\AppNotification;
use App\Models\NotificationContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbox notification, rendered in the request's language.
 *
 *  - `type`: the inbox filter it belongs to (orders | payments | system);
 *    `event` is the precise event type, `icon` the key the app maps to an icon
 *  - `group` / `group_label`: today | yesterday | older, for the date headers
 *  - `data`: deep link payload (screen, order_id, ...)
 *
 * @mixin AppNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * Icon per event type; anything else falls back to its filter.
     */
    protected const ICONS = [
        NotificationContent::TYPE_GIFT => 'gift',
        NotificationContent::TYPE_DELIVERY_REQUEST => 'delivery',
        NotificationContent::TYPE_NEW_OFFER => 'offer',
        NotificationContent::TYPE_ACCOUNT_STATUS => 'account',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->content?->type;
        $category = NotificationContent::categoryOf($event);
        $at = $this->created_at?->copy()->timezone(config('app.timezone'))->locale(app()->getLocale());
        $group = match (true) {
            $at === null => null,
            $at->isToday() => 'today',
            $at->isYesterday() => 'yesterday',
            default => 'older',
        };

        return [
            'id' => $this->id,
            'title' => $this->translated_title,
            'body' => $this->translated_body,
            'type' => $category,
            'event' => $event,
            'icon' => self::ICONS[$event] ?? $category,
            'data' => (object) ($this->content?->data ?? []),
            'is_read' => (bool) $this->is_read,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_human' => $at?->diffForHumans(),
            'group' => $group,
            'group_label' => $group ? __('notifications.inbox.groups.'.$group) : null,
        ];
    }
}
