<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForbidDuringImpersonation
{
    public function handle(Request $request, Closure $next, ?string $redirectTo = null): Response
    {
        if ($request->attributes->has('impersonation')) {
            if ($redirectTo !== null) {
                return redirect($redirectTo);
            }

            abort(403);
        }

        return $next($request);
    }
}
