<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Support\Carbon;

/**
 * Order dates as the apps show them, in the request language:
 * "اليوم، 10:30 ص", "اليوم، 10:30 ص - 11:00 ص", "منذ دقيقتين".
 * For resources wrapping an Order.
 */
trait FormatsOrderTimes
{
    /**
     * "اليوم، 10:30 ص" / "أمس، 9:15 م" / "28 سبتمبر، 4:00 م".
     */
    protected function placedAtLabel(): ?string
    {
        $at = $this->localTime($this->created_at);

        return $at ? __('orders.placed_at', ['day' => $this->dayLabel($at), 'time' => $this->timeLabel($at)]) : null;
    }

    /**
     * Expected delivery: "اليوم، 10:30 ص - 11:00 ص", or the day + slot name when no window was stored.
     */
    protected function expectedTimeLabel(): ?string
    {
        $from = $this->localTime($this->delivery_window_start);
        $to = $this->localTime($this->delivery_window_end);

        if ($from && $to) {
            return __('orders.time_range', ['day' => $this->dayLabel($from), 'from' => $this->timeLabel($from), 'to' => $this->timeLabel($to)]);
        }

        $day = $this->localTime($this->delivery_date);

        return $day ? trim($this->dayLabel($day).' '.$this->delivery_slot_label) : null;
    }

    /**
     * "منذ دقيقتين" / "2 minutes ago".
     */
    protected function timeAgo(?Carbon $at): ?string
    {
        return $this->localTime($at)?->diffForHumans();
    }

    protected function localTime(?Carbon $at): ?Carbon
    {
        return $at?->copy()->timezone(config('app.timezone'))->locale(app()->getLocale());
    }

    /**
     * "اليوم" / "أمس" / "غدًا", else "28 سبتمبر".
     */
    protected function dayLabel(Carbon $at): string
    {
        return match (true) {
            $at->isToday() => __('orders.today'),
            $at->isYesterday() => __('orders.yesterday'),
            $at->isTomorrow() => __('orders.tomorrow'),
            default => $at->translatedFormat('j F'),
        };
    }

    /**
     * "10:30 ص" / "10:30 AM".
     */
    protected function timeLabel(Carbon $at): string
    {
        return $at->translatedFormat('g:i A');
    }
}
