<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Collection;

class ProductPhotoNormalizer
{
    public static function productPhotos(Product $product, iterable $extraPhotos = []): Collection
    {
        return collect([$product->main_image])
            ->merge((array) ($product->images_json ?? []))
            ->merge($extraPhotos)
            ->filter(fn (mixed $photo): bool => trim((string) $photo) !== '')
            ->reject(fn (mixed $photo): bool => self::isCatalogSchemeImage((string) $photo))
            ->unique(fn (mixed $photo): string => self::imageKey((string) $photo))
            ->values();
    }

    public static function imageKey(?string $path): string
    {
        $url = trim((string) (PublicStorageUrl::url($path) ?? $path));
        $parts = parse_url($url);

        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $scheme = mb_strtolower((string) $parts['scheme']);
            $host = mb_strtolower((string) $parts['host']);
            $urlPath = rawurldecode((string) ($parts['path'] ?? ''));
            $query = isset($parts['query']) ? '?'.$parts['query'] : '';

            return $scheme.'://'.$host.$urlPath.$query;
        }

        return rawurldecode($url);
    }

    public static function isCatalogSchemeImage(?string $path): bool
    {
        $raw = mb_strtolower(rawurldecode(trim((string) $path)));
        $url = mb_strtolower(rawurldecode(trim((string) (PublicStorageUrl::url($path) ?? $path))));

        return str_contains($raw, 'tesla-official/resources-images/')
            || str_contains($url, 'tesla-official/resources-images/')
            || str_contains($raw, 'epc.tesla.com/resources/images/')
            || str_contains($url, 'epc.tesla.com/resources/images/');
    }

    public static function persistencePayload(Collection $photos): array
    {
        $photos = $photos
            ->filter(fn (mixed $photo): bool => trim((string) $photo) !== '')
            ->values();

        return [
            'main_image' => $photos->first(),
            'images_json' => $photos->isNotEmpty() ? $photos->all() : null,
        ];
    }
}
