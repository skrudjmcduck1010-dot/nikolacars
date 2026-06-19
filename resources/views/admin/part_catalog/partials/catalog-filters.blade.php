<div class="breadcrumbs" style="margin-bottom:18px;">
    <a href="{{ $categoryUrl(null) }}">
        @if($catalog['source'] === 'nikolacars')
            Все запчасти
        @else
            Модели Tesla
        @endif
    </a>
    @foreach($categoryTrail as $trailCategory)
        <span class="separator">/</span>
        @if($loop->last)
            <span class="current">{{ $trailCategory->code ? $trailCategory->code.' · ' : '' }}{{ $categoryName($trailCategory) }}</span>
        @else
            <a href="{{ $categoryUrl($trailCategory) }}">
                {{ $trailCategory->code ? $trailCategory->code.' · ' : '' }}{{ $categoryName($trailCategory) }}
            </a>
        @endif
    @endforeach
</div>

<form method="GET" action="{{ $categoryUrl($selectedCategory, false) }}" @class(['form-grid', 'catalog-filter-form', 'catalog-filter-form--nikolacars' => $catalog['source'] === 'nikolacars']) style="margin-bottom:18px;">
    <div @class(['catalog-search-field--part-number' => $catalog['source'] === 'nikolacars', 'catalog-filter-form__search' => $catalog['source'] === 'nikolacars'])>
        <label>
            @if($catalog['source'] === 'nikolacars')
                Поиск
            @else
                Поиск в текущем уровне
            @endif
        </label>
        <div class="catalog-search-wrap" data-catalog-search-url="{{ $searchUrl }}">
            <input name="q" value="{{ $query }}" placeholder="{{ $catalog['source'] === 'nikolacars' ? html_entity_decode('Код, артикул, название или донор') : ($isModelLevel ? 'Модель, Парт № или название' : 'Название, код, модель или Парт №') }}" autocomplete="off" data-catalog-search-input>
            <div class="catalog-search-suggestions" data-catalog-search-suggestions hidden></div>
        </div>
        @if($catalog['source'] === 'nikolacars')
            <label class="catalog-checkbox catalog-checkbox--small catalog-search-sold-filter">
                <input type="hidden" name="hide_sold" value="0">
                <input type="checkbox" name="hide_sold" value="1" @checked($hideNikolaCarsSold ?? true)>
                <span>Не отображать проданные</span>
            </label>
        @endif
    </div>
    @if($catalog['source'] === 'nikolacars')
    <div class="catalog-filter-form__vin">
        <label>Донор</label>
        @php
            $selectedNikolaCarsVins = collect($nikolaCarsVins ?? ($nikolaCarsVin !== '' ? [$nikolaCarsVin] : []))
                ->map(fn ($vin) => trim((string) $vin))
                ->filter()
                ->unique()
                ->values();
            $selectedNikolaCarsDonor = $selectedNikolaCarsVins->count() === 1
                ? $nikolaCarsDonorFilterCarsByVin->get(\Illuminate\Support\Str::upper(trim((string) $nikolaCarsVin)))
                : null;
            $donorFilterPhotoUrl = function (?\App\Models\DonorCar $donorCar): ?string {
                $photo = $donorCar?->photos[0] ?? null;

                return $photo ? \App\Support\PublicStorageUrl::url($photo) : null;
            };
            $donorFilterMeta = fn (?\App\Models\DonorCar $donorCar): string => $donorCar
                ? trim(collect([$donorCar->display_model, $donorCar->year])->filter()->implode(' / '))
                : '';
        @endphp
        <details class="catalog-donor-dropdown" data-close-on-outside>
            <summary class="catalog-donor-dropdown__toggle">
                @if($selectedNikolaCarsVins->count() > 1)
                    <span class="catalog-donor-dropdown__placeholder">Донор</span>
                    <span>
                        <strong>Выбрано: {{ $selectedNikolaCarsVins->count() }}</strong>
                        <em>Донор</em>
                    </span>
                @elseif($selectedNikolaCarsDonor)
                    @if($donorFilterPhotoUrl($selectedNikolaCarsDonor))
                        <img src="{{ $donorFilterPhotoUrl($selectedNikolaCarsDonor) }}" alt="Превью {{ $selectedNikolaCarsDonor->display_vin }}" loading="lazy" decoding="async">
                    @else
                        <span class="catalog-donor-dropdown__placeholder">Фото</span>
                    @endif
                    <span>
                        <strong>{{ $selectedNikolaCarsDonor->display_vin }}</strong>
                        <em>{{ $donorFilterMeta($selectedNikolaCarsDonor) }}</em>
                    </span>
                @elseif($nikolaCarsVin !== '')
                    <span class="catalog-donor-dropdown__placeholder">Донор</span>
                    <span>
                        <strong>{{ $nikolaCarsVin }}</strong>
                        <em>Донор не найден</em>
                    </span>
                @else
                    <span>
                        <strong>Все</strong>
                        <em>Все доноры</em>
                    </span>
                @endif
            </summary>
            <div class="catalog-donor-dropdown__menu">
                @foreach($nikolaCarsVinOptions as $vinOption)
                    @php
                        $donorCar = $nikolaCarsDonorFilterCarsByVin->get(\Illuminate\Support\Str::upper(trim((string) $vinOption)));
                        $photoUrl = $donorFilterPhotoUrl($donorCar);
                    @endphp
                    <label class="catalog-donor-option">
                        <input type="checkbox" name="vins[]" value="{{ $vinOption }}" @checked($selectedNikolaCarsVins->contains($vinOption))>
                        @if($photoUrl)
                            <img src="{{ $photoUrl }}" alt="Превью {{ $donorCar->display_vin }}" loading="lazy" decoding="async">
                        @else
                            <span class="catalog-donor-dropdown__placeholder">Фото</span>
                        @endif
                        <span>
                            <strong>{{ $donorCar?->display_vin ?? $vinOption }}</strong>
                            <em>{{ $donorFilterMeta($donorCar) ?: html_entity_decode('Донор не найден') }}</em>
                        </span>
                    </label>
                @endforeach
            </div>
        </details>
    </div>
    <div class="catalog-filter-form__category">
        <label>Категория</label>
        <details class="catalog-checkbox-dropdown catalog-checkbox-dropdown--top-category" data-close-on-outside>
            <summary class="btn btn-secondary catalog-checkbox-dropdown__toggle">
                @if(count($nikolaCarsTopCategories) > 0)
                    Выбрано: {{ count($nikolaCarsTopCategories) }}
                @else
                    Все
                @endif
            </summary>
            <div class="catalog-checkbox-dropdown__menu">
                @foreach($nikolaCarsTopCategoryOptions as $topCategoryOption)
                    <label class="catalog-checkbox">
                        <input type="checkbox" name="top_categories[]" value="{{ $topCategoryOption }}" @checked(in_array($topCategoryOption, $nikolaCarsTopCategories, true))>
                        <span>{{ $topCategoryOption }}</span>
                    </label>
                @endforeach
            </div>
        </details>
    </div>
    @endif
    @if($catalog['source'] !== 'nikolacars')
    <div>
        <label>Модели</label>
        <input type="hidden" name="model_filter" value="1">
        <div class="catalog-model-dropdown" data-model-dropdown>
            <button type="button" class="btn btn-secondary catalog-model-dropdown__toggle" data-model-dropdown-toggle>
                <span data-model-dropdown-label>Модели выбраны</span>
            </button>
            <div class="catalog-model-dropdown__menu" data-model-dropdown-menu hidden>
                @foreach($models as $modelName)
                    @if($modelName === 'Cybertruck')
                        <label class="catalog-checkbox">
                            <input type="checkbox" name="include_cybertruck" value="1" @checked($includeCybertruck) data-model-checkbox>
                            <span>Cybertruck</span>
                        </label>
                    @else
                        <label class="catalog-checkbox">
                            <input type="checkbox" name="models[]" value="{{ $modelName }}" @checked(in_array($modelName, $selectedModels, true)) data-model-checkbox>
                            <span>{{ $modelLabel($modelName) }}</span>
                        </label>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
    @endif
    @if($catalog['source'] === 'all')
        @if($showCatalogItems)
            <input type="hidden" name="show_catalog_items" value="1">
        @endif
        <div>
            <label>Фильтр товаров</label>
            <label class="catalog-checkbox">
                <input type="checkbox" name="missing_names[]" value="ua" @checked(in_array('ua', $missingNames, true))>
                <span>Без укр названия</span>
            </label>
            <label class="catalog-checkbox">
                <input type="checkbox" name="missing_names[]" value="ru" @checked(in_array('ru', $missingNames, true))>
                <span>Без ру названия</span>
            </label>
            <label class="catalog-checkbox">
                <input type="checkbox" name="product_filters[]" value="errors" @checked(in_array('errors', $productFilters, true))>
                <span>С ошибками</span>
            </label>
            <label class="catalog-checkbox">
                <input type="checkbox" name="name_source" value="aleto" @checked($nameSource === 'aleto')>
                <span>Названия с Aleto</span>
            </label>
            <label class="catalog-checkbox">
                <input type="checkbox" name="name_source" value="teslashop" @checked($nameSource === 'teslashop')>
                <span>Названия с TeslaShop.by</span>
            </label>
        </div>
    @endif
    <div @class(['actions', 'full' => $catalog['source'] !== 'nikolacars', 'catalog-filter-actions--nikolacars' => $catalog['source'] === 'nikolacars'])>
        <button type="submit">
            @if($catalog['source'] === 'nikolacars')
                Найти
            @else
                Найти
            @endif
        </button>
        <a class="btn btn-secondary" href="{{ $categoryUrl($selectedCategory, false) }}">
            @if($catalog['source'] === 'nikolacars')
                Сбросить
            @else
                Сбросить
            @endif
        </a>
        @if($selectedCategory)
            <a class="btn btn-secondary" href="{{ $categoryUrl($selectedCategory->parent) }}">Назад</a>
        @endif
    </div>
</form>
