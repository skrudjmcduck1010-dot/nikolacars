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

        // Reviewed UA aliases only; resolve before slash normalization to avoid two hops.
        $batteryAliases = [
            '/parts/vysokovoltnaia-batareia' => '/parts/visokovoltna-batareia/',
            '/parts/model-3-06-2017-12-2023/vysokovoltnaia-batareia' => '/parts/model-3-06-2017-12-2023/visokovoltna-batareia/',
        ];
        $aliasPath = str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
        if (isset($batteryAliases[$aliasPath])) {
            $to = $batteryAliases[$aliasPath];
            if ($query !== null && $query !== '') $to .= '?' . $query;
            return redirect()->away($request->getSchemeAndHttpHost() . $to, 301);
        }

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
            return redirect()->away($request->getSchemeAndHttpHost() . $to, 301);
        }

        return $next($request);
    }
}
