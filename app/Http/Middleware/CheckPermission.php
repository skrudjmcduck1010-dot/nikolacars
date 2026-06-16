<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $request->user()?->hasPermission($permission)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
