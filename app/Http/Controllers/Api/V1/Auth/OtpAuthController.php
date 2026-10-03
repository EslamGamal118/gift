<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Profile\Concerns\LoadsProfileRelations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\OtpService;
use App\Support\AuthAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Unified OTP authentication for every account type (client / store / captain / personal shopper).
 *
 * One mobile number may own a separate account per role, so an account is keyed by
 * (phone, user_type). The client states its intent explicitly on both endpoints:
 *
 *  - action=register : the (phone, user_type) account must NOT exist yet. The name travels
 *                      with send-otp, is stored on the phone_verifications row, and the user
 *                      record is created only after the code has been verified.
 *  - action=login    : the (phone, user_type) account must already exist; the code only
 *                      authenticates it.
 */
class OtpAuthController extends Controller
{
    use LoadsProfileRelations;

    public function __construct(
        protected OtpService $otpService,
    ) {
    }

    /**
     * POST /api/v1/auth/send-otp
     *
     * Enforces the login/register separation up front, then generates an OTP for the mobile
     * number and sends it via 4Jawaly (fixed to 1234 and not dispatched in testing environments).
     * The pending verification row keeps the name supplied for registration.
     */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $mobile = $request->mobile();
        $role   = $request->role();

        $accountExists = User::forPhoneAndRole($mobile, $role)->exists();

        if ($rejection = $this->guardAction($request->action(), $accountExists, $role)) {
            return $rejection;
        }

        $verification = $this->otpService->send(
            $mobile,
            $request->action(),
            $request->name(),
            $request->countryCode(),
        );

        // Roles already registered for this number, so the app can offer "log in as ...".
        // Sorted in PHP: MySQL orders an ENUM column by its ordinal, not alphabetically.
        $registeredRoles = User::where('phone', $mobile)
            ->whereIn('user_type', User::REGISTRABLE_TYPES)
            ->pluck('user_type')
            ->sort()
            ->values();

        $data = [
            'mobile'           => $mobile,
            'country_code'     => $verification->country_code,
            'action'           => $request->action(),
            'user_type'        => $role,
            'is_registered'    => $registeredRoles->isNotEmpty(),
            'registered_roles' => $registeredRoles,
            'expires_in'       => $this->otpService->expiresInMinutes() * 60,
            'resend_after'     => $this->otpService->resendCooldownSeconds(),
        ];

        // Echo the code in testing environments to simplify manual testing.
        if ($this->otpService->isTestingEnvironment()) {
            $data['otp'] = $verification->otp_code;
        }

        return ApiResponse::success('auth.otp_sent', $data);
    }

    /**
     * POST /api/v1/auth/verify-otp
     *
     * 1) Re-checks the requested action against the (mobile, role) account, before spending the OTP.
     * 2) Verifies and consumes the OTP (single use; replays are rejected) - the phone is now verified.
     * 3) register: creates the account with the name captured at send-otp.
     *    login:    updates the existing account.
     * 4) Binds the FCM token and issues a Sanctum token.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $mobile = $request->mobile();
        $role   = $request->role();

        $user = User::forPhoneAndRole($mobile, $role)->first();

        if ($rejection = $this->guardAction($request->action(), $user !== null, $role)) {
            return $rejection;
        }

        // Throws OtpException (invalid / already used / expired / too many attempts),
        // rendered by the exception handler as a unified ApiResponse.
        $verification = $this->otpService->verify($mobile, $request->otp());

        $isNewUser = false;

        if ($request->isRegistration()) {
            // The phone is verified at this point, so the account may finally be created.
            [$user, $isNewUser] = $this->registerAccount(
                $mobile,
                $role,
                $this->resolveName($request, $verification),
                $verification,
            );
        }

        if (! $user->isActive()) {
            return ApiResponse::error('auth.account_blocked', ['account_type' => $role], 403);
        }

        $token = $this->login($user, $request, $verification, $isNewUser);

        $this->loadProfileFor($user);

        return ApiResponse::success(
            $isNewUser ? 'auth.registered' : 'auth.logged_in',
            new AuthResource($user, $token, $isNewUser, $request->filled('fcm_token'))
        );
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revokes the Sanctum token used for this request; other devices stay logged in.
     * An optional `fcm_token` unbinds the device from push notifications.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($fcmToken = $request->input('fcm_token')) {
            $user->fcmTokens()->where('token', $fcmToken)->delete();
        }

        $user->currentAccessToken()?->delete();

        return ApiResponse::success('auth.logged_out');
    }

    /**
     * Reject a request whose action contradicts the state of the (mobile, role) account.
     *
     * The payload carries the action the client should switch to, so the app can route the
     * user without a second round trip.
     */
    protected function guardAction(string $action, bool $accountExists, string $role): ?JsonResponse
    {
        if ($action === AuthAction::REGISTER && $accountExists) {
            return ApiResponse::error(
                'auth.account_exists',
                ['account_type' => $role, 'action' => AuthAction::LOGIN],
                409
            );
        }

        if ($action === AuthAction::LOGIN && ! $accountExists) {
            return ApiResponse::error(
                'auth.account_not_found',
                ['account_type' => $role, 'action' => AuthAction::REGISTER],
                404
            );
        }

        return null;
    }

    /**
     * The name stored on the verified phone_verifications row wins: it is the one that travelled
     * with the code that was just proven. A name sent with verify-otp is only a fallback.
     */
    protected function resolveName(VerifyOtpRequest $request, PhoneVerification $verification): ?string
    {
        return $verification->name ?: $request->name();
    }

    /**
     * Create the (mobile, role) account exactly once, however many requests race.
     *
     * - lockForUpdate inside the transaction serializes concurrent requests for the same key.
     * - The (phone, user_type) unique index is the last line of defence: if another request
     *   inserted first, the violation is caught and the existing row is reused (the number was
     *   verified either way, so the caller is logged into that account instead of failing).
     * - The transaction is retried up to 3 times on deadlock.
     *
     * @return array{0: User, 1: bool}  [account, created now?]
     */
    protected function registerAccount(string $mobile, string $role, ?string $name, PhoneVerification $verification): array
    {
        return DB::transaction(function () use ($mobile, $role, $name, $verification) {
            $user = User::forPhoneAndRole($mobile, $role)->lockForUpdate()->first();

            if ($user) {
                return [$user, false];
            }

            try {
                $user = User::create([
                    'phone'             => $mobile,
                    'name'              => $name,
                    'user_type'         => $role,
                    'status'            => 'active',
                    'phone_verified_at' => $verification->verified_at ?? now(),
                ]);

                return [$user, true];
            } catch (UniqueConstraintViolationException) {
                return [User::forPhoneAndRole($mobile, $role)->lockForUpdate()->firstOrFail(), false];
            }
        }, attempts: 3);
    }

    /**
     * Update the existing account, bind the FCM token and issue a fresh Sanctum token.
     */
    protected function login(User $user, VerifyOtpRequest $request, PhoneVerification $verification, bool $isNewUser): string
    {
        return DB::transaction(function () use ($user, $request, $verification, $isNewUser) {
            if (! $isNewUser) {
                $updates = ['phone_verified_at' => $user->phone_verified_at ?? $verification->verified_at ?? now()];

                if ($name = $this->resolveName($request, $verification)) {
                    $updates['name'] = $name;
                }

                $user->forceFill($updates)->save();
            }

            if ($fcmToken = $request->validated('fcm_token')) {
                $user->registerFcmToken(
                    $fcmToken,
                    $request->validated('device_type'),
                    $request->validated('device_id'),
                );
            }

            return $user->createToken($request->validated('device_name') ?? 'mobile')->plainTextToken;
        });
    }
}
