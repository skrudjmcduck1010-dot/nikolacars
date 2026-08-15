<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;

class SiteController extends Controller
{
    public function show(Request $request, ?string $path = null)
    {
        $locale = $request->route('locale') ?? 'uk';
        $path = $path ? '/'.trim($path, '/').'/' : '/';

        // Cache on path+locale for speed
        $cacheKey = "route:{$locale}:{$path}";

        return Cache::remember($cacheKey, 60, function () use ($locale, $path) {

            // Try Page first
            $page = Page::where('path', $path)->first();
            if ($page) {
                $data = $page->toViewData($locale);
                abort_if($data['noindex'] ?? false, 404); // optional: or just set meta robots noindex

                return response()->view('page', $data);
            }

            // Try Post
            $post = Post::where('path', $path)->first();
            if ($post) {
                $data = $post->toViewData($locale);

                return response()->view('post', $data);
            }

            abort(404);
        });
    }

    public function sitemap(Request $request)
    {
        $urls = Cache::remember('sitemap:urls:v2', 60, function () {
            $base = 'https://nikolacars.kiev.ua';
            $items = [];

            $add = static function (string $path) use (&$items, $base): void {
                $path = '/'.trim($path, '/');
                $items[] = $base.($path === '/' ? '/' : $path.'/');
            };

            $staticPaths = [
                '/',
                '/ru/',
                '/services/',
                '/ru/services/',
                '/testimonial/',
                '/ru/testimonial/',
                '/news/',
                '/ru/news/',
                '/contacts/',
                '/ru/contacts/',
                '/parts/',
                '/ru/parts/',
                '/privacy-policy/',
                '/ru/privacy-policy/',
                '/services/tesla-service/',
                '/ru/services/tesla-service/',
                '/services/tesla-electricmotor-repair/',
                '/ru/services/tesla-electricmotor-repair/',
                '/services/tesla-battery-repair/',
                '/ru/services/tesla-battery-repair/',
                '/services/repair-tesla-door-handle/',
                '/ru/services/repair-tesla-door-handle/',
                '/services/tesla-subframe-repair/',
                '/ru/services/tesla-subframe-repair/',
                '/services/vidnovlennya-sertyfikativ-tesla/',
                '/ru/services/vidnovlennya-sertyfikativ-tesla/',
                '/services/firmware-auto/',
                '/ru/services/firmware-auto/',
                '/services/prigon-tesla-usa/',
                '/ru/services/prigon-tesla-usa/',
            ];

            foreach ($staticPaths as $path) {
                $add($path);
            }

            foreach (config('targeted_services', []) as $service) {
                $slug = $service['slug'] ?? null;
                if (! $slug || ! view()->exists('services.targeted.'.$slug)) {
                    continue;
                }

                $add('/services/'.$slug.'/');
                $add('/ru/services/'.$slug.'/');
            }

            return array_values(array_unique($items));
        });

        $xml = view('sitemap', ['urls' => $urls])->render();

        return Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(Request $request)
    {
        $txt = "User-agent: *\nAllow: /\n\nSitemap: https://nikolacars.kiev.ua/sitemap.xml\n";

        return Response::make($txt, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
