<?php

namespace App\Http\Controllers;

use App\Services\SkladStorefrontClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PartsController extends Controller
{
    public function index(Request $request, SkladStorefrontClient $client): View
    {
        $locale = $request->route('locale') === 'ru' ? 'ru' : 'uk';
        $modelSlug = (string) $request->route('modelSlug', '');
        $categorySlug = (string) $request->route('categorySlug', '');

        try {
            $response = $client->catalog(array_filter([
                'locale' => $locale,
                'model_slug' => $modelSlug,
                'category_slug' => $categorySlug,
                'q' => trim((string) $request->query('q', '')),
                'sort' => (string) $request->query('sort', 'newest'),
                'page' => 1,
                'per_page' => 24,
            ], fn (mixed $value): bool => $value !== ''));
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            abort(503, 'Склад временно недоступен.');
        }

        abort_unless($response->successful() && is_array($response->json()), 404);

        $initialCatalog = $response->json();
        $selection = $initialCatalog['selection'] ?? [];
        $sectionName = trim(implode(' — ', array_filter([
            $selection['category'] ?? '',
            $selection['model'] ?? '',
        ])));
        $baseTitle = $locale === 'ru' ? 'Запчасти Tesla' : 'Запчастини Tesla';
        $seoTitle = implode(' — ', array_filter([$baseTitle, $sectionName, 'NikolaCars']));
        $seoDescription = $locale === 'ru'
            ? 'Оригинальные запчасти Tesla в наличии в Киеве'.($sectionName !== '' ? ': '.$sectionName : '').'.'
            : 'Оригінальні запчастини Tesla в наявності у Києві'.($sectionName !== '' ? ': '.$sectionName : '').'.';

        return view('parts.index', compact(
            'locale',
            'modelSlug',
            'categorySlug',
            'initialCatalog',
            'seoTitle',
            'seoDescription',
        ));
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

        return view('parts.show', [
            'locale' => $locale,
            'product' => $productData,
            'seoTitle' => $productData['name'].' — NikolaCars',
            'seoDescription' => $productData['name'].($locale === 'ru'
                ? '. Оригинальные запчасти Tesla в наличии у NikolaCars.'
                : '. Оригінальні запчастини Tesla в наявності у NikolaCars.'),
        ]);
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
