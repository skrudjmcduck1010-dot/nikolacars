<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\WpBakeryRenderer;

class Page extends Model
{
    protected $fillable = [
        'wp_id','path','slug',
        'title_uk','content_uk','meta_title_uk','meta_desc_uk','canonical_uk','schema_uk',
        'title_ru','content_ru','meta_title_ru','meta_desc_ru','canonical_ru','schema_ru',
        'noindex'
    ];

    public function toViewData(string $locale): array
    {
        $r = app(WpBakeryRenderer::class);

        $title = $locale === 'ru' ? $this->title_ru : $this->title_uk;
        $content = $locale === 'ru' ? $this->content_ru : $this->content_uk;

        return [
            'locale' => $locale,
            'path' => $this->path,
            'title' => $title,
            'content' => $r->render($content ?? ''),
            'meta_title' => $locale === 'ru' ? ($this->meta_title_ru ?: $title) : ($this->meta_title_uk ?: $title),
            'meta_desc' => $locale === 'ru' ? $this->meta_desc_ru : $this->meta_desc_uk,
            'canonical' => $locale === 'ru' ? $this->canonical_ru : $this->canonical_uk,
            'schema' => $locale === 'ru' ? $this->schema_ru : $this->schema_uk,
            'noindex' => (bool)$this->noindex,
        ];
    }
}
