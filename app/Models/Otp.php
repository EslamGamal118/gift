<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated مسار المصادقة صار يستخدم App\Models\PhoneVerification (جدول phone_verifications).
 *             يبقى هذا النموذج للقراءة من السجلات القديمة فقط.
 */
class Otp extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone',
        'code',
        'type',
        'is_used',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_used' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * المستخدم المرتبط برقم الجوال (إن وُجد)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'phone', 'phone');
    }

    /**
     * هل انتهت صلاحية الرمز؟
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * هل الرمز صالح للاستخدام؟
     */
    public function isValid(): bool
    {
        return ! $this->is_used && ! $this->isExpired();
    }

    /**
     * الرموز الصالحة فقط
     */
    public function scopeValid($query)
    {
        return $query->where('is_used', false)->where('expires_at', '>', now());
    }
}
