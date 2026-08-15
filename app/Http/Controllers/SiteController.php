<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use App\Services\SkladStorefrontClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use RuntimeException;

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

    public function sitemap(Request $request, SkladStorefrontClient $storefront)
    {
        $base = 'https://nikolacars.kiev.ua';
        $items = [];
        $add = static function (string $path, ?string $lastModified = null) use (&$items, $base): void {
            $path = '/'.trim($path, '/');
            $url = $base.($path === '/' ? '/' : $path.'/');
            $items[$url] = array_filter([
                'loc' => $url,
                'lastmod' => $lastModified ? substr($lastModified, 0, 10) : null,
            ]);
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

        try {
            $partsIndex = Cache::remember('sitemap:parts-index:v1', now()->addHour(), function () use ($storefront): array {
                $response = $storefront->seoIndex();
                if (! $response->successful() || ! is_array($response->json())) {
                    throw new RuntimeException('Warehouse SEO index returned HTTP '.$response->status().'.');
                }

                $payload = $response->json();
                Cache::put('sitemap:parts-index:stale:v1', $payload, now()->addDays(7));

                return $payload;
            });
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            $partsIndex = Cache::get('sitemap:parts-index:stale:v1', []);
        }

        $partsUpdatedAt = $partsIndex['updated_at'] ?? null;
        foreach (($partsIndex['locales'] ?? []) as $locale => $sections) {
            $prefix = $locale === 'ru' ? '/ru/parts' : '/parts';

            foreach (($sections['models'] ?? []) as $model) {
                if (! empty($model['slug'])) {
                    $add($prefix.'/'.$model['slug'], $partsUpdatedAt);
                }
            }

            foreach (($sections['categories'] ?? []) as $category) {
                if (! empty($category['slug'])) {
                    $add($prefix.'/category/'.$category['slug'], $partsUpdatedAt);
                }
            }

            foreach (($sections['sections'] ?? []) as $section) {
                if (! empty($section['model_slug']) && ! empty($section['category_slug'])) {
                    $add($prefix.'/'.$section['model_slug'].'/'.$section['category_slug'], $partsUpdatedAt);
                }
            }
        }

        foreach (($partsIndex['products'] ?? []) as $product) {
            if (empty($product['id'])) {
                continue;
            }

            $add('/parts/'.$product['id'], $product['updated_at'] ?? null);
            $add('/ru/parts/'.$product['id'], $product['updated_at'] ?? null);
        }

        $xml = view('sitemap', ['urls' => array_values($items)])->render();

        return Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(Request $request)
    {
        $txt = "User-agent: *\nAllow: /\nDisallow: /parts/api/\nDisallow: /ru/parts/api/\n\nSitemap: https://nikolacars.kiev.ua/sitemap.xml\n";

        return Response::make($txt, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
