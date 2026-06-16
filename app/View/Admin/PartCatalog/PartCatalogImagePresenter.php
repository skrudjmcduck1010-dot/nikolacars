<?php

namespace App\View\Admin\PartCatalog;

use App\Models\PartCatalogItem;
use App\Services\DrivePartsCatalogImporter;
use App\Support\PartCatalogRawAttributes;
use App\Support\PublicStorageUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PartCatalogImagePresenter
{
    public function urlsFor(PartCatalogItem $item): Collection
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);
        $urls = $item->source === 'tesla_official'
            ? data_get($rawAttributes, 'part_image_urls', [])
            : data_get($rawAttributes, 'image_urls', []);
        $localUrls = collect($urls)
            ->when(data_get($rawAttributes, 'image_url'), fn (Collection $collection, mixed $url): Collection => $collection->push($url))
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->reject(fn (string $url): bool => Str::startsWith($url, ['http://', 'https://']));

        return ($item->source !== 'tesla_official' && $localUrls->isNotEmpty()
            ? $localUrls
            : collect($urls)
                ->merge((array) data_get($rawAttributes, 'remote_image_urls', []))
                ->when(data_get($rawAttributes, 'image_url'), fn (Collection $collection, mixed $url): Collection => $collection->push($url))
                ->when(data_get($rawAttributes, 'remote_image_url'), fn (Collection $collection, mixed $url): Collection => $collection->push($url))
                ->when(data_get($rawAttributes, 'primary_image_url'), fn (Collection $collection, mixed $url): Collection => $collection->push($url)))
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => $item->source === 'driveparts' && $this->isDrivePartsPlaceholderImageReference($url)
                ? $this->drivePartsPlaceholderImageUrl()
                : $this->imageUrl($url))
            ->reject(fn (string $url): bool => $this->isPlaceholderImageUrl($url))
            ->unique(fn (string $url): string => $this->identityKey($url))
            ->values()
            ->pipe(fn (Collection $imageUrls): Collection => $item->source === 'driveparts' && $imageUrls->contains(fn (string $url): bool => $this->isDrivePartsSharedPlaceholderImageUrl($url))
                ? collect([$this->drivePartsPlaceholderImageUrl()])
                : $imageUrls);
    }

    public function imageUrl(string $url): string
    {
        $url = trim($url);

        if ($url !== '' && Str::startsWith($url, ['http://', 'https://'])) {
            return $this->localTeslaOfficialResourceImageUrl($url) ?? $url;
        }

        if ($url === '' || Str::startsWith($url, ['http://', 'https://', '/'])) {
            return $url;
        }

        return PublicStorageUrl::url($url) ?? $url;
    }

    public function drivePartsPlaceholderImageUrl(): string
    {
        return PublicStorageUrl::url(DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH) ?? '';
    }

    protected function localTeslaOfficialResourceImageUrl(string $url): ?string
    {
        if (! Str::contains((string) parse_url($url, PHP_URL_HOST), 'epc.tesla.com')
            || ! Str::contains((string) parse_url($url, PHP_URL_PATH), '/resources/images/')) {
            return null;
        }

        $urlPath = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION) ?: 'jpg');
        $name = Str::slug(Str::limit(pathinfo($urlPath, PATHINFO_FILENAME) ?: sha1($url), 90, ''), '-');
        $path = 'tesla-official/resources-images/'.$name.'-'.substr(sha1($url), 0, 12).'.'.$extension;

        return Storage::disk('public')->exists($path)
            ? PublicStorageUrl::url($path)
            : null;
    }

    public function isDrivePartsPlaceholderImageReference(string $url): bool
    {
        $path = str_replace('\\', '/', rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $path = trim($path);
        $storagePath = ltrim($path, '/');
        if (Str::startsWith($storagePath, 'storage/')) {
            $storagePath = ltrim(Str::after($storagePath, 'storage/'), '/');
        }

        if ($path === '' || $storagePath === DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH) {
            return false;
        }

        if (Str::startsWith($storagePath, 'driveparts/part-images/')
            && ! Storage::disk('public')->exists($storagePath)) {
            return true;
        }

        return preg_match('~(?:/content/images/\d+/[^/]+/|(?:^|/)driveparts/part-images/[^/]+/)(?:35938788866351|32578632436198|65112127046566|63823657639696|44052052692034|21836359960804)(?:[-.][^/]*)?$~i', $path) === 1;
    }

    public function isDrivePartsSharedPlaceholderImageUrl(string $url): bool
    {
        $path = trim(str_replace('\\', '/', rawurldecode((string) parse_url($url, PHP_URL_PATH))), '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = ltrim(Str::after($path, 'storage/'), '/');
        }

        return $path === DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH;
    }

    public function isPlaceholderImageUrl(string $url): bool
    {
        return preg_match('~/storage/editor/fotos/(?:6f46fee0ab4e187090a1f63b7a570bb2|59968e2a90ed37d309bb00d2e4423600)_\d+\.(?:jpe?g|png|webp)(?:[?#].*)?$~iu', $url) === 1;
    }

    public function identityKey(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        if (Str::contains($path, ['driveparts/part-images/', 'competitor-catalog/driveparts/'])) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $name = preg_replace('/-[a-f0-9]{10,12}$/i', '', $name) ?: $name;

            return 'driveparts:'.Str::lower($name);
        }

        if (Str::contains($path, '/competitor-catalog/')) {
            return $path;
        }

        if (Str::contains($path, '/resources/images/')) {
            return (string) preg_replace('/\.(?:svg|png|jpe?g|webp)$/iu', '', $path);
        }

        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = str_replace('_', '-', Str::lower($name));
        $name = preg_replace('/-[a-f0-9]{10,12}$/i', '', $name) ?: $name;

        return $name ?: $url;
    }
}
