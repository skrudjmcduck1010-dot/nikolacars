<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Support\PartCatalogLanguageMarkerConflict;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartCatalogLanguageMarkerNameRebuilder
{
    public function rebuild(?array $markers = null): array
    {
        $stats = [
            'teslapartsukraine' => 0,
            'tsk' => 0,
        ];

        if (! Schema::hasTable('part_catalog_items')
            || ! Schema::hasColumn('part_catalog_items', 'name_ru')
            || ! Schema::hasColumn('part_catalog_items', 'name_ua')) {
            return $stats;
        }

        foreach ($this->importers() as $source => $importer) {
            DB::table('part_catalog_items')
                ->where('source', $source)
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->when($markers !== null && $markers !== [], function ($query) use ($markers): void {
                    $query->where(function ($query) use ($markers): void {
                        foreach ($markers as $marker) {
                            $marker = trim((string) $marker);

                            if ($marker !== '') {
                                $query->orWhere('name', 'like', '%'.$marker.'%');
                            }
                        }
                    });
                })
                ->orderBy('id')
                ->chunkById(200, function ($items) use ($source, $importer, &$stats): void {
                    foreach ($items as $item) {
                        if ($this->rebuildItem($item, $importer)) {
                            $stats[$source]++;
                        }
                    }
                });
        }

        return $stats;
    }

    protected function importers(): array
    {
        return [
            'teslapartsukraine' => app(TeslaPartsUkraineCatalogImporter::class),
            'tsk' => app(TskCatalogImporter::class),
        ];
    }

    protected function rebuildItem(object $item, object $importer): bool
    {
        $name = trim((string) $item->name);
        $payload = $importer->localizedNamePayload($name);
        $detection = $importer->localizedNameDetection($name);
        $rawAttributes = $this->rawAttributes($item->raw_attributes);
        $originalRawAttributes = $rawAttributes;
        $sourceName = trim((string) data_get($rawAttributes, 'original_name', ''));
        $updates = [];

        foreach (['ru' => 'name_ru', 'ua' => 'name_ua'] as $locale => $column) {
            if ($this->isLocked($item, $rawAttributes, $locale, $column)) {
                continue;
            }

            $current = trim((string) $item->{$column});
            $shouldHaveValue = array_key_exists($column, $payload)
                && $this->normalize((string) $payload[$column]) === $this->normalize($name);

            if ($shouldHaveValue) {
                if ($this->normalize($current) !== $this->normalize($name)) {
                    $updates[$column] = $payload[$column];
                }

                $rawAttributes = PartCatalogLanguageMarkerConflict::apply($rawAttributes, $locale, $detection);

                continue;
            }

            if ($this->matchesAutofilledSourceName($current, $name, $sourceName)) {
                $updates[$column] = null;
                $this->forgetNameSource($rawAttributes, $locale);
            }
        }

        if ($updates === [] && $rawAttributes === $originalRawAttributes) {
            return false;
        }

        $updates['raw_attributes'] = $rawAttributes;

        PartCatalogItem::query()
            ->findOrFail($item->id)
            ->forceFill($updates)
            ->save();

        return true;
    }

    protected function rawAttributes(mixed $value): array
    {
        return PartCatalogRawAttributes::fromValue($value);
    }

    protected function isLocked(object $item, array $rawAttributes, string $locale, string $column): bool
    {
        $lockColumn = $column.'_manually_locked_at';

        return (Schema::hasColumn('part_catalog_items', $lockColumn) && ! empty($item->{$lockColumn}))
            || ! empty(data_get($rawAttributes, 'manual_name_locks.'.$locale));
    }

    protected function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
    }

    protected function matchesAutofilledSourceName(string $current, string $name, string $sourceName): bool
    {
        $current = $this->normalize($current);

        if ($current === '') {
            return false;
        }

        foreach ([$name, $sourceName] as $candidate) {
            if ($candidate !== '' && $current === $this->normalize($candidate)) {
                return true;
            }
        }

        return $this->withoutOriginWords($current) === $this->withoutOriginWords($this->normalize($name));
    }

    protected function withoutOriginWords(string $value): string
    {
        $value = preg_replace('/(?<![\pL\pN])(?:аналог|оригинал|оригінал|бв|бу|б\s*\/\s*у)(?![\pL\pN])/iu', ' ', $value) ?? $value;

        return $this->normalize($value);
    }

    protected function forgetNameSource(array &$rawAttributes, string $locale): void
    {
        unset(
            $rawAttributes['name_source_url_'.$locale],
            $rawAttributes['name_source_site_'.$locale],
            $rawAttributes['name_source_item_id_'.$locale],
            $rawAttributes['name_source_type_'.$locale],
            $rawAttributes['name_source_marker_'.$locale],
            $rawAttributes['name_language_marker_conflict_'.$locale]
        );

        if ($locale === 'ru') {
            unset($rawAttributes['name_source_url'], $rawAttributes['name_source_site']);
        }
    }
}
