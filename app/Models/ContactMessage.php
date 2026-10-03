<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "Contact us" message. `user_id` is null for guests.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property string $message
 */
class ContactMessage extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = ['user_id', 'name', 'email', 'message'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
