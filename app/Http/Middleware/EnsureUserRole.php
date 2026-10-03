<?php

namespace App\Http\Middleware;

use App\Exceptions\ProfileStepException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to one or more account types, e.g. `role:store` or `role:captain,shopper`.
 * Role aliases used by the API (client, personal_shopper, ...) are accepted.
 */
class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_filter(array_map([User::class, 'normalizeRole'], $roles));

        $user = $request->user();

        if (! $user || ! in_array($user->user_type, $allowed, true)) {
            throw ProfileStepException::roleNotAllowed($user?->user_type ?? 'guest');
        }

        return $next($request);
    }
}
