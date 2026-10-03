<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_STORE = 'store';

    public const TYPE_CAPTAIN = 'captain';

    public const TYPE_SHOPPER = 'shopper';

    public const TYPE_ADMIN = 'admin';

    public const REGISTRABLE_TYPES = [
        self::TYPE_CUSTOMER,
        self::TYPE_STORE,
        self::TYPE_CAPTAIN,
        self::TYPE_SHOPPER,
    ];

    public const ROLE_ALIASES = [
        'client' => self::TYPE_CUSTOMER,
        'customer' => self::TYPE_CUSTOMER,
        'store' => self::TYPE_STORE,
        'merchant' => self::TYPE_STORE,
        'captain' => self::TYPE_CAPTAIN,
        'delivery_captain' => self::TYPE_CAPTAIN,
        'shopper' => self::TYPE_SHOPPER,
        'personal_shopper' => self::TYPE_SHOPPER,
    ];

    public const PROFILE_RELATIONS = [
        self::TYPE_STORE => 'storeProfile',
        self::TYPE_CAPTAIN => 'captainProfile',
        self::TYPE_SHOPPER => 'shopperProfile',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'avatar',
        'locale',
        'user_type',
        'status',
        'phone_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'phone_verified_at' => 'datetime',
    ];

    /**
     * بروفايل المتجر
     */
    public function storeProfile(): HasOne
    {
        return $this->hasOne(StoreProfile::class);
    }

    /**
     * بروفايل الكابتن
     */
    public function captainProfile(): HasOne
    {
        return $this->hasOne(CaptainProfile::class);
    }

    /**
     * بروفايل المتسوق
     */
    public function shopperProfile(): HasOne
    {
        return $this->hasOne(ShopperProfile::class);
    }

    /**
     * فروع المتجر التابعة للمستخدم
     */
    public function storeBranches(): HasManyThrough
    {
        return $this->hasManyThrough(StoreBranch::class, StoreProfile::class);
    }

    /**
     * Products offered by this merchant account.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'store_id');
    }

    /**
     * Add-ons offered by this merchant account.
     */
    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class, 'store_id');
    }

    /**
     * Saved delivery addresses (customer accounts).
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * The customer's active cart (created lazily by CartService).
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * Orders placed by this customer.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Online gifts this customer bought for others.
     */
    public function sentGifts(): HasMany
    {
        return $this->hasMany(Gift::class, 'sender_id');
    }

    /**
     * Online gifts attached to this account (as recipient).
     */
    public function receivedGifts(): HasMany
    {
        return $this->hasMany(Gift::class, 'recipient_id');
    }

    /**
     * Orders this captain is delivering.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Order::class, 'captain_id');
    }

    /**
     * Custom (personal shopper) orders placed by this customer.
     */
    public function customOrders(): HasMany
    {
        return $this->hasMany(CustomOrder::class);
    }

    /**
     * Custom orders this personal shopper is handling.
     */
    public function shopperCustomOrders(): HasMany
    {
        return $this->hasMany(CustomOrder::class, 'shopper_id');
    }

    /**
     * Store reviews written by this customer.
     */
    public function storeReviews(): HasMany
    {
        return $this->hasMany(StoreReview::class);
    }

    /**
     * In-app notifications delivered to this account.
     */
    public function appNotifications(): MorphMany
    {
        return $this->morphMany(AppNotification::class, 'notifiable')->latest('id');
    }

    /**
     * The language this account reads notifications in (null = app default).
     */
    public function preferredLocale(): ?string
    {
        return is_string($this->locale) && $this->locale !== '' ? $this->locale : null;
    }

    /**
     * Orders received by this merchant account.
     */
    public function storeOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'store_id');
    }

    /**
     * Stores and products this user marked as favorite.
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Recent search keywords of this user.
     */
    public function searchHistories(): HasMany
    {
        return $this->hasMany(SearchHistory::class);
    }

    /**
     * طلبات توثيق رقم جوال المستخدم (رموز التحقق)
     */
    public function phoneVerifications(): HasMany
    {
        return $this->hasMany(PhoneVerification::class, 'phone', 'phone');
    }

    /**
     * رموز التحقق القديمة المرسلة لرقم جوال المستخدم
     *
     * @deprecated استُبدل جدول otps بـ phone_verifications — انظر phoneVerifications()
     */
    public function otps(): HasMany
    {
        return $this->hasMany(Otp::class, 'phone', 'phone');
    }

    /**
     * توكنات الإشعارات (FCM) لأجهزة هذا الحساب
     */
    public function fcmTokens(): HasMany
    {
        return $this->hasMany(FcmToken::class);
    }

    /**
     * تحويل الدور القادم من الـ API (client / personal_shopper / ...) إلى القيمة المخزّنة،
     * أو null إن كان غير معروف.
     */
    public static function normalizeRole(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }

        return self::ROLE_ALIASES[strtolower(trim($role))] ?? null;
    }

    /**
     * البحث عن حساب بعينه لرقم جوال ودور محددين
     */
    public function scopeForPhoneAndRole($query, string $phone, string $role)
    {
        return $query->where('phone', $phone)->where('user_type', $role);
    }

    /**
     * ربط توكن FCM بهذا الحساب فور تسجيل الدخول.
     * التوكن الواحد يرتبط بحساب واحد فقط (يُنقل إن كان مرتبطًا بحساب آخر)،
     * والجهاز الواحد (device_id) يحمل توكنًا واحدًا فقط.
     */
    public function registerFcmToken(string $token, ?string $deviceType = null, ?string $deviceId = null): FcmToken
    {
        if ($deviceId) {
            FcmToken::where('device_id', $deviceId)
                ->where('token', '!=', $token)
                ->delete();
        }

        return FcmToken::updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $this->id,
                'device_type' => $deviceType,
                'device_id' => $deviceId,
            ]
        );
    }

    /**
     * اسم علاقة البروفايل الخاصة بنوع هذا المستخدم (إن وُجدت)
     */
    public function profileRelation(): ?string
    {
        return self::PROFILE_RELATIONS[$this->user_type] ?? null;
    }

    /**
     * البروفايل الخاص بنوع المستخدم (متجر / كابتن / متسوق) أو null
     */
    public function profile(): StoreProfile|CaptainProfile|ShopperProfile|null
    {
        $relation = $this->profileRelation();

        return $relation ? $this->{$relation} : null;
    }

    /**
     * هل يحتاج هذا النوع من المستخدمين إلى بروفايل قبل مباشرة العمل؟
     */
    public function requiresProfile(): bool
    {
        return $this->profileRelation() !== null;
    }

    public function isCustomer(): bool
    {
        return $this->user_type === 'customer';
    }

    public function isStore(): bool
    {
        return $this->user_type === 'store';
    }

    public function isCaptain(): bool
    {
        return $this->user_type === 'captain';
    }

    public function isShopper(): bool
    {
        return $this->user_type === 'shopper';
    }

    public function isAdmin(): bool
    {
        return $this->user_type === 'admin';
    }

    /**
     * هل الحساب مفعّل؟
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * هل تم توثيق رقم الجوال؟
     */
    public function hasVerifiedPhone(): bool
    {
        return ! is_null($this->phone_verified_at);
    }

    /**
     * المستخدمون النشطون فقط
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * تصفية حسب نوع المستخدم
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('user_type', $type);
    }
}
