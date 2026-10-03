<?php

namespace App\Http\Resources\Auth;

use App\Http\Resources\Concerns\DescribesProfileState;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * استجابة تسجيل الدخول/التسجيل بعد التحقق من الرمز.
 * تُشكَّل البيانات ديناميكيًا حسب نوع المستخدم (عميل / متجر / كابتن / متسوق).
 *
 * @mixin \App\Models\User
 */
class AuthResource extends JsonResource
{
    use DescribesProfileState;

    protected string $token;

    protected bool $isNewUser;

    protected bool $fcmTokenBound;

    public function __construct(User $user, string $token, bool $isNewUser = false, bool $fcmTokenBound = false)
    {
        parent::__construct($user);

        $this->token         = $token;
        $this->isNewUser     = $isNewUser;
        $this->fcmTokenBound = $fcmTokenBound;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user    = $this->resource;
        $profile = $user->profile();

        return [
            'is_new_user'  => $this->isNewUser,
            'account_type' => $user->user_type,
            'token'        => $this->token,
            'token_type'   => 'Bearer',
            'user'         => new UserResource($user),
            'profile'      => $this->profileResource($user, $profile),
            'flags'        => ['fcm_token_bound' => $this->fcmTokenBound] + $this->profileFlags($user, $profile),
            'next_step'    => $this->nextStep($user, $profile),
        ];
    }
}
