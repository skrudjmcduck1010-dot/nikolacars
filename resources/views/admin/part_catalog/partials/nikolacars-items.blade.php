@php
    $nikolaCarsPriceSortLabel = $nikolaCarsSort === 'price'
        ? '&#1062;&#1077;&#1085;&#1072; &#1087;&#1088;&#1086;&#1076;&#1072;&#1078;&#1080; '.($nikolaCarsSortDirection === 'asc' ? '&#8593;' : '&#8595;')
        : '&#1062;&#1077;&#1085;&#1072; &#1087;&#1088;&#1086;&#1076;&#1072;&#1078;&#1080;';
    $nikolaCarsColorSwatchHex = function (?string $color): ?string {
        $normalized = mb_strtolower(trim((string) $color));

        if ($normalized === '') {
            return null;
        }

        return match (true) {
            preg_match('/\b(white|pearl)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0431}\u{0435}\u{043B}") || str_contains($normalized, "\u{0431}\u{0456}\u{043B}") => '#f8fafc',
            preg_match('/\b(black|obsidian)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0447}\u{0435}\u{0440}\u{043D}") || str_contains($normalized, "\u{0447}\u{043E}\u{0440}\u{043D}") => '#111827',
            preg_match('/\b(red|ultra)\b/u', $normalized) === 1 || str_contains($normalized, "\u{043A}\u{0440}\u{0430}\u{0441}\u{043D}") || str_contains($normalized, "\u{0447}\u{0435}\u{0440}\u{0432}\u{043E}\u{043D}") => '#dc2626',
            preg_match('/\b(blue|navy)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0441}\u{0438}\u{043D}") => '#2563eb',
            preg_match('/\b(grey|gray|silver|quicksilver|midnight)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0441}\u{0435}\u{0440}") || str_contains($normalized, "\u{0441}\u{0440}\u{0456}\u{0431}") => '#94a3b8',
            preg_match('/\b(green)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0437}\u{0435}\u{043B}\u{0435}\u{043D}") || str_contains($normalized, "\u{0437}\u{0435}\u{043B}") => '#16a34a',
            preg_match('/\b(yellow|gold)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0436}\u{0435}\u{043B}\u{0442}") || str_contains($normalized, "\u{0436}\u{043E}\u{0432}\u{0442}") || str_contains($normalized, "\u{0437}\u{043E}\u{043B}\u{043E}\u{0442}") => '#facc15',
            preg_match('/\b(brown|bronze)\b/u', $normalized) === 1 || str_contains($normalized, "\u{043A}\u{043E}\u{0440}\u{0438}\u{0447}") || str_contains($normalized, "\u{0431}\u{0440}\u{043E}\u{043D}\u{0437}") => '#92400e',
            default => null,
        };
    };
    $nikolaCarsColorSwatchStyle = fn (?string $hex): string => 'display:inline-block;width:16px;height:16px;margin:0 2px;vertical-align:middle;border:1px solid rgba(17,24,39,.22);border-radius:999px;background-color: '.($hex ?: '#e5e7eb').';box-shadow:inset 0 0 0 1px rgba(255,255,255,.62);';
    $nikolaCarsUsesDonorColorCategory = fn (iterable $categories): bool => collect($categories)
        ->contains(function (string $category): bool {
            $normalizedCategory = \Illuminate\Support\Str::lower($category);

            return \Illuminate\Support\Str::contains($normalizedCategory, ["\u{043A}\u{0443}\u{0437}\u{043E}\u{0432}", 'body'])
                || (
                    \Illuminate\Support\Str::contains($normalizedCategory, ["\u{0434}\u{0430}\u{0442}\u{0447}\u{0438}\u{043A}", 'sensor'])
                    && \Illuminate\Support\Str::contains($normalizedCategory, ["\u{043F}\u{0430}\u{0440}\u{043A}\u{043E}\u{0432}", 'parking'])
                );
        });
    $nikolaCarsUsesDonorColorItem = function (\App\Models\PartCatalogItem $item) use ($nikolaCarsUsesDonorColorCategory): bool {
        return $nikolaCarsUsesDonorColorCategory([
            app(\App\Services\NikolaCarsInventoryService::class)->displayCategory($item),
            (string) data_get($item->raw_attributes, 'category_display', ''),
            (string) data_get($item->raw_attributes, 'category_path', ''),
            (string) $item->main_category_name,
        ]);
    };
@endphp
<table class="nikolacars-parts-table" style="margin-top:12px;">
    <thead>
    <tr>
        <th>&#1060;&#1086;&#1090;&#1086;</th>
        <th>&#1050;&#1086;&#1076;</th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('part_number') }}">&#1040;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;</a></th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('name') }}">&#1053;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1077;</a></th>
        <th>&#1062;&#1074;&#1077;&#1090;</th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('vin') }}">&#1044;&#1086;&#1085;&#1086;&#1088;</a></th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('category') }}">&#1050;&#1072;&#1090;&#1077;&#1075;&#1086;&#1088;&#1080;&#1103;</a></th>
        <th>&#1057;&#1090;&#1072;&#1090;&#1091;&#1089;</th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('price') }}">{!! $nikolaCarsPriceSortLabel !!}</a></th>
        <th>&#1050;&#1091;&#1087;&#1080;&#1090;&#1100;</th>
        <th><a class="catalog-sort-link" href="{{ $nikolaCarsSortUrl('stock') }}">&#1054;&#1089;&#1090;&#1072;&#1090;&#1086;&#1082;</a></th>
        <th></th>
    </tr>
    </thead>
    <tbody data-nikolacars-items-body>
    @forelse($nikolaCarsItemGroups as $group)
        @include('admin.part_catalog.partials.nikolacars-item-row')
    @empty
        <tr data-nikolacars-empty-row>
            <td colspan="12" class="empty">{{ $showNikolaCarsSoldItems ? 'Проданные запчасти не найдены.' : 'Запчасти не найдены.' }}</td>
        </tr>
    @endforelse
    </tbody>
</table>
@include('admin.part_catalog.partials.nikolacars-create-dialog')
@include('admin.part_catalog.partials.nikolacars-cart')
<dialog class="catalog-photo-lightbox" data-catalog-photo-lightbox>
    <div class="catalog-photo-lightbox__toolbar">
        <span data-catalog-photo-counter></span>
        <button type="button" class="btn btn-secondary catalog-photo-lightbox__close" data-close-catalog-photo-lightbox aria-label="Закрыть">&times;</button>
    </div>
    <div class="catalog-photo-lightbox__stage">
        <button type="button" class="btn btn-secondary catalog-photo-lightbox__nav catalog-photo-lightbox__nav--prev" data-catalog-photo-prev aria-label="Предыдущее фото">&#8249;</button>
        <img src="" alt="" data-catalog-photo-lightbox-image>
        <button type="button" class="btn btn-secondary catalog-photo-lightbox__nav catalog-photo-lightbox__nav--next" data-catalog-photo-next aria-label="Следующее фото">&#8250;</button>
    </div>
</dialog>
