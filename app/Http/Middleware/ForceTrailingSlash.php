<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceTrailingSlash
{
    public function handle(Request $request, Closure $next)
    {
        // Не трогаем POST/PUT/PATCH/DELETE, чтобы не ломать формы
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $uri = $request->getRequestUri();                 // например: "/ru/services?x=1"
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';     // например: "/ru/services"
        $query = parse_url($uri, PHP_URL_QUERY);          // например: "x=1"

        // Главную не трогаем
        if ($path === '/') {
            return $next($request);
        }

        // Не трогаем файлы типа .css .js .png
        if (str_contains($path, '.')) {
            return $next($request);
        }

        // Если нет конечного слеша — редиректим
        if (!str_ends_with($path, '/')) {
            $to = $path . '/';
            if (!empty($query)) $to .= '?' . $query;
            return redirect($to, 301);
        }

        return $next($request);
    }
}
