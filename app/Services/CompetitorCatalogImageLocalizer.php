<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CompetitorCatalogImageLocalizer
{
    protected const DRIVE_PARTS_CHALLENGE_COOKIE = 'ea711ddd5b297885600ff1df0ef114b145ad0fa0fc6e6d02d637fbc6f4eb4666';

    protected const DRIVEPARTS_PREFERRED_IMAGE_SIZE_SEGMENT = '450x600l80mc100';

    public const SOURCES = [
        'tcarservice',
        'teslapartsukraine',
        'tsk',
        'stock-tesla',
        'driveparts',
        'dkparts',
        'erazborka',
        'toprazborka',
        'teslawestparts',
        'teslacompany',
        'tesla_official',
    ];

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function localizeSource(string $source, array $options = []): array
    {
        $source = trim($source);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $progress = $options['progress'] ?? null;

        $stats = [
            'catalog_images_items_seen' => 0,
            'catalog_images_items_updated' => 0,
            'catalog_images_remote_seen' => 0,
            'catalog_images_downloaded' => 0,
            'catalog_images_existing' => 0,
            'catalog_images_failed' => 0,
            'catalog_images_skipped_placeholders' => 0,
        ];

        if (! in_array($source, self::SOURCES, true)) {
            return $stats;
        }

        $query = PartCatalogItem::query()->where('source', $source);
        if ($source === 'tesla_official') {
            $this->whereHasRemoteTeslaOfficialDisplayImage($query);
        } else {
            $this->whereHasRemoteDisplayImage($query, $source);
        }
        $query->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->get()->each(function (PartCatalogItem $item) use ($source, $progress, &$stats): void {
            $stats['catalog_images_items_seen']++;

            if (is_callable($progress) && $stats['catalog_images_items_seen'] % 25 === 0) {
                $progress("Images {$source}: {$stats['catalog_images_items_seen']} items scanned, {$stats['catalog_images_downloaded']} downloaded.");
            }

            $changed = $this->localizeItemImages($item, $stats);

            if ($changed) {
                $stats['catalog_images_items_updated']++;
            }
        });

        return $stats;
    }

    public function localizeItemImages(PartCatalogItem $item, array &$stats = []): bool
    {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        $existingLocalPaths = collect([
            ...(array) ($rawAttributes['image_urls'] ?? []),
            ...(array) ($rawAttributes['part_image_urls'] ?? []),
            ...(array) ($rawAttributes['system_group_image_urls'] ?? []),
            $rawAttributes['image_url'] ?? null,
            $rawAttributes['primary_image_url'] ?? null,
        ])
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '' && ! $this->isRemoteImageUrl($url))
            ->reject(fn (string $url): bool => $item->source === 'tesla_official' && str_contains($url, 'competitor-catalog/tesla_official/'))
            ->unique()
            ->values()
            ->all();

        $remoteUrls = collect([
            ...(array) ($rawAttributes['image_urls'] ?? []),
            ...(array) ($rawAttributes['part_image_urls'] ?? []),
            ...(array) ($rawAttributes['system_group_image_urls'] ?? []),
            ...(array) ($rawAttributes['remote_image_urls'] ?? []),
            $rawAttributes['image_url'] ?? null,
            $rawAttributes['remote_image_url'] ?? null,
            $rawAttributes['primary_image_url'] ?? null,
        ])
            ->filter(fn ($url): bool => is_string($url) && $this->isRemoteImageUrl($url))
            ->map(fn (string $url): string => $this->normalizedRemoteImageUrl($item->source, $url))
            ->sortByDesc(fn (string $url): int => $this->remoteImageSortScore($url))
            ->unique(fn (string $url): string => $this->remoteImageIdentityKey($url))
            ->values();

        $localPaths = $existingLocalPaths;
        $keptRemoteUrls = [];
        $localPathByRemoteIdentity = [];
        $existingRelatedImageFields = collect(['part_image_urls', 'system_group_image_urls'])
            ->mapWithKeys(fn (string $key): array => [$key => $rawAttributes[$key] ?? null])
            ->all();

        if ($remoteUrls->isEmpty()) {
            $localPaths = $this->deduplicateLocalImagePaths($item, array_values(array_unique($localPaths)));

            foreach (['part_image_urls', 'system_group_image_urls'] as $key) {
                if (! array_key_exists($key, $rawAttributes)) {
                    continue;
                }

                $rawAttributes[$key] = $this->deduplicateLocalImagePaths($item, collect((array) $rawAttributes[$key])
                    ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
                    ->unique()
                    ->values()
                    ->all());
            }

            $relatedImageFields = collect(['part_image_urls', 'system_group_image_urls'])
                ->mapWithKeys(fn (string $key): array => [$key => $rawAttributes[$key] ?? null])
                ->all();

            if ($localPaths === $existingLocalPaths && $relatedImageFields === $existingRelatedImageFields) {
                return false;
            }

            $rawAttributes['image_urls'] = $localPaths;
            $rawAttributes['image_url'] = $localPaths[0] ?? null;
            $item->forceFill(['raw_attributes' => $rawAttributes])->save();

            return true;
        }

        foreach ($remoteUrls as $url) {
            $url = (string) $url;
            $stats['catalog_images_remote_seen'] = (int) ($stats['catalog_images_remote_seen'] ?? 0) + 1;

            if ($this->isPlaceholderImageUrl($item->source, $url)) {
                $stats['catalog_images_skipped_placeholders'] = (int) ($stats['catalog_images_skipped_placeholders'] ?? 0) + 1;

                continue;
            }

            $path = $this->downloadImage($item, $url, $stats);
            if ($path === null) {
                if ($item->source !== 'driveparts') {
                    $keptRemoteUrls[] = $url;
                }

                continue;
            }

            $localPaths[] = $path;
            $keptRemoteUrls[] = $url;
            $localPathByRemoteIdentity[$this->remoteImageIdentityKey($url)] = $path;
        }

        $localPaths = $this->deduplicateLocalImagePaths($item, array_values(array_unique($localPaths)));
        $keptRemoteUrls = array_values(array_unique($keptRemoteUrls));

        foreach (['part_image_urls', 'system_group_image_urls'] as $key) {
            if (! array_key_exists($key, $rawAttributes)) {
                continue;
            }

            $rawAttributes[$key] = $this->deduplicateLocalImagePaths($item, collect((array) $rawAttributes[$key])
                ->map(fn (mixed $url): mixed => is_string($url)
                    ? $this->localImagePathForRemoteUrl($item->source, $url, $localPathByRemoteIdentity) ?? $url
                    : $url)
                ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
                ->unique()
                ->values()
                ->all());
        }

        $relatedImageFields = collect(['part_image_urls', 'system_group_image_urls'])
            ->mapWithKeys(fn (string $key): array => [$key => $rawAttributes[$key] ?? null])
            ->all();

        if ($localPaths === $existingLocalPaths
            && ! array_key_exists('remote_image_urls', $rawAttributes)
            && ! array_key_exists('remote_image_url', $rawAttributes)
            && $relatedImageFields === $existingRelatedImageFields) {
            return false;
        }

        $rawAttributes['image_urls'] = $localPaths;
        $rawAttributes['image_url'] = $localPaths[0] ?? null;

        unset($rawAttributes['primary_image_url'], $rawAttributes['remote_image_urls'], $rawAttributes['remote_image_url']);

        $item->forceFill(['raw_attributes' => $rawAttributes])->save();

        return true;
    }

    protected function deduplicateLocalImagePaths(PartCatalogItem $item, array $paths): array
    {
        if ($item->source !== 'tesla_official') {
            return $paths;
        }

        $seenHashes = [];

        return collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path) && trim($path) !== '')
            ->reject(function (string $path) use (&$seenHashes): bool {
                if (! str_contains($path, 'tesla-official/resources-images/')) {
                    return false;
                }

                $normalizedPath = preg_replace('~^/storage/~', '', $path);
                $normalizedPath = preg_replace('~^storage/~', '', (string) $normalizedPath);
                $normalizedPath = ltrim((string) $normalizedPath, '/');
                $fullPath = Storage::disk('public')->path($normalizedPath);

                if (! is_file($fullPath)) {
                    return false;
                }

                $hash = hash_file('sha256', $fullPath);
                if (! is_string($hash) || $hash === '') {
                    return false;
                }

                if (isset($seenHashes[$hash])) {
                    return true;
                }

                $seenHashes[$hash] = true;

                return false;
            })
            ->values()
            ->all();
    }

    protected function localImagePathForRemoteUrl(string $source, string $url, array $localPathByRemoteIdentity): ?string
    {
        if (! $this->isRemoteImageUrl($url)) {
            return null;
        }

        return $localPathByRemoteIdentity[$this->remoteImageIdentityKey($this->normalizedRemoteImageUrl($source, $url))]
            ?? $localPathByRemoteIdentity[$this->remoteImageIdentityKey($url)]
            ?? null;
    }

    protected function whereHasRemoteDisplayImage(Builder $query, string $source): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $json = 'if(json_valid(raw_attributes), raw_attributes, json_object())';
            $remoteDisplaySearch = "json_search({$json}, 'one', 'http%', null, '$.image_urls[*]', '$.part_image_urls[*]', '$.system_group_image_urls[*]', '$.image_url', '$.primary_image_url') is not null";

            if ($source === 'driveparts') {
                $remoteDisplaySearch = "({$remoteDisplaySearch} or (
                    json_search({$json}, 'one', 'http%', null, '$.remote_image_urls[*]', '$.remote_image_url') is not null
                    and coalesce(json_length(json_extract({$json}, '$.remote_image_urls')), 0)
                        > coalesce(json_length(json_extract({$json}, '$.image_urls')), 0)
                ))";
            }

            $query->whereRaw($remoteDisplaySearch)
                ->where('raw_attributes', 'not like', '%6f46fee0ab4e187090a1f63b7a570bb2%')
                ->where('raw_attributes', 'not like', '%59968e2a90ed37d309bb00d2e4423600%')
                ->where('raw_attributes', 'not like', '%no-photo%')
                ->where('raw_attributes', 'not like', '%no_photo%')
                ->where('raw_attributes', 'not like', '%placeholder%');

            return;
        }

        $query->where(function (Builder $builder) use ($source): void {
            $builder
                ->where('raw_attributes', 'like', '%"image_urls"%http%')
                ->orWhere('raw_attributes', 'like', '%"part_image_urls"%http%')
                ->orWhere('raw_attributes', 'like', '%"system_group_image_urls"%http%')
                ->orWhere('raw_attributes', 'like', '%"image_url"%http%')
                ->orWhere('raw_attributes', 'like', '%"primary_image_url"%http%');

            if ($source === 'driveparts') {
                $builder
                    ->orWhere('raw_attributes', 'like', '%"remote_image_urls"%http%')
                    ->orWhere('raw_attributes', 'like', '%"remote_image_url"%http%');
            }
        });
    }

    protected function whereHasRemoteTeslaOfficialDisplayImage(Builder $query): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $json = 'if(json_valid(raw_attributes), raw_attributes, json_object())';

            $query->where(function (Builder $builder) use ($json): void {
                $builder
                    ->whereRaw("json_search({$json}, 'one', 'http%', null, '$.image_urls[*]', '$.image_url', '$.primary_image_url') is not null")
                    ->orWhereRaw("json_search({$json}, 'one', 'http%', null, '$.system_group_image_urls[*]', '$.part_image_urls[*]') is not null");
            });

            return;
        }

        $query->where(function (Builder $builder): void {
            $builder
                ->where('raw_attributes', 'like', '%"image_urls"%http%')
                ->orWhere('raw_attributes', 'like', '%"image_url"%http%')
                ->orWhere('raw_attributes', 'like', '%"primary_image_url"%http%')
                ->orWhere('raw_attributes', 'like', '%"system_group_image_urls"%http%')
                ->orWhere('raw_attributes', 'like', '%"part_image_urls"%http%');
        });
    }

    protected function downloadImage(PartCatalogItem $item, string $url, array &$stats): ?string
    {
        $path = $this->imagePath($item, $url);
        if ($path === null) {
            $stats['catalog_images_failed'] = (int) ($stats['catalog_images_failed'] ?? 0) + 1;

            return null;
        }

        if (Storage::disk('public')->exists($path)) {
            $stats['catalog_images_existing'] = (int) ($stats['catalog_images_existing'] ?? 0) + 1;

            return $path;
        }

        try {
            $response = $this->http
                ->timeout(8)
                ->retry(1, 300)
                ->withHeaders($this->requestHeaders($url))
                ->get($url);
        } catch (Throwable) {
            $stats['catalog_images_failed'] = (int) ($stats['catalog_images_failed'] ?? 0) + 1;

            return null;
        }

        if (! $response->ok() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            $stats['catalog_images_failed'] = (int) ($stats['catalog_images_failed'] ?? 0) + 1;

            return null;
        }

        Storage::disk('public')->put($path, $response->body());
        $this->flattenTransparentTeslaOfficialPng($item, $path);
        $path = $this->deduplicateTeslaOfficialResourceImage($item, $path);
        $stats['catalog_images_downloaded'] = (int) ($stats['catalog_images_downloaded'] ?? 0) + 1;

        return $path;
    }

    protected function deduplicateTeslaOfficialResourceImage(PartCatalogItem $item, string $path): string
    {
        if ($item->source !== 'tesla_official'
            || ! str_contains($path, 'tesla-official/resources-images/')) {
            return $path;
        }

        $disk = Storage::disk('public');
        $fullPath = $disk->path($path);
        if (! is_file($fullPath)) {
            return $path;
        }

        $hash = hash_file('sha256', $fullPath);
        if (! is_string($hash) || $hash === '') {
            return $path;
        }

        foreach ($disk->files('tesla-official/resources-images') as $existingPath) {
            if ($existingPath === $path) {
                continue;
            }

            $existingFullPath = $disk->path($existingPath);
            if (! is_file($existingFullPath)
                || filesize($existingFullPath) !== filesize($fullPath)
                || hash_file('sha256', $existingFullPath) !== $hash) {
                continue;
            }

            $disk->delete($path);

            return $existingPath;
        }

        return $path;
    }

    protected function flattenTransparentTeslaOfficialPng(PartCatalogItem $item, string $path): void
    {
        if ($item->source !== 'tesla_official'
            || ! str_contains($path, 'tesla-official/resources-images/')
            || ! str_ends_with(strtolower($path), '.png')
            || ! function_exists('imagecreatefrompng')) {
            return;
        }

        $fullPath = Storage::disk('public')->path($path);
        $image = @imagecreatefrompng($fullPath);

        if (! $image) {
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);

        if (! $canvas) {
            imagedestroy($image);

            return;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagepng($canvas, $fullPath);
        imagedestroy($canvas);
        imagedestroy($image);
    }

    protected function imagePath(PartCatalogItem $item, string $url): ?string
    {
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (! is_string($urlPath) || $urlPath === '') {
            return null;
        }

        $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION) ?: 'jpg');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
            $extension = 'jpg';
        }

        $name = Str::slug(Str::limit(pathinfo($urlPath, PATHINFO_FILENAME) ?: sha1($url), 90, ''), '-');
        if ($name === '') {
            $name = sha1($url);
        }

        if ($item->source === 'tesla_official' && str_contains((string) parse_url($url, PHP_URL_HOST), 'epc.tesla.com')) {
            return 'tesla-official/resources-images/'.$name.'-'.substr(sha1($url), 0, 12).'.'.$extension;
        }

        $partNumber = Str::slug((string) ($item->part_number ?: 'item-'.$item->id), '-');
        if ($partNumber === '') {
            $partNumber = 'item-'.$item->id;
        }

        return 'competitor-catalog/'.$item->source.'/'.$partNumber.'/'.$name.'-'.substr(sha1($url), 0, 12).'.'.$extension;
    }

    protected function remoteImageIdentityKey(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (str_contains($path, '/image-cache/') && isset($query['f']) && is_string($query['f'])) {
            return 'image-cache:'.$query['f'];
        }

        if (str_contains((string) parse_url($url, PHP_URL_HOST), 'drive-parts.com.ua')
            && preg_match('~/content/images/(\d+)/[^/]+/([^/?#]+)$~i', $path, $matches) === 1) {
            return '/content/images/'.$matches[1].'/'.$matches[2];
        }

        if (str_contains($path, '/resources/images/')) {
            return (string) preg_replace('/\.(?:svg|png|jpe?g|webp)$/iu', '', $path);
        }

        return $url;
    }

    protected function normalizedRemoteImageUrl(string $source, string $url): string
    {
        if ($source !== 'driveparts' || ! str_contains((string) parse_url($url, PHP_URL_HOST), 'drive-parts.com.ua')) {
            return $url;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('~/content/images/(\d+)/[^/]+/([^/?#]+)$~i', $path, $matches) !== 1) {
            return $url;
        }

        return 'https://drive-parts.com.ua/content/images/'.$matches[1].'/'.$this->drivePartsImageSizeSegment($path).'/'.$matches[2];
    }

    protected function remoteImageSortScore(string $url): int
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('~/(\d+)x(\d+)[^/]*/[^/]+$~i', $path, $matches) === 1) {
            return ((int) $matches[1]) * ((int) $matches[2]);
        }

        return 0;
    }

    protected function drivePartsImageSizeSegment(string $path): string
    {
        return self::DRIVEPARTS_PREFERRED_IMAGE_SIZE_SEGMENT;
    }

    protected function isRemoteImageUrl(string $url): bool
    {
        $url = trim($url);

        return preg_match('~^https?://.+\.(?:jpe?g|png|webp|svg)(?:[?#].*)?$~iu', $url) === 1;
    }

    protected function isPlaceholderImageUrl(string $source, string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($source === 'tcarservice' && preg_match('~/storage/editor/fotos/(?:6f46fee0ab4e187090a1f63b7a570bb2|59968e2a90ed37d309bb00d2e4423600)_\d+\.(?:jpe?g|png|webp)$~i', $path) === 1) {
            return true;
        }

        if ($source === 'driveparts' && preg_match('~/(?:65112127046566|63823657639696|44052052692034|21836359960804)\.(?:jpe?g|png|webp)$~i', $path) === 1) {
            return true;
        }

        if ($source === 'teslacompany' && str_contains(strtolower($url), 'cap.jpg')) {
            return true;
        }

        return str_contains($path, 'no-photo')
            || str_contains($path, 'no_photo')
            || str_contains($path, 'placeholder');
    }

    protected function requestHeaders(string $url): array
    {
        $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (str_contains($host, 'epc.tesla.com')) {
            return [
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            ];
        }

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
        ];

        $headers['Referer'] = is_string($origin) ? $origin.'/' : '';

        if (str_contains($host, 'drive-parts.com.ua')) {
            $headers['Cookie'] = 'challenge_passed='.self::DRIVE_PARTS_CHALLENGE_COOKIE;
        }

        return $headers;
    }
}
