<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * طلب توثيق رقم جوال (رمز تحقق معلّق).
 *
 * يُنشأ عند send-otp ويُستهلك عند verify-otp، قبل إنشاء حساب المستخدم:
 * التسجيل يخزّن الاسم هنا ثم يُمرَّر إلى User بعد نجاح التحقق فقط.
 */
class PhoneVerification extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'country_code',
        'phone',
        'name',
        'otp_code',
        'expires_at',
        'verified_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'otp_code',
    ];

    /**
     * هل انتهت صلاحية الرمز؟
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * هل تم استهلاك الرمز (وبالتالي توثيق الرقم) مسبقًا؟
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * هل الرمز ما زال صالحًا للاستخدام؟
     */
    public function isPending(): bool
    {
        return ! $this->isVerified() && ! $this->isExpired();
    }

    /**
     * الرقم بالصيغة الدولية بدون (+) — نفس صيغة users.phone
     */
    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    /**
     * الطلبات المعلّقة فقط (غير مستهلكة وغير منتهية)
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('verified_at')->where('expires_at', '>', now());
    }
}
