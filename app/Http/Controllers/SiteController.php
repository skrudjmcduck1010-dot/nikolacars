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

    public function sitemap(Request $request)
    {
        $xml = Cache::rememberForever('sitemap:index:xml:v1', fn (): string => view('sitemap-index', [
            'sitemaps' => [
                'https://nikolacars.kiev.ua/sitemaps/pages.xml',
                'https://nikolacars.kiev.ua/sitemaps/parts-uk.xml',
                'https://nikolacars.kiev.ua/sitemaps/parts-ru.xml',
            ],
        ])->render());

        return $this->xmlResponse($request, $xml, 86400);
    }

    public function sitemapPages(Request $request)
    {
        $xml = Cache::remember('sitemap:pages:xml:v1', now()->addDay(), function (): string {
            $paths = [
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

            foreach (config('targeted_services', []) as $service) {
                $slug = $service['slug'] ?? null;
                if ($slug && view()->exists('services.targeted.'.$slug)) {
                    $paths[] = '/services/'.$slug.'/';
                    $paths[] = '/ru/services/'.$slug.'/';
                }
            }

            return view('sitemap', ['urls' => $this->sitemapItems($paths)])->render();
        });

        return $this->xmlResponse($request, $xml, 86400);
    }

    public function sitemapParts(Request $request, string $locale, SkladStorefrontClient $storefront)
    {
        abort_unless(in_array($locale, ['uk', 'ru'], true), 404);

        $xml = Cache::flexible(
            'sitemap:parts:'.$locale.':xml:v2',
            [3600, 604800],
            function () use ($locale, $storefront): string {
                $partsIndex = $this->partsIndex($storefront);
                $prefix = $locale === 'ru' ? '/ru/parts' : '/parts';
                $paths = [[$prefix, $partsIndex['updated_at'] ?? null]];
                $sections = $partsIndex['locales'][$locale] ?? [];

                foreach (($sections['models'] ?? []) as $model) {
                    if (! empty($model['slug'])) {
                        $paths[] = [$prefix.'/'.$model['slug'], $partsIndex['updated_at'] ?? null];
                    }
                }

                foreach (($sections['categories'] ?? []) as $category) {
                    if (! empty($category['slug'])) {
                        $paths[] = [$prefix.'/category/'.$category['slug'], $partsIndex['updated_at'] ?? null];
                    }
                }

                foreach (($sections['sections'] ?? []) as $section) {
                    if (! empty($section['model_slug']) && ! empty($section['category_slug'])) {
                        $paths[] = [
                            $prefix.'/'.$section['model_slug'].'/'.$section['category_slug'],
                            $partsIndex['updated_at'] ?? null,
                        ];
                    }
                }

                foreach (($partsIndex['products'] ?? []) as $product) {
                    if (! empty($product['id'])) {
                        $paths[] = [$prefix.'/'.$product['id'], $product['updated_at'] ?? null];
                    }
                }

                return view('sitemap', ['urls' => $this->sitemapItems($paths)])->render();
            },
        );

        return $this->xmlResponse($request, $xml, 3600);
    }

    protected function partsIndex(SkladStorefrontClient $storefront): array
    {
        try {
            return Cache::remember('sitemap:parts-index:v2', now()->addHour(), function () use ($storefront): array {
                $response = $storefront->seoIndex();
                if (! $response->successful() || ! is_array($response->json())) {
                    throw new RuntimeException('Warehouse SEO index returned HTTP '.$response->status().'.');
                }

                $payload = $response->json();
                Cache::put('sitemap:parts-index:stale:v2', $payload, now()->addDays(7));

                return $payload;
            });
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);

            return Cache::get('sitemap:parts-index:stale:v2', []);
        }
    }

    protected function sitemapItems(array $paths): array
    {
        $base = 'https://nikolacars.kiev.ua';
        $items = [];

        foreach ($paths as $entry) {
            [$path, $lastModified] = is_array($entry) ? [$entry[0], $entry[1] ?? null] : [$entry, null];
            $path = '/'.trim((string) $path, '/');
            $url = $base.($path === '/' ? '/' : $path.'/');
            $items[$url] = array_filter([
                'loc' => $url,
                'lastmod' => $lastModified ? substr((string) $lastModified, 0, 10) : null,
            ]);
        }

        return array_values($items);
    }

    protected function xmlResponse(Request $request, string $xml, int $maxAge)
    {
        $response = Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->setEtag(sha1($xml));
        $response->headers->set('Cache-Control', 'public, max-age='.$maxAge.', s-maxage='.$maxAge.', stale-while-revalidate=86400');
        $response->isNotModified($request);

        return $response;
    }

    public function robots(Request $request)
    {
        $txt = "User-agent: *\nAllow: /\nDisallow: /parts/api/\nDisallow: /ru/parts/api/\n\nSitemap: https://nikolacars.kiev.ua/sitemap.xml\n";

        return Response::make($txt, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
