<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Page;
use App\Models\Post;

class ImportNikolaCars extends Command
{
    protected $signature = 'nikolacars:import {--file=storage/app/nikolacars_export.json}';
    protected $description = 'Import NikolaCars export JSON into Laravel DB';

    public function handle(): int
    {
        $file = $this->option('file');
        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $json = json_decode(file_get_contents($file), true);
        if (!$json) {
            $this->error("Invalid JSON");
            return 1;
        }

        $this->info('Importing pages...');
        foreach ($json['pages'] ?? [] as $p) {
            Page::updateOrCreate(
                ['path' => $p['path']],
                [
                    'wp_id' => $p['wp_id'] ?? null,
                    'slug' => $p['slug'] ?? null,
                    'title_uk' => $p['title_uk'] ?? null,
                    'content_uk' => $p['content_uk'] ?? null,
                    'meta_title_uk' => $p['meta_title_uk'] ?? null,
                    'meta_desc_uk' => $p['meta_desc_uk'] ?? null,
                    'canonical_uk' => $p['canonical_uk'] ?? null,
                    'schema_uk' => $p['schema_uk'] ?? null,

                    'title_ru' => $p['title_ru'] ?? null,
                    'content_ru' => $p['content_ru'] ?? null,
                    'meta_title_ru' => $p['meta_title_ru'] ?? null,
                    'meta_desc_ru' => $p['meta_desc_ru'] ?? null,
                    'canonical_ru' => $p['canonical_ru'] ?? null,
                    'schema_ru' => $p['schema_ru'] ?? null,

                    'noindex' => (bool)($p['noindex'] ?? false),
                ]
            );
        }

        $this->info('Importing posts...');
        foreach ($json['posts'] ?? [] as $p) {
            Post::updateOrCreate(
                ['path' => $p['path']],
                [
                    'wp_id' => $p['wp_id'] ?? null,
                    'slug' => $p['slug'] ?? null,
                    'category_slug' => $p['category_slug'] ?? null,
                    'published_at' => $p['published_at'] ?? null,
                    'title_uk' => $p['title_uk'] ?? null,
                    'content_uk' => $p['content_uk'] ?? null,
                    'meta_title_uk' => $p['meta_title_uk'] ?? null,
                    'meta_desc_uk' => $p['meta_desc_uk'] ?? null,
                    'canonical_uk' => $p['canonical_uk'] ?? null,
                    'schema_uk' => $p['schema_uk'] ?? null,

                    'title_ru' => $p['title_ru'] ?? null,
                    'content_ru' => $p['content_ru'] ?? null,
                    'meta_title_ru' => $p['meta_title_ru'] ?? null,
                    'meta_desc_ru' => $p['meta_desc_ru'] ?? null,
                    'canonical_ru' => $p['canonical_ru'] ?? null,
                    'schema_ru' => $p['schema_ru'] ?? null,

                    'noindex' => (bool)($p['noindex'] ?? false),
                ]
            );
        }

        $this->info('Done.');
        $this->warn('Примечание: WPBakery shortcodes рендерятся упрощённо. Для 1:1 дизайна нужны отдельные шаблоны/верстка.');
        return 0;
    }
}
