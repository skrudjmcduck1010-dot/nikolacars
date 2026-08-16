<?php

namespace App\Http\Controllers;

use App\Services\SkladStorefrontClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PartsController extends Controller
{
    private const CATEGORY_LOCALE_SLUGS = [
        ['uk' => 'informaciino-rozvazalna-sistema', 'ru' => 'informacionno-razvlekatelnaia-sistema'],
        ['uk' => 'bezpeka-i-zaxist', 'ru' => 'bezopasnost-i-zashhita'],
        ['uk' => 'visokovoltna-batareia', 'ru' => 'visokovoltna-batareia'],
        ['uk' => 'visokovoltna-sistema', 'ru' => 'vysokovoltnaia-sistema'],
        ['uk' => 'vnutrisnje-ozdoblennia', 'ru' => 'vnutrenniaia-otdelka'],
        ['uk' => 'galma', 'ru' => 'tormoza'],
        ['uk' => 'diski-i-sini', 'ru' => 'diski-i-siny'],
        ['uk' => 'elektrika', 'ru' => 'elektrika'],
        ['uk' => 'zadnii-motor', 'ru' => 'zadnii-motor'],
        ['uk' => 'zovnisnia-furnitura', 'ru' => 'naruznaia-furnitura'],
        ['uk' => 'komponenti-zakrittia', 'ru' => 'komponenty-zakrytiia'],
        ['uk' => 'krisa', 'ru' => 'krysa'],
        ['uk' => 'kuzov', 'ru' => 'kuzov'],
        ['uk' => 'panel-priladiv', 'ru' => 'pribornaia-panel'],
        ['uk' => 'perednii-motor', 'ru' => 'perednii-motor'],
        ['uk' => 'pidviska', 'ru' => 'podveska'],
        ['uk' => 'rulyovii-mexanizm', 'ru' => 'rulevoi-mexanizm'],
        ['uk' => 'sidinnia', 'ru' => 'sidenia'],
        ['uk' => 'upravlinnia-temperaturnim-rezimom', 'ru' => 'upravlenie-temperaturnym-rezimom'],
    ];

    public function index(Request $request, SkladStorefrontClient $client): View|RedirectResponse
    {
        $locale = $request->route('locale') === 'ru' ? 'ru' : 'uk';
        $modelSlug = (string) $request->route('modelSlug', '');
        $categorySlug = (string) $request->route('categorySlug', '');
        if ($modelSlug === 'model-s2-04-2016-01-2021') {
            $target = ($locale === 'ru' ? '/ru/parts/' : '/parts/')
                .'model-s-04-2016-01-2021/'
                .($categorySlug !== '' ? $categorySlug.'/' : '');
            $queryString = http_build_query($request->query());
            $targetUrl = rtrim(url($target), '/').'/';

            return redirect()->away($targetUrl.($queryString !== '' ? '?'.$queryString : ''), 301);
        }

        $page = max(1, $request->integer('page', 1));
        $query = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'newest');
        if (! in_array($sort, ['newest', 'price_asc', 'price_desc', 'name'], true)) {
            $sort = 'newest';
        }

        try {
            $response = $client->catalog(array_filter([
                'locale' => $locale,
                'model_slug' => $modelSlug,
                'category_slug' => $categorySlug,
                'q' => $query,
                'sort' => $sort,
                'page' => $page,
                'per_page' => 24,
            ], fn (mixed $value): bool => $value !== ''));
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            abort(503, 'Склад временно недоступен.');
        }

        abort_unless($response->successful() && is_array($response->json()), 404);

        $initialCatalog = $response->json();
        $lastPage = (int) ($initialCatalog['pagination']['last_page'] ?? 1);
        abort_if($page > max(1, $lastPage), 404);

        $selection = $initialCatalog['selection'] ?? [];
        $sectionName = trim(implode(' — ', array_filter([
            $selection['category'] ?? '',
            $selection['model'] ?? '',
        ])));
        $baseTitle = $locale === 'ru' ? 'Запчасти Tesla' : 'Запчастини Tesla';
        $pageLabel = $page > 1 ? ($locale === 'ru' ? 'Страница '.$page : 'Сторінка '.$page) : '';
        $seoTitle = implode(' — ', array_filter([$baseTitle, $sectionName, $pageLabel, 'NikolaCars']));
        $seoDescription = $locale === 'ru'
            ? 'Оригинальные запчасти Tesla в наличии в Киеве'.($sectionName !== '' ? ': '.$sectionName : '').($page > 1 ? '. Страница '.$page : '').'.'
            : 'Оригінальні запчастини Tesla в наявності у Києві'.($sectionName !== '' ? ': '.$sectionName : '').($page > 1 ? '. Сторінка '.$page : '').'.';
        $seoNoindex = $query !== '' || $request->has('sort') || $request->has('model') || $request->has('category');
        $seoPage = $seoNoindex ? 1 : $page;
        $localeUrls = $this->partsLocaleUrls($modelSlug, $categorySlug);

        return view('parts.index', compact(
            'locale',
            'modelSlug',
            'categorySlug',
            'initialCatalog',
            'seoTitle',
            'seoDescription',
            'seoNoindex',
            'seoPage',
            'localeUrls',
        ));
    }

    /** @return array{uk: string, ru: string} */
    private function partsLocaleUrls(string $modelSlug, string $categorySlug): array
    {
        $categorySlugs = ['uk' => $categorySlug, 'ru' => $categorySlug];
        foreach (self::CATEGORY_LOCALE_SLUGS as $localizedSlugs) {
            if (in_array($categorySlug, $localizedSlugs, true)) {
                $categorySlugs = $localizedSlugs;
                break;
            }
        }

        $urls = [];
        foreach (['uk', 'ru'] as $locale) {
            $base = $locale === 'ru' ? '/ru/parts' : '/parts';
            if ($modelSlug !== '' && $categorySlugs[$locale] !== '') {
                $urls[$locale] = $base.'/'.$modelSlug.'/'.$categorySlugs[$locale].'/';
            } elseif ($modelSlug !== '') {
                $urls[$locale] = $base.'/'.$modelSlug.'/';
            } elseif ($categorySlugs[$locale] !== '') {
                $urls[$locale] = $base.'/category/'.$categorySlugs[$locale].'/';
            } else {
                $urls[$locale] = $base.'/';
            }
        }

        return $urls;
    }

    public function catalog(Request $request, SkladStorefrontClient $client, string $locale = 'uk'): JsonResponse
    {
        return $this->proxy(fn () => $client->catalog($request->query() + ['locale' => $locale]));
    }

    public function show(SkladStorefrontClient $client, int $product, string $locale = 'uk'): View
    {
        $locale = $locale === 'ru' ? 'ru' : 'uk';

        try {
            $response = $client->product($product, $locale);
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            abort(503, 'Склад временно недоступен.');
        }

        abort_unless($response->successful() && is_array($response->json()), 404);
        $productData = $response->json();

        $name = trim((string) ($productData['name'] ?? ''));
        $model = trim((string) ($productData['model'] ?? ''));
        $model = trim((string) preg_replace('/^tesla\s+/iu', '', $model));
        $vehicle = trim('Tesla '.$model);
        $article = trim((string) ($productData['part_number'] ?? ''));
        if ($article === '') {
            $article = trim((string) ($productData['sku'] ?? $productData['id'] ?? $product));
        }

        $productTitle = $name;
        if ($vehicle !== '' && mb_stripos($productTitle, $vehicle) === false) {
            $productTitle = trim($productTitle.' '.$vehicle);
        }
        $seoTitle = $productTitle.' — '.$article.' | NikolaCars';
        $price = number_format((float) ($productData['price_uah'] ?? 0), 0, '.', ' ');
        $seoDescription = $locale === 'ru'
            ? $productTitle.', артикул '.$article.' — '.$price.' грн. В наличии в NikolaCars, Киев.'
            : $productTitle.', артикул '.$article.' — '.$price.' грн. В наявності у NikolaCars, Київ.';
        $productData['description'] = $this->generateProductDescription(
            $productData,
            $locale,
            $name,
            $vehicle,
            $article,
            $price,
        );

        $baseUrl = 'https://nikolacars.kiev.ua';
        $catalogPath = $locale === 'ru' ? '/ru/parts/' : '/parts/';
        $productUrl = $baseUrl.$catalogPath.$product.'/';
        $images = collect($productData['images'] ?? [])
            ->push($productData['image_url'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $condition = mb_strtolower(trim((string) ($productData['condition'] ?? '')), 'UTF-8');
        $itemCondition = match (true) {
            preg_match('/(^|\s)(new|нов|нова|новая)(\s|$)/u', $condition) === 1 => 'https://schema.org/NewCondition',
            preg_match('/(used|б\/у|вживан|уживан)/u', $condition) === 1 => 'https://schema.org/UsedCondition',
            default => null,
        };
        $offer = array_filter([
            '@type' => 'Offer',
            'url' => $productUrl,
            'priceCurrency' => 'UAH',
            'price' => number_format((float) ($productData['price_uah'] ?? 0), 2, '.', ''),
            'availability' => (int) ($productData['quantity'] ?? 0) > 0
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'itemCondition' => $itemCondition,
            'seller' => ['@type' => 'Organization', 'name' => 'NikolaCars'],
        ]);
        $productSchema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $productTitle,
            'image' => $images,
            'description' => $productData['description'],
            'sku' => trim((string) ($productData['sku'] ?? '')) ?: $article,
            'mpn' => trim((string) ($productData['part_number'] ?? '')) ?: null,
            'brand' => ['@type' => 'Brand', 'name' => 'Tesla'],
            'category' => trim((string) ($productData['category_path'] ?? '')) ?: null,
            'offers' => $offer,
        ]);

        $breadcrumbItems = [[
            '@type' => 'ListItem',
            'position' => 1,
            'name' => $locale === 'ru' ? 'Запчасти Tesla' : 'Запчастини Tesla',
            'item' => $baseUrl.$catalogPath,
        ]];
        $modelSlug = trim((string) ($productData['model_slug'] ?? ''));
        if ($modelSlug !== '') {
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => count($breadcrumbItems) + 1,
                'name' => (string) ($productData['model'] ?? ''),
                'item' => $baseUrl.$catalogPath.$modelSlug.'/',
            ];
        }
        $categorySlug = trim((string) ($productData['category_slug'] ?? ''));
        if ($modelSlug !== '' && $categorySlug !== '') {
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => count($breadcrumbItems) + 1,
                'name' => (string) ($productData['category'] ?? ''),
                'item' => $baseUrl.$catalogPath.$modelSlug.'/'.$categorySlug.'/',
            ];
        }
        $breadcrumbItems[] = [
            '@type' => 'ListItem',
            'position' => count($breadcrumbItems) + 1,
            'name' => $name,
            'item' => $productUrl,
        ];

        return view('parts.show', [
            'locale' => $locale,
            'product' => $productData,
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'seoStructuredData' => [
                $productSchema,
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => $breadcrumbItems,
                ],
            ],
        ]);
    }

    protected function generateProductDescription(
        array $product,
        string $locale,
        string $name,
        string $vehicle,
        string $article,
        string $price,
    ): string {
        $category = trim((string) ($product['category_path'] ?? $product['category'] ?? ''));
        $compatibility = trim((string) ($product['compatibility'] ?? ''));
        $condition = $this->localizedProductAttribute((string) ($product['condition'] ?? ''), $locale, 'condition');
        $color = $this->localizedProductAttribute((string) ($product['color'] ?? ''), $locale, 'color');
        $quantity = max(0, (int) ($product['quantity'] ?? 0));
        $compactArticle = preg_replace('/[^\p{L}\p{N}]+/u', '', $article) ?: $article;

        if ($locale === 'ru') {
            $details = [
                $name.' — запчасть для '.$vehicle.'.',
                'Артикул: '.$article.($compactArticle !== $article ? ' ('.$compactArticle.')' : '').'.',
            ];
            if ($category !== '') {
                $details[] = 'Категория: '.$category.'.';
            }
            if ($compatibility !== '') {
                $details[] = 'Совместимость: '.$compatibility.'.';
            }
            if ($condition !== '') {
                $details[] = 'Состояние: '.$condition.'.';
            }
            if ($color !== '') {
                $details[] = 'Цвет: '.$color.'.';
            }

            $availability = $quantity > 0
                ? 'Запчасть в наличии на складе NikolaCars: '.$quantity.' шт.'
                : 'Наличие запчасти уточняйте у менеджера NikolaCars.';

            return implode(' ', $details)."\n\n".$availability.' Купить запчасть можно по цене '.$price.' грн. Перед заказом сверьте артикул и совместимость детали с вашим автомобилем Tesla.';
        }

        $details = [
            $name.' — запчастина для '.$vehicle.'.',
            'Артикул: '.$article.($compactArticle !== $article ? ' ('.$compactArticle.')' : '').'.',
        ];
        if ($category !== '') {
            $details[] = 'Категорія: '.$category.'.';
        }
        if ($compatibility !== '') {
            $details[] = 'Сумісність: '.$compatibility.'.';
        }
        if ($condition !== '') {
            $details[] = 'Стан: '.$condition.'.';
        }
        if ($color !== '') {
            $details[] = 'Колір: '.$color.'.';
        }

        $availability = $quantity > 0
            ? 'Запчастина є в наявності на складі NikolaCars: '.$quantity.' шт.'
            : 'Наявність запчастини уточнюйте у менеджера NikolaCars.';

        return implode(' ', $details)."\n\n".$availability.' Купити запчастину можна за ціною '.$price.' грн. Перед замовленням звірте артикул і сумісність деталі з вашим автомобілем Tesla.';
    }

    protected function localizedProductAttribute(string $value, string $locale, string $attribute): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        if ($value === '' || $locale === 'ru') {
            return $value;
        }

        $translations = $attribute === 'condition'
            ? [
                'new' => 'нова',
                'новая' => 'нова',
                'новый' => 'новий',
                'used' => 'вживана',
                'б/у' => 'вживана',
            ]
            : [
                'black' => 'чорний',
                'черный' => 'чорний',
                'чёрный' => 'чорний',
                'white' => 'білий',
                'белый' => 'білий',
                'red' => 'червоний',
                'красный' => 'червоний',
                'blue' => 'синій',
                'синий' => 'синій',
                'grey' => 'сірий',
                'gray' => 'сірий',
                'серый' => 'сірий',
                'silver' => 'сріблястий',
                'серебристый' => 'сріблястий',
                'green' => 'зелений',
                'зеленый' => 'зелений',
                'brown' => 'коричневий',
                'коричневый' => 'коричневий',
                'beige' => 'бежевий',
                'бежевый' => 'бежевий',
                'yellow' => 'жовтий',
                'желтый' => 'жовтий',
                'orange' => 'помаранчевий',
                'оранжевый' => 'помаранчевий',
            ];

        return strtr($value, $translations);
    }

    public function cities(Request $request, SkladStorefrontClient $client): JsonResponse
    {
        return $this->proxy(fn () => $client->cities($request->query()));
    }

    public function warehouses(Request $request, SkladStorefrontClient $client): JsonResponse
    {
        return $this->proxy(fn () => $client->warehouses($request->query()));
    }

    public function storeOrder(Request $request, SkladStorefrontClient $client, string $locale = 'uk'): JsonResponse
    {
        return $this->proxy(fn () => $client->createOrder($request->all() + ['locale' => $locale]));
    }

    protected function proxy(callable $callback): JsonResponse
    {
        try {
            $response = $callback();
            $payload = $response->json();

            return response()->json(is_array($payload) ? $payload : ['message' => 'Invalid warehouse response.'], $response->status());
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => 'Склад временно недоступен. Попробуйте немного позже.'], 503);
        }
    }
}
