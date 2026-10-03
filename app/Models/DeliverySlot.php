<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * A recurring daily delivery window (e.g. 9:00 AM - 11:00 AM, morning).
 *
 * @property int $id
 * @property string $label Translated label for the current locale
 * @property string $period morning | evening
 * @property string $start_time HH:MM:SS
 * @property string $end_time HH:MM:SS
 * @property bool $is_active
 * @property int $sort_order
 */
class DeliverySlot extends Model
{
    use HasFactory, HasTranslations;

    public const PERIOD_MORNING = 'morning';

    public const PERIOD_EVENING = 'evening';

    public const PERIODS = [self::PERIOD_MORNING, self::PERIOD_EVENING];

    /**
     * @var array<int, string>
     */
    public array $translatable = ['label'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'label',
        'period',
        'start_time',
        'end_time',
        'is_active',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('start_time');
    }

    /**
     * Start of the slot on the given day, in the store timezone.
     */
    public function startsAt(Carbon $date): Carbon
    {
        return $this->atTime($date, $this->start_time);
    }

    /**
     * End of the slot on the given day, in the store timezone.
     */
    public function endsAt(Carbon $date): Carbon
    {
        return $this->atTime($date, $this->end_time);
    }

    /**
     * Human label such as "9:00 AM - 11:00 AM" (locale-independent, used for snapshots).
     */
    public function timeRange(): string
    {
        return sprintf(
            '%s - %s',
            Carbon::createFromFormat('H:i:s', $this->start_time)->format('g:i A'),
            Carbon::createFromFormat('H:i:s', $this->end_time)->format('g:i A'),
        );
    }

    protected function atTime(Carbon $date, string $time): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $date->copy()->setTimezone(config('checkout.delivery.timezone'))->setTime($h, $m, 0);
    }
}
