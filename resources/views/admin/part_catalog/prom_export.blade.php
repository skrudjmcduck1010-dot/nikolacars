@extends('layouts.admin', [
    'heading' => 'Выгрузка Prom',
    'subheading' => 'Запчасти с фото, остатком и ценой продажи',
])

@section('topbar-actions')
    <a class="btn btn-small btn-secondary" href="{{ route('admin.zapchasti.index') }}">Запчасти НиколаКарз</a>
@endsection

@section('content')
    @php
        $partCatalogPresenter = app(\App\View\Admin\PartCatalog\PartCatalogIndexPresenter::class);
    @endphp

    <div class="grid grid-2 prom-export-stats" style="margin-bottom:18px;">
        <div class="panel">
            <div class="help">Товаров к выгрузке</div>
            <div class="stat">{{ $itemsCount }}</div>
        </div>
        <div class="panel">
            <div class="help">Остаток</div>
            <div class="stat">{{ rtrim(rtrim(number_format($totalQuantity, 3, '.', ''), '0'), '.') }} шт</div>
        </div>
        <div class="panel">
            <div class="help">Стоимость остатков</div>
            <div class="stat">{{ number_format($totalValueUsd, 2, '.', ' ') }} USD</div>
        </div>
        <div class="panel">
            <div class="help">Курс для пересчета</div>
            <div class="stat">{{ $usdRate['label'] }}</div>
        </div>
    </div>

    <div class="panel">
        <div class="prom-export-head">
            <h2 class="section-title">Товары для Prom</h2>
            <span class="tag">фото + в наличии + цена</span>
        </div>

        <table class="prom-export-table" style="margin-top:12px;">
            <thead>
            <tr>
                <th>Фото</th>
                <th>Код</th>
                <th>Артикул</th>
                <th>Название</th>
                <th>Цена продажи, грн</th>
                <th>Остаток</th>
                <th>Стоимость</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($groups as $group)
                @php
                    $item = $group['item'];
                    $imageUrls = $group['image_urls'];
                    $imageUrl = $imageUrls->first();
                    $nameRu = trim((string) $item->name_ru);
                    $descriptionUa = $nikolaCarsDescription($item, $partCatalogPresenter->promDescriptionSource($item, $group['description_uk'] ?? null));
                    $descriptionRu = $nikolaCarsDescription($item, $group['description_ru'] ?? $item->notes_ru);
                @endphp
                <tr>
                    <td>
                        @if($imageUrl)
                            <button
                                type="button"
                                class="catalog-photo-preview"
                                data-catalog-photo-trigger
                                data-catalog-images='@json($imageUrls->all())'
                                data-catalog-photo-title="{{ $itemName($item) }}"
                                aria-label="Open photo {{ $itemName($item) }}"
                            >
                                <img class="table-preview" src="{{ $imageUrl }}" alt="{{ $itemName($item) }}">
                                @if($imageUrls->count() > 1)
                                    <span class="catalog-photo-preview__count">{{ $imageUrls->count() }}</span>
                                @endif
                            </button>
                        @else
                            <span class="preview-placeholder">нет фото</span>
                        @endif
                    </td>
                    <td>
                        {{ $group['codes']->take(3)->implode(', ') ?: '-' }}
                        @if($group['codes']->count() > 3)
                            <div class="help">+{{ $group['codes']->count() - 3 }}</div>
                        @endif
                    </td>
                    <td>
                        <strong>{{ $group['part_number'] ?: '-' }}</strong>
                        @if($group['count'] > 1)
                            <div class="help">{{ $group['count'] }} позиции объединено</div>
                        @endif
                    </td>
                    <td class="prom-export-title-cell">
                        <a href="{{ $itemUrl($item) }}"><strong>{{ $itemName($item) }}</strong></a>
                        @if($nameRu !== '')
                            <div class="prom-export-name-ru">{{ $nameRu }}</div>
                        @endif
                        @if($descriptionRu !== '')
                            <div class="prom-export-description prom-export-description--ru">{{ $descriptionRu }}</div>
                        @endif
                        @if($descriptionUa !== '')
                            <div class="prom-export-description prom-export-description--ua">{{ $descriptionUa }}</div>
                        @endif
                    </td>
                    <td>{{ $group['price_uah_text'] }}</td>
                    <td>{{ $group['quantity_text'] }}</td>
                    <td>{{ $group['total_value_text'] }}</td>
                    <td class="actions">
                        <a class="btn btn-small btn-secondary" href="{{ $itemUrl($item) }}">Открыть</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="empty">Нет товаров, которые одновременно имеют фото, остаток и цену продажи.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <dialog class="catalog-photo-lightbox" data-catalog-photo-lightbox>
            <div class="catalog-photo-lightbox__toolbar">
                <span data-catalog-photo-counter></span>
                <button type="button" class="btn btn-secondary catalog-photo-lightbox__close" data-close-catalog-photo-lightbox aria-label="Close">&times;</button>
            </div>
            <div class="catalog-photo-lightbox__stage">
                <button type="button" class="btn btn-secondary catalog-photo-lightbox__nav catalog-photo-lightbox__nav--prev" data-catalog-photo-prev aria-label="Previous photo">‹</button>
                <img src="" alt="" data-catalog-photo-lightbox-image>
                <button type="button" class="btn btn-secondary catalog-photo-lightbox__nav catalog-photo-lightbox__nav--next" data-catalog-photo-next aria-label="Next photo">›</button>
            </div>
        </dialog>

        <div class="prom-export-actions">
            <div>
                <h3>Фид для Prom</h3>
                <div class="help">Эту ссылку можно указать в кабинете Prom как импорт из YML/XML по ссылке.</div>
                <input value="{{ $feedUrl }}" readonly onclick="this.select()">
            </div>
            <a class="btn" href="{{ $feedUrl }}">Выгрузить YML для Prom</a>
        </div>
    </div>

    <style>
        .prom-export-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .prom-export-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .prom-export-table td { vertical-align: top; }
        .prom-export-title-cell { min-width: 320px; }
        .prom-export-name-ru {
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.35;
        }
        .prom-export-description {
            margin-top: 8px;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--surface-muted, #f5f7f8);
            color: var(--text);
            font-size: 12px;
            line-height: 1.35;
            max-width: 520px;
            white-space: pre-line;
        }
        .prom-export-description--ua {
            color: var(--muted);
            font-size: 11px;
        }
        .prom-export-description--ru + .prom-export-description--ua {
            margin-top: 6px;
        }
        .catalog-photo-preview {
            position: relative;
            display: inline-flex;
            padding: 0;
            border: 0;
            background: transparent;
            cursor: zoom-in;
        }
        .catalog-photo-preview__count {
            position: absolute;
            right: -6px;
            top: -6px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: var(--accent);
            color: white;
            font-size: 12px;
            font-weight: 700;
            line-height: 18px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(25, 32, 36, .18);
        }
        .catalog-photo-lightbox {
            width: min(1180px, calc(100vw - 32px));
            max-height: calc(100vh - 32px);
            border: 0;
            border-radius: 18px;
            padding: 0;
            background: #0f171b;
            color: white;
            box-shadow: 0 24px 80px rgba(0, 0, 0, .42);
        }
        .catalog-photo-lightbox::backdrop { background: rgba(10, 17, 20, .72); }
        .catalog-photo-lightbox__toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
        }
        .catalog-photo-lightbox__toolbar span {
            color: rgba(255, 255, 255, .78);
            font-size: 13px;
        }
        .catalog-photo-lightbox__close,
        .catalog-photo-lightbox__nav {
            width: 42px;
            height: 42px;
            padding: 0;
            border-color: rgba(255, 255, 255, .18);
            background: rgba(255, 255, 255, .08);
            color: white;
        }
        .catalog-photo-lightbox__close { font-size: 26px; line-height: 1; }
        .catalog-photo-lightbox__stage {
            position: relative;
            display: grid;
            place-items: center;
            min-height: min(720px, calc(100vh - 114px));
            padding: 0 64px 20px;
        }
        .catalog-photo-lightbox__stage img {
            display: block;
            max-width: 100%;
            max-height: calc(100vh - 150px);
            object-fit: contain;
            border-radius: 10px;
            background: #0b1013;
        }
        .catalog-photo-lightbox__nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 34px;
            line-height: 1;
        }
        .catalog-photo-lightbox__nav--prev { left: 14px; }
        .catalog-photo-lightbox__nav--next { right: 14px; }
        .catalog-photo-lightbox__nav[hidden] { display: none; }
        .prom-export-actions {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: 16px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
        }
        .prom-export-actions h3 {
            margin: 0 0 6px;
            font-size: 18px;
        }
        .prom-export-actions input {
            margin-top: 10px;
            font-family: Consolas, monospace;
            font-size: 13px;
        }
        @media (max-width: 980px) {
            .prom-export-stats { grid-template-columns: 1fr; }
            .prom-export-actions { grid-template-columns: 1fr; }
            .catalog-photo-lightbox__stage {
                min-height: min(520px, calc(100vh - 104px));
                padding: 0 14px 76px;
            }
            .catalog-photo-lightbox__stage img { max-height: calc(100vh - 178px); }
            .catalog-photo-lightbox__nav {
                top: auto;
                bottom: 18px;
                transform: none;
            }
            .catalog-photo-lightbox__nav--prev { left: calc(50% - 54px); }
            .catalog-photo-lightbox__nav--next { right: calc(50% - 54px); }
        }
    </style>

    <script>
        (() => {
            const lightbox = document.querySelector('[data-catalog-photo-lightbox]');
            if (!lightbox) return;

            const image = lightbox.querySelector('[data-catalog-photo-lightbox-image]');
            const counter = lightbox.querySelector('[data-catalog-photo-counter]');
            const closeButton = lightbox.querySelector('[data-close-catalog-photo-lightbox]');
            const prevButton = lightbox.querySelector('[data-catalog-photo-prev]');
            const nextButton = lightbox.querySelector('[data-catalog-photo-next]');
            let photoUrls = [];
            let currentIndex = 0;
            let currentTitle = '';

            const showPhoto = (index) => {
                if (!image || photoUrls.length === 0) return;

                currentIndex = (index + photoUrls.length) % photoUrls.length;
                image.src = photoUrls[currentIndex];
                image.alt = currentTitle;

                if (counter) {
                    counter.textContent = `${currentTitle ? `${currentTitle} - ` : ''}${currentIndex + 1} / ${photoUrls.length}`;
                }

                const hasMultiplePhotos = photoUrls.length > 1;
                if (prevButton) prevButton.hidden = !hasMultiplePhotos;
                if (nextButton) nextButton.hidden = !hasMultiplePhotos;
            };

            const openPhoto = (trigger) => {
                try {
                    photoUrls = JSON.parse(trigger.dataset.catalogImages || '[]').filter(Boolean);
                } catch (error) {
                    photoUrls = [];
                }

                if (photoUrls.length === 0) return;

                currentTitle = trigger.dataset.catalogPhotoTitle || '';
                showPhoto(0);
                lightbox.showModal();
            };

            document.querySelectorAll('[data-catalog-photo-trigger]').forEach((trigger) => {
                trigger.addEventListener('click', () => openPhoto(trigger));
            });

            closeButton?.addEventListener('click', () => lightbox.close());
            prevButton?.addEventListener('click', () => showPhoto(currentIndex - 1));
            nextButton?.addEventListener('click', () => showPhoto(currentIndex + 1));
            lightbox.addEventListener('click', (event) => {
                if (event.target === lightbox) lightbox.close();
            });
            lightbox.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft') showPhoto(currentIndex - 1);
                if (event.key === 'ArrowRight') showPhoto(currentIndex + 1);
            });
        })();
    </script>
@endsection
