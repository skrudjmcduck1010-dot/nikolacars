<?php

namespace App\Services;

/**
 * Упрощённый рендерер WPBakery shortcodes.
 * Цель: сохранить текст/заголовки слово-в-слово и превратить базовую сетку в HTML.
 * Не пытается воспроизвести 1:1 дизайн темы WordPress.
 */
class WpBakeryRenderer
{
    public function render(string $content): string
    {
        if ($content === '') return '';

        // 1) Уберём обёртки Gutenberg
        $content = preg_replace('/<!--\s*\/?.*?\s*-->/s', '', $content);

        // 2) Частые VC shortcodes -> HTML
        $replacements = [
            '/\[vc_row[^\]]*\]/i' => '<section class="vc-row">',
            '/\[\/vc_row\]/i' => '</section>',
            '/\[vc_row_inner[^\]]*\]/i' => '<div class="vc-row-inner">',
            '/\[\/vc_row_inner\]/i' => '</div>',
            '/\[vc_column[^\]]*\]/i' => '<div class="vc-col">',
            '/\[\/vc_column\]/i' => '</div>',
            '/\[vc_column_inner[^\]]*\]/i' => '<div class="vc-col-inner">',
            '/\[\/vc_column_inner\]/i' => '</div>',
            '/\[vc_separator[^\]]*\]/i' => '<hr/>',
        ];
        foreach ($replacements as $pattern => $replace) {
            $content = preg_replace($pattern, $replace, $content);
        }

        // vc_custom_heading
        $content = preg_replace_callback('/\[vc_custom_heading([^\]]*)\]/i', function ($m) {
            $atts = $this->parseAttributes($m[1]);
            $text = $atts['text'] ?? '';
            $level = $atts['level'] ?? 'h2';
            $level = in_array(strtolower($level), ['h1','h2','h3','h4','h5','h6']) ? strtolower($level) : 'h2';
            return "<{$level}>" . e($text) . "</{$level}>";
        }, $content);

        // vc_column_text wraps inner HTML
        $content = preg_replace('/\[vc_column_text[^\]]*\]/i', '<div class="vc-text">', $content);
        $content = preg_replace('/\[\/vc_column_text\]/i', '</div>', $content);

        // vc_single_image -> img (если есть image URL — часто нет; тогда оставляем заглушку)
        $content = preg_replace_callback('/\[vc_single_image([^\]]*)\]/i', function ($m) {
            $atts = $this->parseAttributes($m[1]);
            $img = $atts['image'] ?? null;
            // В WPBakery image — это attachment ID. Без wp_get_attachment_url мы не узнаем URL из дампа,
            // поэтому просто оставляем комментарий, чтобы вы могли вручную заменить при необходимости.
            return "<!-- vc_single_image attachment_id={$img} -->";
        }, $content);

        // rev_slider
        $content = preg_replace('/\[rev_slider[^\]]*\]/i', '<!-- rev_slider removed -->', $content);

        // 3) Удалим любые оставшиеся shortcodes вида [xxxx ...]
        // но стараемся не ломать обычные квадратные скобки в тексте
        $content = preg_replace('/\[(?!\s*\d)([^\[\]\/\s]+)([^\]]*)\]/', '', $content);
        $content = preg_replace('/\[\/(?!\s*\d)([^\[\]\/\s]+)\]/', '', $content);

        // 4) Мини-чистка
        $content = str_replace("\r", "", $content);

        return $content;
    }

    private function parseAttributes(string $raw): array
    {
        $atts = [];
        // matches key="value" or key='value' or key=value
        if (preg_match_all('/(\w+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s\"]+)/', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $key = $m[1];
                $val = $m[2];
                $val = trim($val, "\"'");
                $atts[$key] = $val;
            }
        }
        return $atts;
    }
}
