<?php

namespace App\Http\Requests\Profile;

use App\Exceptions\ProfileLockedException;
use App\Exceptions\ProfileStepException;
use App\Models\CaptainProfile;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * الأساس المشترك لخطوات إكمال البروفايل التي تلي "البيانات الأساسية":
 *  - الدور يجب أن يكون ضمن الأدوار المسموح لها بهذه الخطوة (403)
 *  - يجب أن يكون البروفايل قد أُنشئ في الخطوة الأولى (409)
 *  - البروفايل قيد المراجعة/المعتمد لا يُعدَّل (409)
 *
 * كل ذلك يُفحص قبل التحقق من الحقول حتى لا تُربك رسائل الحقول المستخدم.
 */
abstract class ProfileStepRequest extends FormRequest
{
    public const MAX_IMAGE_KB    = 2048;
    public const MAX_COVER_KB    = 4096;
    public const MAX_DOCUMENT_KB = 5120;

    protected const IMAGE_RULES    = ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KB];
    protected const DOCUMENT_RULES = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::MAX_DOCUMENT_KB];

    /**
     * الأدوار المسموح لها بهذه الخطوة
     *
     * @var array<int, string>
     */
    protected array $allowedRoles = [];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if (! in_array($user->user_type, $this->allowedRoles, true)) {
            throw ProfileStepException::roleNotAllowed($user->user_type);
        }

        $profile = $this->existingProfile();

        if (! $profile) {
            throw ProfileStepException::basicInfoRequired();
        }

        if (in_array($profile->status, ['pending', 'approved'], true)) {
            throw ProfileLockedException::forStatus($profile->status);
        }

        $this->prepareStep();
    }

    /**
     * تهيئة/توحيد مدخلات الخطوة قبل التحقق (اختياري في الأصناف الفرعية)
     */
    protected function prepareStep(): void
    {
    }

    /**
     * قاعدة `account_type` الاختيارية: إن أُرسلت فيجب أن تطابق دور الحساب الحالي
     *
     * @return array<int, mixed>
     */
    protected function accountTypeRule(): array
    {
        $aliases = array_keys(array_filter(
            User::ROLE_ALIASES,
            fn (string $role) => $role === $this->user()->user_type
        ));

        return ['nullable', 'string', Rule::in($aliases)];
    }

    public function existingProfile(): StoreProfile|CaptainProfile|ShopperProfile|null
    {
        return $this->user()?->profile();
    }

    public function profile(): StoreProfile|CaptainProfile|ShopperProfile
    {
        return $this->existingProfile() ?? throw ProfileStepException::basicInfoRequired();
    }

    public function messages(): array
    {
        return [
            'account_type.in' => __('profile.account_type_mismatch', ['registered_as' => $this->user()->user_type]),
        ];
    }
}
