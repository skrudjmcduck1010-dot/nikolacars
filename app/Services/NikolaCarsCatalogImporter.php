<?php

namespace App\Services;

use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use Illuminate\Support\Str;

class NikolaCarsCatalogImporter
{
    protected string $source = 'nikolacars';

    protected ?array $knownDonorVins = null;

    public function import(string $path, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $fresh = (bool) ($options['fresh'] ?? false);
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $imagesDir = (string) ($options['images_dir'] ?? base_path('NC/prod'));
        $publicImagesDir = (string) ($options['public_images_dir'] ?? public_path('nikolacars/prod'));
        $publicImagePrefix = trim((string) ($options['public_image_prefix'] ?? 'nikolacars/prod'), '/');

        $stats = [
            'rows_read' => 0,
            'rows_skipped' => 0,
            'donor_products_skipped_unchecked' => 0,
            'categories_saved' => 0,
            'products_saved' => 0,
            'products_deleted' => 0,
            'images_linked' => 0,
            'images_copied' => 0,
            'donor_vins_found' => 0,
        ];

        if (! is_file($path)) {
            return $stats + ['error' => "File not found: {$path}"];
        }

        if ($fresh && ! $dryRun) {
            $stats['products_deleted'] = PartCatalogItem::query()->where('source', $this->source)->delete();
            PartCatalogCategory::query()->where('source', $this->source)->delete();
        }

        foreach ($this->rows($path) as $row) {
            $stats['rows_read']++;

            $code = $this->clean($row['code'] ?? '');
            $name = $this->clean($row['name'] ?? '');
            if ($code === '' || $name === '') {
                $stats['rows_skipped']++;

                continue;
            }

            $categorySegments = $this->categorySegments($row);
            if ($this->isWorkCategory($categorySegments)) {
                $stats['rows_skipped']++;

                continue;
            }

            $donorVin = $this->donorVin($row, $categorySegments);
            if ($donorVin !== null) {
                $stats['donor_vins_found']++;

                if (! $this->isCheckedDonorProduct($row, $categorySegments)) {
                    $stats['rows_skipped']++;
                    $stats['donor_products_skipped_unchecked']++;

                    continue;
                }
            }

            $images = $this->images($code, $imagesDir, $publicImagesDir, $publicImagePrefix, $dryRun);
            $stats['images_linked'] += count($images['urls']);
            $stats['images_copied'] += $images['copied'];

            if (! $dryRun) {
                $category = $this->productCategory($categorySegments, $row, $donorVin, $images['urls'][0] ?? null);
                $stats['categories_saved'] += count($category['created']);

                $item = PartCatalogItem::query()->updateOrCreate(
                    ['source_url' => "nikolacars://product/{$code}"],
                    $this->productPayload($category['category'], $row, $code, $name, $donorVin, $images['urls'])
                );
                app(NikolaCarsTeslaCategoryResolver::class)->resolveItem($item);
                app(NikolaCarsCatalogProductSyncService::class)->syncItem($item);
                $stats['products_saved']++;
            }

            $this->progress($progress, $verbose, "NikolaCars {$stats['rows_read']}: {$code} {$name}");
        }

        return $stats;
    }

    protected function rows(string $path): iterable
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        if (fgetcsv($handle) === false) {
            fclose($handle);

            return;
        }

        $headers = ['code', 'part_number', 'name', 'barcode', 'category', 'price', 'stock', 'images'];

        while (($values = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[(string) $header] = $values[$index] ?? '';
            }

            yield $row;
        }

        fclose($handle);
    }

    protected function productCategory(array $segments, array $row, ?string $donorVin, ?string $previewImageUrl): array
    {
        $created = [];
        $modelLabel = $this->modelLabel($row, $segments);
        $modelName = $this->modelName($modelLabel);
        $donorName = $this->donorName($segments, $row, $donorVin, $modelLabel);
        $parent = null;

        $levels = collect([$donorName])
            ->merge(array_slice($segments, 3))
            ->filter()
            ->values()
            ->all();

        if ($levels === []) {
            $levels = ['NikolaCars'];
        }

        foreach ($levels as $depth => $name) {
            $sourceUrl = 'nikolacars://category/'.md5(implode('|', array_slice($levels, 0, $depth + 1)));
            $category = PartCatalogCategory::query()->updateOrCreate(
                ['source_url' => $sourceUrl],
                [
                    'source' => $this->source,
                    'parent_id' => $parent?->id,
                    'depth' => $depth,
                    'code' => $depth === 0 ? $donorVin : null,
                    'name' => $name,
                    'name_ru' => $this->russianName($name),
                    'name_ua' => $name,
                    'model_label' => $modelLabel,
                    'model_name' => $modelName,
                    'sort_order' => $depth,
                    'preview_image_url' => $previewImageUrl,
                    'children_scanned_at' => now(),
                    'products_scanned_at' => now(),
                ]
            );

            if ($category->wasRecentlyCreated) {
                $created[] = $category->id;
            }

            $parent = $category;
        }

        return ['category' => $parent, 'created' => $created];
    }

    protected function productPayload(
        PartCatalogCategory $category,
        array $row,
        string $code,
        string $name,
        ?string $donorVin,
        array $imageUrls
    ): array {
        $price = $this->decimal($row['price'] ?? null);
        $stock = $this->decimal($row['stock'] ?? null);
        $barcode = $this->clean($row['barcode'] ?? '');
        $partNumber = $this->normalizePartNumber($this->clean($row['part_number'] ?? ''));
        $segments = $this->categorySegments($row);
        $categoryDisplay = $this->categoryDisplay($segments, $row);
        $donorDamageStatus = $this->donorDamageStatus($row, $segments);

        return [
            'part_catalog_category_id' => $category->id,
            'source' => $this->source,
            'part_number' => $partNumber !== '' ? $partNumber : null,
            'name' => $name,
            'name_ru' => $this->russianName($name),
            'name_ua' => $name,
            'price_amount' => $price,
            'currency' => $price !== null ? 'USD' : null,
            'model_label' => $category->model_label,
            'model_name' => $category->model_name,
            'main_category_name' => $segments[3] ?? $category->name,
            'subcategory_name' => $segments[4] ?? null,
            'node_name' => $category->name,
            'compatibility_text' => $categoryDisplay,
            'quality' => $donorDamageStatus,
            'availability' => $stock !== null ? (rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.').' шт') : null,
            'raw_attributes' => array_filter([
                'code' => $code,
                'barcode' => $barcode,
                'donor_vin' => $donorVin,
                'donor_label' => $segments[2] ?? null,
                'donor_damage_status' => $donorDamageStatus,
                'category_path' => $this->clean($row['category'] ?? ''),
                'category_display' => $categoryDisplay,
                'stock_quantity' => $stock,
                'price_text' => $this->clean($row['price'] ?? ''),
                'image_urls' => $imageUrls,
                'source_row' => collect($row)->map(fn ($value) => is_string($value) ? $this->clean($value) : $value)->all(),
            ], fn ($value): bool => $value !== '' && $value !== [] && $value !== null),
            'source_updated_at' => now(),
        ];
    }

    protected function categorySegments(array $row): array
    {
        return collect(explode(';', $this->clean($row['category'] ?? '')))
            ->map(fn (string $segment): string => $this->clean($segment))
            ->filter()
            ->values()
            ->all();
    }

    protected function isWorkCategory(array $segments): bool
    {
        return Str::lower($segments[0] ?? '') === 'роботи';
    }

    protected function categoryDisplay(array $segments, array $row): ?string
    {
        foreach ($segments as $segment) {
            $normalized = Str::lower($segment);

            if (Str::contains($normalized, 'залишки') || $normalized === 'tesla валера') {
                return $segment;
            }
        }

        return $this->clean($row['category'] ?? '') ?: null;
    }

    protected function russianName(string $name): string
    {
        $replacements = [
            'Основна' => 'Основная',
            'Двері' => 'Двери',
            'двері' => 'двери',
            'задні' => 'задние',
            'передні' => 'передние',
            'ліві' => 'левые',
            'праві' => 'правые',
            'ліва' => 'левая',
            'права' => 'правая',
            'лівий' => 'левый',
            'правий' => 'правый',
            'передній' => 'передний',
            'задній' => 'задний',
            'переднього' => 'переднего',
            'заднього' => 'заднего',
            'Підрамник' => 'Подрамник',
            'підрамник' => 'подрамник',
            'Підкрилок' => 'Подкрылок',
            'підкрилок' => 'подкрылок',
            'важіль' => 'рычаг',
            'верхній' => 'верхний',
            'нижній' => 'нижний',
            'поздовжній' => 'продольный',
            'поперечний' => 'поперечный',
            'Скло' => 'Стекло',
            'скло' => 'стекло',
            'кришки' => 'крышки',
            'Ліхтар' => 'Фонарь',
            'ліхтар' => 'фонарь',
            'підсвічування' => 'подсветки',
            'зовнішня' => 'наружная',
            'ремінь' => 'ремень',
            'безпеки' => 'безопасности',
            'водійський' => 'водительский',
            'Маточина' => 'Ступица',
            'маточина' => 'ступица',
            'Запобіжник' => 'Предохранитель',
            'запобіжник' => 'предохранитель',
            'переднє' => 'переднее',
            'заднє' => 'заднее',
            'ліве' => 'левое',
            'праве' => 'правое',
            'Cкло' => 'Стекло',
            'Чорна' => 'Черная',
            'чорна' => 'черная',
            'Сірий' => 'Серый',
            'сірий' => 'серый',
            'Синій' => 'Синий',
            'синій' => 'синий',
            'Блакитна' => 'Голубая',
            'блакитна' => 'голубая',
            'внутрішня' => 'внутренняя',
            'олія' => 'масло',
            'мастило' => 'смазка',
            'трансмісійна' => 'трансмиссионная',
            'кришка' => 'крышка',
        ];

        return strtr($name, $replacements);
    }

    protected function normalizePartNumber(string $partNumber): string
    {
        $partNumber = Str::upper(str_replace(' ', '', trim($partNumber)));

        if (preg_match('/^(\d{7})([A-Z0-9]{2})([A-Z0-9])$/', $partNumber, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $partNumber;
    }

    protected function isCheckedDonorProduct(array $row, array $segments): bool
    {
        return $this->donorDamageStatus($row, $segments) !== null
            || $this->isLeftoversDonorVin($this->donorVin($row, $segments));
    }

    protected function donorDamageStatus(array $row, array $segments): ?string
    {
        $texts = collect($segments)
            ->push($this->clean($row['name'] ?? ''))
            ->map(fn (string $value): string => $this->normalizeDamageText($value))
            ->filter()
            ->values();

        if ($texts->contains(fn (string $text): bool => str_contains($text, 'разбит') || str_contains($text, 'розбит'))) {
            return null;
        }

        foreach ($texts as $text) {
            if (str_contains($text, 'без поврежден') || str_contains($text, 'без пошкоджен')) {
                return "\u{0411}\u{0435}\u{0437} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{0439}";
            }

            if (str_contains($text, 'легк') || str_contains($text, 'легкi')) {
                return "\u{041B}\u{0435}\u{0433}\u{043A}\u{0438}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}";
            }

            if (str_contains($text, 'сильн') || str_contains($text, 'сильнi')) {
                return "\u{0421}\u{0438}\u{043B}\u{044C}\u{043D}\u{044B}\u{0435} \u{043F}\u{043E}\u{0432}\u{0440}\u{0435}\u{0436}\u{0434}\u{0435}\u{043D}\u{0438}\u{044F}";
            }
        }

        return null;
    }

    protected function normalizeDamageText(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), [
            "\u{0451}" => "\u{0435}",
            "\u{0456}" => 'i',
            "\u{0457}" => 'i',
        ]);
    }

    protected function donorVin(array $row, array $segments): ?string
    {
        $text = collect($segments)->push($this->clean($row['name'] ?? ''))->implode(' ');

        if (preg_match('/\b[A-Z0-9]{17}\b/i', $text, $matches) === 1) {
            $vin = strtoupper($matches[0]);

            return strtr($vin, ['O' => '0', 'I' => '1']);
        }

        foreach ($segments as $segment) {
            $candidate = $this->clean($segment);
            $donorVin = $this->knownDonorVin($candidate);
            if ($donorVin !== null) {
                return $donorVin;
            }
        }

        return null;
    }

    protected function knownDonorVins(): array
    {
        return $this->knownDonorVins ??= DonorCar::query()
            ->pluck('vin')
            ->map(fn (string $vin): string => $this->clean($vin))
            ->filter()
            ->values()
            ->all();
    }

    protected function knownDonorVin(string $candidate): ?string
    {
        $candidate = $this->clean($candidate);
        if ($candidate === '') {
            return null;
        }

        $normalizedCandidate = Str::lower($candidate);

        foreach ($this->knownDonorVins() as $vin) {
            if (Str::lower($vin) === $normalizedCandidate) {
                return $vin;
            }
        }

        return null;
    }

    protected function isLeftoversDonorVin(?string $vin): bool
    {
        return $vin !== null && str_contains(Str::lower($vin), 'залишки');
    }

    protected function donorName(array $segments, array $row, ?string $donorVin, string $modelLabel): string
    {
        $candidate = $segments[2] ?? $this->clean($row['name'] ?? '');
        if ($candidate !== '') {
            return $candidate;
        }

        return trim($modelLabel.' '.($donorVin ?? 'NikolaCars'));
    }

    protected function images(string $code, string $imagesDir, string $publicDir, string $publicPrefix, bool $dryRun): array
    {
        $files = glob(rtrim($imagesDir, DIRECTORY_SEPARATOR.'/').'/'.$code.'_*.*') ?: [];
        natsort($files);

        $copied = 0;
        $urls = [];
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $basename = basename($file);
            $target = rtrim($publicDir, DIRECTORY_SEPARATOR.'/').DIRECTORY_SEPARATOR.$basename;

            if (! $dryRun) {
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }

                if (! is_file($target) || filesize($target) !== filesize($file)) {
                    copy($file, $target);
                    $copied++;
                }
            }

            $urls[] = asset($publicPrefix.'/'.$basename);
        }

        return ['urls' => $urls, 'copied' => $copied];
    }

    protected function modelLabel(array $row, array $segments): string
    {
        $text = Str::lower(implode(' ', array_merge($segments, [$this->clean($row['name'] ?? '')])));

        return match (true) {
            str_contains($text, 'model 3') || str_contains($text, 'м3') || str_contains($text, 'm3') => 'Model 3 06.2017 - 12.2023',
            str_contains($text, 'model x') || str_contains($text, 'мx') || str_contains($text, 'mx') => 'Model X 09.2015-02.2021',
            str_contains($text, 'model s') || str_contains($text, 'мs') || str_contains($text, 'ms') => 'Model S 02.2012-03.2016',
            default => 'Model Y 01.2020 - 01.2025',
        };
    }

    protected function modelName(string $modelLabel): string
    {
        return match (true) {
            str_contains($modelLabel, 'Model S') => 'Model S',
            str_contains($modelLabel, 'Model X') => 'Model X',
            str_contains($modelLabel, 'Model 3') => 'Model 3',
            default => 'Model Y',
        };
    }

    protected function decimal(mixed $value): ?float
    {
        $text = str_replace(["\xc2\xa0", ' '], '', $this->clean((string) $value));
        if (preg_match('/^\d{1,3}(,\d{3})+$/', $text) === 1) {
            $text = str_replace(',', '', $text);
        } else {
            $text = str_replace(',', '.', $text);
        }

        return is_numeric($text) ? round((float) $text, 3) : null;
    }

    protected function clean(?string $value): string
    {
        return trim(html_entity_decode(preg_replace('/\s+/u', ' ', (string) $value) ?: '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($verbose && $progress !== null) {
            $progress($message);
        }
    }
}
