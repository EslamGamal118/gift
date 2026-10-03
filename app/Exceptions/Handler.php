<?php

namespace App\Exceptions;

use App\Helpers\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // استجابات API موحّدة (نفس بنية ApiResponse) لطلبات /api/*

        $this->renderable(function (ApiException $e, Request $request) {
            if ($this->wantsApiResponse($request)) {
                return ApiResponse::send($e->getStatusCode(), $e->getMessageKey(), $e->getData(), $e->getReplace());
            }
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if ($this->wantsApiResponse($request)) {
                return ApiResponse::send(422, 'messages.validation_error', ['errors' => $e->errors()]);
            }
        });

        $this->renderable(function (AuthenticationException $e, Request $request) {
            if ($this->wantsApiResponse($request)) {
                return ApiResponse::send(401, 'auth.unauthenticated');
            }
        });

        $this->renderable(function (ThrottleRequestsException $e, Request $request) {
            if ($this->wantsApiResponse($request)) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

                return ApiResponse::send(429, 'auth.too_many_requests', $retryAfter ? ['retry_after' => (int) $retryAfter] : null);
            }
        });

        $this->renderable(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($this->wantsApiResponse($request)) {
                return ApiResponse::send(404, 'messages.not_found');
            }
        });
    }

    protected function wantsApiResponse(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
