@if($showRootItemList)
@elseif($showCategoryBlocks)
    <div class="catalog-category-blocks">
        @forelse($categoryBlocks as $category)
            <section class="catalog-category-block">
                <div class="catalog-category-block__head">
                    <a class="catalog-category-title" href="{{ $categoryUrl($category) }}">
                        @if($category->preview_image_url)
                            <img src="{{ $category->preview_image_url }}" alt="Превью {{ $categoryName($category) }}" loading="lazy" decoding="async">
                        @else
                            <span class="catalog-category-image-placeholder">нет фото</span>
                        @endif
                        <span>
                            <strong>{{ $categoryName($category) }}</strong>
                            <em>
                                {{ $category->children_count }} подкатегорий
                                @if($category->branch_items_count !== null)
                                    · {{ $category->branch_items_count }} запчастей
                                @endif
                            </em>
                        </span>
                    </a>
                    @if(str_starts_with($category->source_url, 'http'))
                        <a class="catalog-source-link" href="{{ $category->source_url }}" target="_blank" rel="noopener">Оригинал</a>
                    @endif
                </div>

                <div class="catalog-subcategory-grid">
                    @forelse($category->children as $subcategory)
                        <div class="catalog-subcategory">
                            <a href="{{ $categoryUrl($subcategory) }}">
                                <strong>{{ $categoryName($subcategory) }}</strong>
                                <span>
                                    {{ $subcategory->children_count }} подразделов
                                    @if($subcategory->branch_items_count !== null)
                                        · {{ $subcategory->branch_items_count }} запчастей
                                    @endif
                                </span>
                            </a>
                            @if(str_starts_with($subcategory->source_url, 'http'))
                                <a class="catalog-source-link" href="{{ $subcategory->source_url }}" target="_blank" rel="noopener">Оригинал</a>
                            @endif
                        </div>
                    @empty
                        <div class="empty">Подкатегорий нет.</div>
                    @endforelse
                </div>
            </section>
        @empty
            <div class="empty">Категории модели не найдены.</div>
        @endforelse
    </div>
@else
    <table>
        <thead>
        <tr>
            @if($isModelLevel)
                <th>Превью</th>
            @endif
            <th>{{ $isModelLevel ? 'Модель Tesla' : 'Категория' }}</th>
            <th>{{ $isModelLevel ? 'Период выпуска' : 'Модель' }}</th>
            <th>{{ $isModelLevel ? 'Категории' : 'Подкатегории' }}</th>
            <th>Запчасти в ветке</th>
            <th>Оригинал</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse($categories as $category)
            <tr>
                @if($isModelLevel)
                    <td>
                        @if($category->preview_image_url)
                            <img class="table-preview catalog-model-preview" src="{{ $category->preview_image_url }}" alt="Превью {{ $categoryName($category) }}" loading="lazy" decoding="async">
                        @else
                            <span class="preview-placeholder">нет фото</span>
                        @endif
                    </td>
                @endif
                <td>
                    <a href="{{ $categoryUrl($category) }}">
                        <strong>{{ $isModelLevel && $catalog['source'] !== 'nikolacars' ? ($modelLabel($category) ?: $categoryName($category)) : $categoryName($category) }}</strong>
                    </a>
                    <div class="help">{{ $isModelLevel ? 'Модель / поколение' : 'Уровень '.$category->depth }}</div>
                </td>
                <td>
                    @if($isModelLevel)
                        @if($category->year_from || $category->year_to)
                            {{ $category->year_from ?: '—' }}–{{ $category->year_to ?: 'н.в.' }}
                        @else
                            —
                        @endif
                    @else
                        {{ $modelLabel($category) ?: '—' }}
                        @if($category->year_from || $category->year_to)
                            <div class="help">{{ $category->year_from ?: '—' }}–{{ $category->year_to ?: 'н.в.' }}</div>
                        @endif
                    @endif
                </td>
                <td>{{ $category->children_count }}</td>
                <td>
                    {{ $category->branch_items_count ?? '—' }}
                    @if($category->branch_items_count !== null && $category->items_count > 0 && $category->items_count !== $category->branch_items_count)
                        <div class="help">Прямо здесь: {{ $category->items_count }}</div>
                    @endif
                </td>
                <td>
                    @if(str_starts_with($category->source_url, 'http'))
                        <a href="{{ $category->source_url }}" target="_blank" rel="noopener">Открыть</a>
                    @else
                        —
                    @endif
                </td>
                <td class="actions">
                    <a class="btn btn-secondary" href="{{ $categoryUrl($category) }}">Открыть</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $isModelLevel ? 7 : 6 }}" class="empty">
                    @if($selectedCategory)
                        Подкатегорий нет.
                    @else
                        Модели Tesla не найдены.
                    @endif
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

    @if($categories instanceof \Illuminate\Contracts\Pagination\Paginator)
        <div style="margin-top:16px;">
            {{ $categories->links() }}
        </div>
    @endif
@endif
