<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MAP = [
        'Model 3 06.2017 - 12.2023' => ['label' => 'Tesla Model 3', 'url' => 'https://teslacompany.com.ua/category/tesla-model-3-552761/', 'sort' => 10],
        'Model 3 Highland 01.2024 -' => ['label' => 'Tesla Model 3 highland', 'url' => 'https://teslacompany.com.ua/category/tesla-model-3-highland/', 'sort' => 20],
        'Model S 02.2012-03.2016' => ['label' => 'Tesla Model S', 'url' => 'https://teslacompany.com.ua/category/tesla-model-s-552783/', 'sort' => 30],
        'Model S Palladium 02.2021-05.2025' => ['label' => 'Tesla Model S Plaid', 'url' => 'https://teslacompany.com.ua/category/tesla-model-s-plaid-552805/', 'sort' => 40],
        'Model S2 04.2016-01.2021' => ['label' => 'Tesla Model S Restyle', 'url' => 'https://teslacompany.com.ua/category/tesla-model-s-restyle/', 'sort' => 50],
        'Model X 09.2015-02.2021' => ['label' => 'Tesla Model X', 'url' => 'https://teslacompany.com.ua/category/tesla-model-x-552816/', 'sort' => 60],
        'Model X Palladium 03.2021-05.2025' => ['label' => 'Tesla Model X Plaid', 'url' => 'https://teslacompany.com.ua/category/tesla-model-x-plaid-552827/', 'sort' => 70],
        'Model Y 01.2020 - 01.2025' => ['label' => 'Tesla Model Y', 'url' => 'https://teslacompany.com.ua/category/tesla-model-y-552772/', 'sort' => 80],
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            $oldPrefix = 'https://teslacompany.com.ua/catalog/'.str($old)->slug();
            $newPrefix = rtrim($new['url'], '/');

            DB::table('part_catalog_items')
                ->where('source', 'teslacompany')
                ->where('model_label', $old)
                ->update(['model_label' => $new['label']]);

            DB::table('part_catalog_categories')
                ->where('source', 'teslacompany')
                ->where('model_label', $old)
                ->update(['model_label' => $new['label']]);

            DB::table('part_catalog_categories')
                ->where('source', 'teslacompany')
                ->whereNotNull('parent_id')
                ->where('source_url', 'like', $oldPrefix.'/%')
                ->orderBy('id')
                ->lazyById()
                ->each(function (object $category) use ($oldPrefix, $newPrefix): void {
                    DB::table('part_catalog_categories')
                        ->where('id', $category->id)
                        ->update(['source_url' => preg_replace('#^'.preg_quote($oldPrefix, '#').'#', $newPrefix, (string) $category->source_url)]);
                });

            DB::table('part_catalog_categories')
                ->where('source', 'teslacompany')
                ->whereNull('parent_id')
                ->where('name', $old)
                ->update([
                    'name' => $new['label'],
                    'name_ru' => $new['label'],
                    'source_url' => $new['url'],
                    'sort_order' => $new['sort'],
                ]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            $oldPrefix = 'https://teslacompany.com.ua/catalog/'.str($old)->slug();
            $newPrefix = rtrim($new['url'], '/');

            DB::table('part_catalog_items')
                ->where('source', 'teslacompany')
                ->where('model_label', $new['label'])
                ->update(['model_label' => $old]);

            DB::table('part_catalog_categories')
                ->where('source', 'teslacompany')
                ->where('model_label', $new['label'])
                ->update(['model_label' => $old]);

            DB::table('part_catalog_categories')
                ->where('source', 'teslacompany')
                ->whereNotNull('parent_id')
                ->where('source_url', 'like', $newPrefix.'/%')
                ->orderBy('id')
                ->lazyById()
                ->each(function (object $category) use ($oldPrefix, $newPrefix): void {
                    DB::table('part_catalog_categories')
                        ->where('id', $category->id)
                        ->update(['source_url' => preg_replace('#^'.preg_quote($newPrefix, '#').'#', $oldPrefix, (string) $category->source_url)]);
                });

            DB::table('part_catalog_categories')
                ->where('source', 'teslacompany')
                ->whereNull('parent_id')
                ->where('name', $new['label'])
                ->update([
                    'name' => $old,
                    'name_ru' => $old,
                    'source_url' => 'https://teslacompany.com.ua/catalog/'.str($old)->slug(),
                ]);
        }
    }
};
