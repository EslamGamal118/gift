<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One notification sitting in one recipient's inbox.
 *
 * Carries no text: `translated_title` and `translated_body` render the content
 * row's keys against the locale in force at the moment they are read, which is
 * what makes switching language re-render the entire history rather than only
 * the notifications that arrive afterwards.
 *
 * @property-read string $translated_title
 * @property-read string $translated_body
 */
class AppNotification extends Model
{
    use HasFactory;

    /**
     * A notification without its content is an empty row, and the translated
     * accessors below read straight through the relation. Loading it eagerly by
     * default is what stops a twenty item inbox becoming twenty-one queries.
     *
     * @var array<int, string>
     */
    protected $with = ['content'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'notification_content_id',
        'notifiable_type',
        'notifiable_id',
        'is_read',
        'read_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    /**
     * Keep `is_read` and `read_at` from drifting apart.
     *
     * The two columns encode the same fact, so any row where one says read and
     * the other says unread is a row no query can be trusted on. Saving through
     * the model reconciles them; a mass update cannot fire this, which is why
     * markAllAsReadFor() exists rather than a bare update(['is_read' => true]).
     */
    protected static function booted(): void
    {
        static::saving(function (self $notification): void {
            if ($notification->is_read && $notification->read_at === null) {
                $notification->read_at = now();
            }

            if (! $notification->is_read) {
                $notification->read_at = null;
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The translatable text this delivery points at.
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(NotificationContent::class, 'notification_content_id');
    }

    /**
     * The Client, Provider or Admin this notification was delivered to.
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function scopeRead(Builder $query): Builder
    {
        return $query->where('is_read', true);
    }

    /**
     * The inbox of one recipient.
     */
    public function scopeFor(Builder $query, Model $notifiable): Builder
    {
        return $query->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey());
    }

    /**
     * One inbox filter (NotificationContent::CATEGORIES): orders, payments, or
     * system = every type the other two do not list.
     */
    public function scopeInCategory(Builder $query, string $category): Builder
    {
        $listed = NotificationContent::CATEGORY_TYPES;

        return $query->whereHas('content', fn (Builder $content) => $category === NotificationContent::CATEGORY_SYSTEM
            ? $content->where(fn (Builder $q) => $q->whereNull('type')->orWhereNotIn('type', array_merge(...array_values($listed))))
            : $content->whereIn('type', $listed[$category] ?? []));
    }

    /*
    |--------------------------------------------------------------------------
    | Translated text
    |--------------------------------------------------------------------------
    */

    /**
     * The title, rendered in the locale that is current right now.
     *
     * Deliberately not ->shouldCache(): caching would pin the string to the
     * first locale it was read in, which is precisely the bug this whole design
     * exists to avoid.
     */
    protected function translatedTitle(): Attribute
    {
        return Attribute::get(fn (): string => $this->content?->renderTitle() ?? '');
    }

    protected function translatedBody(): Attribute
    {
        return Attribute::get(fn (): string => $this->content?->renderBody() ?? '');
    }

    /**
     * Mark this notification read, both columns at once.
     */
    public function markAsRead(): bool
    {
        if ($this->is_read) {
            return true;
        }

        $this->is_read = true;

        return $this->save();
    }

    public function markAsUnread(): bool
    {
        if (! $this->is_read) {
            return true;
        }

        $this->is_read = false;

        return $this->save();
    }

    /**
     * Mark a whole inbox read in one statement.
     *
     * A mass update skips model events, so the saving() hook above cannot help
     * here and both columns have to be written explicitly. Doing it any other
     * way is what leaves rows with is_read = 1 and read_at = null.
     */
    public static function markAllAsReadFor(Model $notifiable): int
    {
        return static::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }
}
