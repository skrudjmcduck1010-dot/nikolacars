<?php

use App\Services\TeslaPartsUkraineCatalogImporter;
use App\Services\TskCatalogImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_items')
            || ! Schema::hasColumn('part_catalog_items', 'name_ru')
            || ! Schema::hasColumn('part_catalog_items', 'name_ua')) {
            return;
        }

        foreach ([
            'teslapartsukraine' => app(TeslaPartsUkraineCatalogImporter::class),
            'tsk' => app(TskCatalogImporter::class),
        ] as $source => $importer) {
            DB::table('part_catalog_items')
                ->where('source', $source)
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->orderBy('id')
                ->chunkById(200, function ($items) use ($importer): void {
                    foreach ($items as $item) {
                        $name = trim((string) $item->name);
                        $payload = $importer->localizedNamePayload($name);
                        $detection = $importer->localizedNameDetection($name);
                        $rawAttributes = $this->rawAttributes($item->raw_attributes);
                        $originalRawAttributes = $rawAttributes;
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

                                $rawAttributes = $this->withLocalizedNameMarkerConflict($rawAttributes, $locale, $detection);

                                continue;
                            }

                            if ($this->normalize($current) === $this->normalize($name)) {
                                $updates[$column] = null;
                                $this->forgetNameSource($rawAttributes, $locale);
                            }
                        }

                        if ($updates === [] && $rawAttributes === $originalRawAttributes) {
                            continue;
                        }

                        $updates['raw_attributes'] = json_encode($rawAttributes, JSON_UNESCAPED_UNICODE);
                        $updates['updated_at'] = now();

                        DB::table('part_catalog_items')->where('id', $item->id)->update($updates);
                    }
                });
        }
    }

    public function down(): void
    {
        //
    }

    private function rawAttributes(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isLocked(object $item, array $rawAttributes, string $locale, string $column): bool
    {
        $lockColumn = $column.'_manually_locked_at';

        return (Schema::hasColumn('part_catalog_items', $lockColumn) && ! empty($item->{$lockColumn}))
            || ! empty(data_get($rawAttributes, 'manual_name_locks.'.$locale));
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
    }

    private function forgetNameSource(array &$rawAttributes, string $locale): void
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

    private function withLocalizedNameMarkerConflict(array $rawAttributes, string $locale, array $detection): array
    {
        unset($rawAttributes['name_language_marker_conflict_'.$locale]);

        $conflict = $detection['conflict'] ?? null;

        if (($detection['source'] ?? null) !== 'language_marker'
            || ! is_array($conflict)
            || (int) ($conflict['count'] ?? 0) <= 0) {
            return $rawAttributes;
        }

        $rawAttributes['name_language_marker_conflict_'.$locale] = [
            'locale' => $conflict['locale'] ?? null,
            'count' => (int) ($conflict['count'] ?? 0),
            'markers' => array_values(array_filter((array) ($conflict['markers'] ?? []))),
        ];

        return $rawAttributes;
    }
};
