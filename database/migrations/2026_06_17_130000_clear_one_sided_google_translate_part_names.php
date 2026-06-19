<?php

use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_items')
            || ! Schema::hasColumn('part_catalog_items', 'name_ru')
            || ! Schema::hasColumn('part_catalog_items', 'name_ua')
            || ! Schema::hasColumn('part_catalog_items', 'raw_attributes')) {
            return;
        }

        DB::table('part_catalog_items')
            ->select(['id', 'name_ru', 'name_ua', 'raw_attributes'])
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query->whereNotNull('name_ru')
                            ->where('name_ru', '!=', '')
                            ->where(function ($query): void {
                                $query->whereNull('name_ua')->orWhere('name_ua', '');
                            });
                    })
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('name_ua')
                            ->where('name_ua', '!=', '')
                            ->where(function ($query): void {
                                $query->whereNull('name_ru')->orWhere('name_ru', '');
                            });
                    });
            })
            ->orderBy('id')
            ->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    $rawAttributes = PartCatalogRawAttributes::fromValue($item->raw_attributes ?? null);
                    $updates = [];

                    if ($this->shouldClearLocaleName($item, $rawAttributes, 'ru')) {
                        $updates['name_ru'] = null;
                        $rawAttributes = $this->withoutGoogleTranslateNameSource($rawAttributes, 'ru');
                    }

                    if ($this->shouldClearLocaleName($item, $rawAttributes, 'ua')) {
                        $updates['name_ua'] = null;
                        $rawAttributes = $this->withoutGoogleTranslateNameSource($rawAttributes, 'ua');
                    }

                    if ($updates === []) {
                        continue;
                    }

                    $updates['raw_attributes'] = json_encode($rawAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if (Schema::hasColumn('part_catalog_items', 'updated_at')) {
                        $updates['updated_at'] = now();
                    }

                    DB::table('part_catalog_items')
                        ->where('id', $item->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        // One-time data cleanup; cleared Google translations are intentionally not restored.
    }

    private function shouldClearLocaleName(object $item, array $rawAttributes, string $locale): bool
    {
        $column = $locale === 'ru' ? 'name_ru' : 'name_ua';
        $oppositeColumn = $locale === 'ru' ? 'name_ua' : 'name_ru';

        return trim((string) $item->{$column}) !== ''
            && trim((string) $item->{$oppositeColumn}) === ''
            && $this->isGoogleTranslateNameSource($rawAttributes, $locale);
    }

    private function isGoogleTranslateNameSource(array $rawAttributes, string $locale): bool
    {
        $site = trim((string) data_get($rawAttributes, 'name_source_site_'.$locale));
        $url = trim((string) data_get($rawAttributes, 'name_source_url_'.$locale));
        $type = trim((string) data_get($rawAttributes, 'name_source_type_'.$locale));

        if ($locale === 'ru') {
            $site = $site !== '' ? $site : trim((string) data_get($rawAttributes, 'name_source_site'));
            $url = $url !== '' ? $url : trim((string) data_get($rawAttributes, 'name_source_url'));
        }

        return strcasecmp($site, 'Google Translate') === 0
            || str_contains(strtolower($url), 'cloud.google.com/translate')
            || str_contains(strtolower($type), 'google_translate');
    }

    private function withoutGoogleTranslateNameSource(array $rawAttributes, string $locale): array
    {
        foreach (['url', 'site', 'item_id', 'type', 'marker'] as $sourceKey) {
            unset($rawAttributes['name_source_'.$sourceKey.'_'.$locale]);
        }

        if ($locale === 'ru' || strcasecmp(trim((string) data_get($rawAttributes, 'name_source_site')), 'Google Translate') === 0) {
            unset($rawAttributes['name_source_url'], $rawAttributes['name_source_site']);
        }

        return array_filter($rawAttributes, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
};
