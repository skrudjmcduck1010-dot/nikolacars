@extends('layouts.admin', ['heading' => $heading])

@section('content')
    @php
        $partCatalogShowPresenter = app(\App\View\Admin\PartCatalog\PartCatalogShowPresenter::class);
        $localizedNameBadges = $partCatalogShowPresenter->localizedNameBadges($item);
        $nameRuBadge = $localizedNameBadges['ru'];
        $nameUaBadge = $localizedNameBadges['ua'];
        $undeterminedNameBadge = $localizedNameBadges['undetermined'];
        $isNikolaCarsSoldItem = $partCatalogShowPresenter->isNikolaCarsSoldItem($item);
        $catalogCategoryPath = $partCatalogShowPresenter->catalogCategoryPath($item);
        $isInvalidNikolaCarsPartNumber = $partCatalogShowPresenter->isInvalidNikolaCarsPartNumber($item);
        $schemeNumberBadge = $partCatalogShowPresenter->schemeNumberBadge($item);
        $localizedNameConflicts = $partCatalogShowPresenter->localizedNameConflicts($item);
        $localizedNameConflictRu = $localizedNameConflicts['ru'] ?? '';
        $localizedNameConflictUa = $localizedNameConflicts['ua'] ?? '';
        $catalogNameSource = $partCatalogShowPresenter->catalogNameSource($item, $catalog, $sourceUrl, $nameRuBadge, $nameUaBadge, $undeterminedNameBadge, $localizedNameSources);
        $nameRuManual = $catalogNameSource['manual']['ru'];
        $nameUaManual = $catalogNameSource['manual']['ua'];
        $catalogNameSourceUrl = $catalogNameSource['url'];
        $catalogNameSourceSite = $catalogNameSource['site'];
        $catalogNameSourceLabel = $catalogNameSource['label'];
        $shouldShowCatalogNameSourceRu = (bool) ($catalogNameSource['show_for']['ru'] ?? false);
        $shouldShowCatalogNameSourceUa = (bool) ($catalogNameSource['show_for']['ua'] ?? false);
        $catalogNameUpdateRoute = ! $isNikolaCarsSoldItem && \Illuminate\Support\Facades\Route::has(($catalog['route_prefix'] ?? '').'.update')
            ? route($catalog['route_prefix'].'.update', $item)
            : null;
        $sourceUrls = $partCatalogShowPresenter->sourceUrls($item, $catalog, $sourceUrl);
        $tskHasNoProductPage = $partCatalogShowPresenter->tskHasNoProductPage($item, $sourceUrl);
        $imageUrls = $partCatalogShowPresenter->imageUrls($item, $catalog);
        $schemeImageUrls = $partCatalogShowPresenter->schemeImageUrls($item, $catalog, $teslaOfficialOccurrenceCategories ?? collect());
        $lightboxImageUrls = $partCatalogShowPresenter->lightboxImageUrls($imageUrls, $schemeImageUrls);
        $characteristics = $partCatalogShowPresenter->characteristics($item);
        $colorValue = $partCatalogShowPresenter->colorValue($item);
        $partTypeValue = $partCatalogShowPresenter->partTypeValue($item, $itemPartType);
        $info = $partCatalogShowPresenter->info($item);
        $extraRows = $partCatalogShowPresenter->extraRows($item);
        $availabilitySourceLabel = $partCatalogShowPresenter->availabilitySourceLabel($catalog);
        $hasTeslaExactPresence = $partCatalogShowPresenter->hasTeslaExactPresence($item);
        $findPartFoundByRequestedPartNumbers = $partCatalogShowPresenter->findPartFoundByRequestedPartNumbers($item);
        $officialPartMatchStatus = $partCatalogShowPresenter->officialPartMatchStatus($item);
        $teslaPartSearchSimilarPartNumbersText = $partCatalogShowPresenter->teslaPartSearchSimilarPartNumbersText($item);
        $teslaPartSearchRequestedPartNumber = $partCatalogShowPresenter->teslaPartSearchRequestedPartNumber($item);
        $teslaOfficialPresence = $partCatalogShowPresenter->teslaOfficialPresence($item);
        $teslaPartSearchCheckedAt = $partCatalogShowPresenter->teslaPartSearchCheckedAt($item);
        $teslaResults = $partCatalogShowPresenter->teslaResults($item, $teslaRelatedFindPartResults ?? collect());
        $teslaResultsFromSavedCatalog = $partCatalogShowPresenter->teslaResultsFromSavedCatalog($item, $teslaRelatedFindPartResults ?? collect());
        $teslaFoundRequestLinks = $partCatalogShowPresenter->teslaFoundRequestLinks($findPartFoundByRequestedPartNumbers, $teslaFindPartRequestItemIds);
        $teslaResultRows = $partCatalogShowPresenter->teslaResultRows($teslaResults, $teslaPartSearchItemIds);
        $teslaFindPartUrl = $partCatalogShowPresenter->teslaFindPartUrl($item);
        $compatibilitySummary = $partCatalogShowPresenter->compatibilitySummary($item, $catalog, $modelLabel, $nikolaCarsDonorCarsByVin);
        $priceSummary = $partCatalogShowPresenter->priceSummary($item, $usdRate, $priceSource);
        $nikolaCarsRelatedRows = $partCatalogShowPresenter->nikolaCarsRelatedRows($nikolaCarsRelatedItems, $item, $usdRate, $priceSource, $itemName, $nikolaCarsDonorCarsByVin);
        $drivePartsTeslaActualPartNumber = $partCatalogShowPresenter->drivePartsTeslaActualPartNumber($item);
    @endphp

    <div class="grid part-catalog-show-grid" style="display:flex;flex-direction:column;gap:18px;">
        @if($teslaFoundRequestLinks->isNotEmpty())
            <div class="panel">
                <span class="tag">Tesla found</span>
                <span class="help">
                    Найден через Tesla.com по запросу артикула:
                    @foreach($teslaFoundRequestLinks as $requestLink)
                        @if($requestLink['item_id'])
                            <a href="{{ route('admin.tesla-official-catalog.show', $requestLink['item_id']) }}">{{ $requestLink['part_number'] }}</a>@if(! $loop->last), @endif
                        @else
                            {{ $requestLink['part_number'] }}@if(! $loop->last), @endif
                        @endif
                    @endforeach
                </span>
            </div>
        @endif
        @if($hasTeslaExactPresence && $teslaResults->isEmpty())
            <div class="panel">
                <span class="tag part-catalog-tesla-exact-tag" title="Tesla.com подтверждает этот артикул." aria-label="Tesla.com подтверждает этот артикул.">Tesla exact</span>
            </div>
        @endif
        @if($officialPartMatchStatus === 'similar')
            <div class="panel">
                <span class="tag">Tesla similar</span>
                <span class="help">
                    Tesla.com дает похожий номер -
                    {{$teslaPartSearchSimilarPartNumbersText !== '' ? $teslaPartSearchSimilarPartNumbersText : html_entity_decode('&mdash;', ENT_QUOTES, 'UTF-8')}}

                    @if($teslaPartSearchRequestedPartNumber !== '')
                        для {{$teslaPartSearchRequestedPartNumber}}

                    @endif
                </span>
            </div>
        @endif
        @if($teslaResults->isNotEmpty())
            <div class="panel part-catalog-tesla-results-panel" style="order:3;">
                <div class="part-catalog-tesla-results-heading">
                    <strong>Результаты Tesla.com</strong>
                    @if($hasTeslaExactPresence)
                        <span class="tag part-catalog-tesla-exact-tag" title="Tesla.com подтверждает этот артикул." aria-label="Tesla.com подтверждает этот артикул.">Tesla exact</span>
                    @endif
                    @if($teslaResultsFromSavedCatalog)
                        <span class="tag">из сохраненного каталога</span>
                    @endif
                </div>
                <table style="margin-top:10px;">
                    <thead>
                    <tr>
                        <th>Артикул</th>
                        <th>Description</th>
                        <th>Model</th>
                        <th>Category</th>
                        <th>Subcategory</th>
                        <th>Group</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($teslaResultRows as $teslaResultRow)
                        <tr>
                            <td>
                                @if($teslaResultRow['item_id'])
                                    <a href="{{route('admin.tesla-official-catalog.show', $teslaResultRow['item_id'])}}">{{$teslaResultRow['part_number']}}</a>
                                @else
                                    {{$teslaResultRow['part_number'] !== '' ? $teslaResultRow['part_number'] : '—'}}

                                @endif
                            </td>
                            <td>
                                {{$teslaResultRow['description'] ?: '—'}}

                                @if($teslaResultRow['visibility'] === 'saved_official_catalog')
                                    <span class="tag">каталог</span>
                                @elseif($teslaResultRow['visibility'] === 'hidden')
                                    <span class="tag">скрытый</span>
                                @elseif($teslaResultRow['visibility'] === 'visible')
                                    <span class="tag">видимый</span>
                                @endif
                                @if($teslaResultRow['localized_description'])
                                    <div class="help">{{$teslaResultRow['localized_description']}}</div>
                                @endif
                            </td>
                            <td>{{$teslaResultRow['model'] ?: '—'}}</td>
                            <td>{{$teslaResultRow['category'] ?: '—'}}</td>
                            <td>{{$teslaResultRow['subcategory'] ?: '—'}}</td>
                            <td>{{$teslaResultRow['group'] ?: '—'}}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if($officialPartMatchStatus === 'not_found')
            <div class="panel">
                <span class="tag">Tesla not found</span>
                <span class="help">Tesla.com не дал точный или похожий номер.</span>
            </div>
        @endif
        @if(($catalogItemDonorCars ?? collect())->isNotEmpty())
            <div class="panel part-catalog-donor-panel" style="order:2;">
                <strong>Найдена на доноре</strong>
                <div class="help" style="margin-top:6px;">Эта позиция привязана к донорскому авто.</div>
                <div class="part-catalog-donor-links">
                    @foreach($catalogItemDonorCars as $donorCar)
                        <a class="tag" href="{{route('admin.donor-cars.show', $donorCar)}}">
                            Донор #{{$donorCar->id}} · {{$donorCar->vin}}

                            @if($donorCar->model || $donorCar->year)
                                · {{trim(($donorCar->model ?: '').' '.($donorCar->year ?: ''))}}

                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="panel part-catalog-item-main-panel" style="order:1;">
            @if($catalog['source'] !== 'tesla_official' && $sourceUrls->count() > 1)
                <div class="part-catalog-source-button">
                    @foreach($sourceUrls as $index => $sourceItemUrl)
                        <a class="btn btn-secondary" href="{{$sourceItemUrl}}" target="_blank" rel="noopener">{{$catalog['source_label']}} {{$index + 1}}</a>
                    @endforeach
                </div>
            @elseif($catalog['source'] !== 'tesla_official' && $sourceUrl)
                <div class="part-catalog-source-button">
                    <a class="btn btn-secondary" href="{{$sourceUrl}}" target="_blank" rel="noopener">{{$catalog['source_label']}}</a>
                    @if($tskHasNoProductPage)
                        <div class="help" style="margin-top:6px;">Страницы товара нету</div>
                    @endif
                </div>
            @endif

            <div class="part-catalog-item-layout">
                <div class="part-catalog-item-details">
                    <div class="help">{{$item->source === 'driveparts' ? 'Артикул DriveParts' : 'Парт '}}</div>
                    <div @class(['part-catalog-part-heading', 'part-catalog-invalid-part' => $isInvalidNikolaCarsPartNumber])>
                        <div class="stat" style="font-size:24px;">{{$item->part_number ?: '—'}}</div>
                        @if($teslaOfficialPresence === 'part_search_auth_required')
                            <span class="tag" title="Профиль браузера не залогинен в Tesla.com: {{$teslaPartSearchCheckedAt}}">Tesla auth required</span>
                        @elseif($teslaOfficialPresence === 'part_search_security_blocked')
                            <span class="tag" title="Tesla заблокировала security tools/VPN/network: {{$teslaPartSearchCheckedAt}}">Tesla security blocked</span>
                        @elseif($teslaOfficialPresence === 'part_search_api_error')
                            <span class="tag" title="Tesla.com find-part вернул API error: {{$teslaPartSearchCheckedAt}}">Tesla API error</span>
                        @elseif($teslaPartSearchCheckedAt)
                            @if($teslaFindPartUrl)
                                <a class="tag" href="{{$teslaFindPartUrl}}" target="_blank" rel="noopener" title="Проверено через Tesla.com find-part: {{$teslaPartSearchCheckedAt}}">Tesla checked</a>
                            @else
                                <span class="tag" title="Проверено через Tesla.com find-part: {{$teslaPartSearchCheckedAt}}">Tesla checked</span>
                            @endif
                        @endif
                    </div>
                    @if($drivePartsTeslaActualPartNumber !== '')
                        <div class="part-catalog-number-pair" style="margin-top:10px;">
                            <div class="help">Актуальный парт-номер Tesla</div>
                            <strong>{{$drivePartsTeslaActualPartNumber}}</strong>
                        </div>
                    @endif
                    @if(filled($partNumberDisplayName) || $schemeNumberBadge !== '')
                        <div class="part-catalog-display-name-row">
                            @if(filled($partNumberDisplayName))
                                <span class="help">{{$partNumberDisplayName}}</span>
                            @endif
                            @if($schemeNumberBadge !== '')
                                <span class="tag" title="Позиция на схеме">На схеме {{$schemeNumberBadge}}</span>
                            @endif
                        </div>
                    @endif
                    @if(($priceHistory ?? collect())->isNotEmpty())
                        <div class="part-catalog-price-history">
                            <div class="help">Изменение цены</div>
                            @foreach($priceHistory as $history)
                                <div class="part-catalog-price-history__row">
                                    <span>{{$history->changed_at?->format('d.m.Y H:i') ?: '—'}}</span>
                                    <strong>
                                        {{number_format((float) $history->old_price, 2, '.', ' ')}}

                                        &rarr;
                                        {{number_format((float) $history->new_price, 2, '.', ' ')}}

                                        {{$history->currency ?: $item->currency ?: 'USD'}}

                                    </strong>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <p class="part-catalog-name-row"><strong>Название RU:</strong> <span data-name-value="ru">{{$nameRuBadge['text'] !== '' ? $nameRuBadge['text'] : '—'}}</span>
                        @if($nameRuManual)<span class="tag" data-manual-name-tag="ru">Вручную</span>@endif
                        @if($localizedNameSources['ru']['is_language_marker'] ?? false)<span class="tag">Маркеры языка</span>@endif
                        @if($localizedNameConflictRu !== '')<span class="tag tag-conflict">{{ $localizedNameConflictRu }}</span>@endif
                        @if($localizedNameSources['ru']['site'])
                            @if($localizedNameSources['ru']['url'])
                                <a class="tag" href="{{$localizedNameSources['ru']['url']}}" target="_blank" rel="noopener">{{$localizedNameSources['ru']['site']}}</a>
                            @else
                                <span class="tag">{{$localizedNameSources['ru']['site']}}</span>
                            @endif
                        @endif
                        @if($nameRuBadge['text'] !== '' && $catalogNameSourceUrl && $shouldShowCatalogNameSourceRu)
                            <a class="tag" href="{{$catalogNameSourceUrl}}" target="_blank" rel="noopener" data-catalog-name-source-tag>{{$catalogNameSourceLabel}}</a>
                        @endif
                        @if($catalogNameUpdateRoute)
                            <button type="button" class="part-catalog-name-edit-button" data-show-name-edit-form data-focus-locale="ru" aria-label="Редактировать название RU" title="Редактировать название RU">&#9998;</button>
                        @endif
                    </p>
                    <p class="part-catalog-name-row"><strong>Назва UA:</strong> <span data-name-value="ua">{{$nameUaBadge['text'] !== '' ? $nameUaBadge['text'] : '—'}}</span>
                        @if($nameUaManual)<span class="tag" data-manual-name-tag="ua">Вручную</span>@endif
                        @if($localizedNameSources['ua']['is_language_marker'] ?? false)<span class="tag">Маркеры языка</span>@endif
                        @if($localizedNameConflictUa !== '')<span class="tag tag-conflict">{{ $localizedNameConflictUa }}</span>@endif
                        @if($localizedNameSources['ua']['site'])
                            @if($localizedNameSources['ua']['url'])
                                <a class="tag" href="{{$localizedNameSources['ua']['url']}}" target="_blank" rel="noopener">{{$localizedNameSources['ua']['site']}}</a>
                            @else
                                <span class="tag">{{$localizedNameSources['ua']['site']}}</span>
                            @endif
                        @endif
                        @if($nameUaBadge['text'] !== '' && $catalogNameSourceUrl && $shouldShowCatalogNameSourceUa)
                            <a class="tag" href="{{$catalogNameSourceUrl}}" target="_blank" rel="noopener" data-catalog-name-source-tag>{{$catalogNameSourceLabel}}</a>
                        @endif
                        @if($catalogNameUpdateRoute)
                            <button type="button" class="part-catalog-name-edit-button" data-show-name-edit-form data-focus-locale="ua" aria-label="Редактировать назву UA" title="Редактировать назву UA">&#9998;</button>
                        @endif
                    </p>
                    @if($undeterminedNameBadge['text'] !== '')
                        <p class="part-catalog-name-row" data-undetermined-name-row>
                            <strong>Не определено:</strong>
                            <span>{{$undeterminedNameBadge['text']}}</span>
                            <span class="tag tag-conflict">Конфликт</span>
                            @if($catalogNameSourceUrl)
                                <a class="tag" href="{{$catalogNameSourceUrl}}" target="_blank" rel="noopener" data-catalog-name-source-tag>{{$catalogNameSourceLabel}}</a>
                            @elseif($catalogNameSourceSite)
                                <span class="tag" data-catalog-name-source-tag>{{$catalogNameSourceLabel}}</span>
                            @endif
                        </p>
                    @endif
                    @if($catalogNameUpdateRoute)
                        <form method="POST" action="{{$catalogNameUpdateRoute}}" class="part-catalog-name-edit-form" data-name-edit-form data-source-url="{{$catalogNameSourceUrl}}" data-source-label="{{$catalogNameSourceLabel}}" hidden>
                            @csrf
                            @method('PATCH')
                            <input type="hidden" id="catalog-name-ru-{{$item->id}}" name="name_ru" value="{{old('name_ru', $item->name_ru)}}" data-display-value="{{$nameRuBadge['text']}}" data-original-name="{{$item->name_ru}}">

                            <input type="hidden" id="catalog-name-ua-{{$item->id}}" name="name_ua" value="{{old('name_ua', $item->name_ua)}}" data-display-value="{{$nameUaBadge['text']}}" data-original-name="{{$item->name_ua}}">
                        </form>
                        @once
                            <script>
                                const saveInlineCatalogName = async function (form, row, locale) {
                                    const input = row.querySelector('[data-name-inline-input="' + locale + '"]');
                                    const value = row.querySelector('[data-name-value="' + locale + '"]');
                                    const saveButton = row.querySelector('[data-name-inline-save="' + locale + '"]');
                                    const hiddenInput = form.querySelector('[name=name_' + locale + ']');
                                    const token = form.querySelector('[name=_token]')?.value || '';

                                    if (!input || !value || !hiddenInput) {
                                        return;
                                    }

                                    hiddenInput.value = input.value;
                                    saveButton && (saveButton.disabled = true);

                                    const payload = new FormData();
                                    payload.append('_token', token);
                                    payload.append('_method', 'PATCH');
                                    payload.append('name_' + locale, input.value);

                                    const response = await fetch(form.action, {
                                        method: 'POST',
                                        headers: {
                                            'Accept': 'application/json',
                                            'X-Requested-With': 'XMLHttpRequest',
                                        },
                                        body: payload,
                                    });

                                    saveButton && (saveButton.disabled = false);

                                    if (!response.ok) {
                                        alert('Не удалось сохранить название');
                                        return;
                                    }

                                    const data = await response.json();
                                    const savedValue = data?.item?.['name_' + locale] ?? input.value;

                                    hiddenInput.value = savedValue;
                                    hiddenInput.dataset.displayValue = savedValue;
                                    value.textContent = savedValue !== '' ? savedValue : '—';
                                    value.hidden = false;
                                    input.remove();
                                    saveButton?.remove();

                                    if (savedValue !== '') {
                                        row.closest('.part-catalog-item-details')?.querySelector('[data-undetermined-name-row]')?.setAttribute('hidden', 'hidden');
                                    }

                                    if (!row.querySelector('[data-manual-name-tag="' + locale + '"]')) {
                                        const tag = document.createElement('span');
                                        tag.className = 'tag';
                                        tag.dataset.manualNameTag = locale;
                                        tag.textContent = 'Вручную';
                                        value.after(tag);
                                    }

                                    row.querySelectorAll('[data-catalog-name-source-tag]').forEach((tag) => tag.remove());
                                };

                                document.addEventListener('click', function (event) {
                                    const saveButton = event.target.closest('[data-name-inline-save]');

                                    if (saveButton) {
                                        const row = saveButton.closest('.part-catalog-name-row');
                                        const form = row?.closest('.part-catalog-item-details')?.querySelector('[data-name-edit-form]');

                                        if (row && form) {
                                            saveInlineCatalogName(form, row, saveButton.dataset.nameInlineSave);
                                        }

                                        return;
                                    }

                                    const button = event.target.closest('[data-show-name-edit-form]');

                                    if (!button) {
                                        return;
                                    }

                                    const row = button.closest('.part-catalog-name-row');
                                    const form = button.closest('.part-catalog-item-details').querySelector('[data-name-edit-form]');

                                    if (!row || !form) {
                                        return;
                                    }

                                    const locale = button.dataset.focusLocale;
                                    const hiddenInput = form.querySelector('[name=name_' + locale + ']');
                                    const value = row.querySelector('[data-name-value="' + locale + '"]');

                                    if (!hiddenInput || !value) {
                                        return;
                                    }

                                    let input = row.querySelector('[data-name-inline-input="' + locale + '"]');

                                    if (!input) {
                                        input = document.createElement('input');
                                        input.type = 'text';
                                        input.maxLength = 255;
                                        input.className = 'part-catalog-name-inline-input';
                                        input.dataset.nameInlineInput = locale;
                                        input.value = hiddenInput.value || hiddenInput.dataset.displayValue || '';
                                        input.addEventListener('input', function () {
                                            hiddenInput.value = input.value;
                                        });
                                        input.addEventListener('keydown', function (event) {
                                            if (event.key !== 'Enter') {
                                                return;
                                            }

                                            event.preventDefault();
                                            hiddenInput.value = input.value;
                                            saveInlineCatalogName(form, row, locale);
                                        });

                                        const saveButton = document.createElement('button');
                                        saveButton.type = 'button';
                                        saveButton.className = 'btn-small btn-secondary part-catalog-name-save-button';
                                        saveButton.dataset.nameInlineSave = locale;
                                        saveButton.textContent = 'Сохранить';

                                        value.hidden = true;
                                        value.after(input);
                                        input.after(saveButton);
                                    }

                                    input.focus();
                                    input.select();
                                });

                                document.addEventListener('submit', function (event) {
                                    const form = event.target.closest('[data-name-edit-form]');

                                    if (!form) {
                                        return;
                                    }

                                    event.preventDefault();
                                });
                            </script>
                        @endonce
                    @endif
                    <p><strong>Модель:</strong> {{$modelLabel($item) ?: '—'}}</p>
                    <p><strong>Тип запчасти:</strong> {{filled($partTypeValue) ? (is_scalar($partTypeValue) ? $partTypeValue : json_encode($partTypeValue, JSON_UNESCAPED_UNICODE)) : '—'}}</p>

                    <p><strong>{{$compatibilitySummary['label']}}:</strong>
                        @if($compatibilitySummary['donor_car'])
                            <a href="{{route('admin.donor-cars.show', $compatibilitySummary['donor_car'])}}">{{$compatibilitySummary['donor_vin']}}</a>
                        @else
                            {{$compatibilitySummary['text'] ?: '—'}}

                        @endif
                    </p>
                    @if($compatibilitySummary['show_version_restriction'])
                        <p><strong>Привод/версия:</strong> {{$compatibilitySummary['version_restriction']}}</p>
                    @endif
                    <p><strong>Категория:</strong> {{$catalogCategoryPath ?: '—'}}</p>
                    @if($catalog['source'] === 'nikolacars')
                        <p><strong>Цвет:</strong> {{filled($colorValue) ? $colorValue : '—'}}</p>
                    @endif
                    <p><strong>Цена:</strong>
                        @if($priceSummary['has_price'])
                            {{number_format($priceSummary['amount_usd'], 2, '.', ' ')}} USD
                            <span class="help">
                                @if($priceSummary['source_url'])
                                    <a href="{{$priceSummary['source_url']}}" target="_blank" rel="noopener">{{$priceSummary['source_label']}}</a>
                                @else
                                    {{$priceSummary['source_label']}}

                                @endif
                            </span>
                            @if($priceSummary['amount_uah'] !== null)
                                <span class="help">≈ {{number_format($priceSummary['amount_uah'], 2, '.', ' ')}} UAH · {{$priceSummary['rate_label']}}</span>
                            @endif
                        @else
                            —
                        @endif
                    </p>
                    <p><strong>Состояние:</strong> {{$itemCondition($item) ?: '—'}}</p>
                    @if($catalog['source'] === 'nikolacars')
                        <p><strong>Повреждения:</strong> {{$item->quality ? \Illuminate\Support\Str::ucfirst($item->quality) : 'Без повреждений'}}</p>
                    @elseif($catalog['source'] !== 'tcarservice')
                        <p><strong>Качество:</strong> {{$item->quality ?: '—'}}</p>
                    @endif
                    <p><strong>Наличие у {{$availabilitySourceLabel}}:</strong> {{$item->availability ?: '—'}}</p>
                    <p><strong>Описание UA:</strong> {!! filled($item->notes_ua) ? nl2br(e($item->notes_ua)) : '—' !!}</p>
                    <p><strong>Описание RU:</strong> {!! filled($item->notes_ru) ? nl2br(e($item->notes_ru)) : '—' !!}</p>

                    @if($catalog['source'] === 'nikolacars' && ! $isNikolaCarsSoldItem)
                        <form method="POST" action="{{route('admin.zapchasti.update', $item)}}" class="nikolacars-prom-description-form" data-nikolacars-prom-description-form>
                            @csrf
                            @method('PATCH')
                            <label for="nikolacars-prom-description-{{$item->id}}">Описание</label>
                            <textarea id="nikolacars-prom-description-{{$item->id}}" name="notes_ua" rows="6">{{old('notes_ua', $nikolaCarsDescription($item, $item->notes_ua))}}</textarea>
                            <div class="actions">
                                <button type="submit" class="btn-small btn-secondary">Сохранить</button>
                                <span class="help" data-nikolacars-prom-description-status></span>
                            </div>
                        </form>
                    @endif

                    <div class="actions">
                        <a class="btn btn-secondary" href="{{route($catalog['route_prefix'].'.index')}}">Назад к справочнику</a>
                    </div>
                </div>

                <aside class="part-catalog-photo-manager">
                        <div class="help">Фото товара</div>
                        @if($imageUrls->isNotEmpty())
                            <div class="part-catalog-photo-manager__grid">
                                @foreach($imageUrls as $imageUrl)
                                    <div class="part-catalog-photo-manager__item">
                                        <a href="{{$imageUrl}}" data-catalog-item-photo-trigger data-photo-index="{{$loop->index}}">
                                            <img src="{{$imageUrl}}" alt="{{$itemName($item)}}">
                                        </a>
                                        @if($catalog['source'] === 'driveparts' && $partCatalogShowPresenter->isDrivePartsSharedPlaceholderImageUrl($imageUrl))
                                            <span class="part-catalog-photo-manager__missing">&#1073;&#1077;&#1079; &#1092;&#1086;&#1090;&#1086;</span>
                                        @endif
                                        @if($catalog['source'] === 'nikolacars' && ! $isNikolaCarsSoldItem)
                                            <form method="POST" action="{{route('admin.zapchasti.photos.destroy', $item)}}">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="image_url" value="{{$imageUrl}}">
                                                <button type="submit" class="part-catalog-photo-manager__delete" onclick="return confirm('Удалить это фото из товара?')" aria-label="Удалить фото">&times;</button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="empty part-catalog-photo-manager__empty">Фото нет.</div>
                        @endif

                        @if($schemeImageUrls->isNotEmpty())
                            <div class="help" style="margin-top:14px;">Схема узла</div>
                            <div class="part-catalog-photo-manager__grid">
                                @foreach($schemeImageUrls as $schemeImageUrl)
                                    <div class="part-catalog-photo-manager__item part-catalog-photo-manager__item--scheme">
                                        <a href="{{$schemeImageUrl}}" data-catalog-item-photo-trigger data-photo-index="{{$imageUrls->count() + $loop->index}}">
                                            <img src="{{$schemeImageUrl}}" alt="Схема {{$itemName($item)}}">
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($catalog['source'] === 'nikolacars' && ! $isNikolaCarsSoldItem)
                            <form method="POST" action="{{route('admin.zapchasti.photos.store', $item)}}" enctype="multipart/form-data" class="part-catalog-photo-manager__upload" data-nikolacars-photo-upload-form>
                                @csrf
                                <input id="nikolacars-photo-upload-{{$item->id}}" type="file" name="photos[]" accept="image/*" multiple data-nikolacars-photo-input>
                                <label class="part-catalog-photo-manager__dropzone" for="nikolacars-photo-upload-{{$item->id}}" data-nikolacars-photo-dropzone>
                                    <span>Перетащите фото сюда</span>
                                    <small>или нажмите, чтобы выбрать файлы</small>
                                </label>
                            </form>
                        @endif
                </aside>
            </div>
        </div>
    </div>

    @if($lightboxImageUrls->isNotEmpty())
        <dialog class="part-catalog-photo-lightbox" data-catalog-item-photo-lightbox>
            <div class="part-catalog-photo-lightbox__toolbar">
                <span data-catalog-item-photo-counter></span>
                <button type="button" class="part-catalog-photo-lightbox__close" data-close-catalog-item-photo-lightbox aria-label="Закрыть">&times;</button>
            </div>
            <div class="part-catalog-photo-lightbox__stage">
                <button type="button" class="part-catalog-photo-lightbox__nav part-catalog-photo-lightbox__nav--prev" data-catalog-item-photo-prev aria-label="Предыдущее фото">&#8249;</button>
                <img src="" alt="{{$itemName($item)}}" data-catalog-item-photo-lightbox-image>
                <button type="button" class="part-catalog-photo-lightbox__nav part-catalog-photo-lightbox__nav--next" data-catalog-item-photo-next aria-label="Следующее фото">&#8250;</button>
            </div>
        </dialog>
    @endif

    @if($catalog['source'] === 'nikolacars' && $nikolaCarsRelatedRows->count() > 1)
        <div class="panel nikolacars-related-panel" style="margin-top:18px;">
            <h2 style="margin-top:0;">Объединенные позиции <span class="tag">{{$nikolaCarsRelatedRows->count()}} шт</span></h2>
            <p class="help" style="margin-top:-6px;">
                Все строки каталога НиколаКарз с первыми 7 цифрами артикула {{$nikolaCarsRelatedPartNumberPrefix ?: $item->part_number}}.
                Общая стоимость: {{number_format($nikolaCarsRelatedTotalValueUsd, 2, '.', ' ')}} USD
            </p>
            <table class="nikolacars-related-table">
                <thead><tr><th>Код</th><th>Название</th><th>Снято с донора / Категория</th><th>Цена</th><th>Остаток</th><th>Стоимость</th><th></th></tr></thead>
                <tbody>
                    @foreach($nikolaCarsRelatedRows as $relatedRow)
                        <tr class="@class(['nikolacars-current-related-row' => $relatedRow['is_current']])">
                            <td>{{$relatedRow['code']}}</td>
                            <td>
                                <strong>{{$relatedRow['name']}}</strong>
                                @if($relatedRow['is_current'])
                                    <span class="tag">Текущая</span>
                                @endif
                            </td>
                            <td>
                                @if($relatedRow['donor_car'])
                                    <a href="{{route('admin.donor-cars.show', $relatedRow['donor_car'])}}">{{$relatedRow['donor_vin']}}</a>
                                @else
                                    {{$relatedRow['location']}}
                                @endif
                            </td>
                            <td>
                                @if($relatedRow['price_usd'] !== null)
                                    {{number_format($relatedRow['price_usd'], 2, '.', ' ')}} USD
                                    <div class="help">
                                        цена из
                                        @if($relatedRow['price_source_url'])
                                            <a href="{{$relatedRow['price_source_url']}}" target="_blank" rel="noopener">{{$relatedRow['price_source_label']}}</a>
                                        @else
                                            {{$relatedRow['price_source_label']}}
                                        @endif
                                    </div>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{$relatedRow['stock_text']}} шт</td>
                            <td>{{$relatedRow['value_usd'] !== null ? number_format($relatedRow['value_usd'], 2, '.', ' ').' USD' : '-'}}</td>
                            <td class="actions"><a class="btn btn-secondary" href="{{route($catalog['route_prefix'].'.show', $relatedRow['item'])}}">Открыть</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($catalog['source'] === 'nikolacars')
        <div class="panel" style="margin-top:18px;">
            <h2 style="margin-top:0;">Продажи</h2>
            <table>
                <thead><tr><th>Дата</th><th>Документ</th><th>Кол-во</th><th>Цена</th><th>Сумма</th><th>Донор</th><th>Контрагент</th></tr></thead>
                <tbody>
                    @forelse($item->sales as $sale)
                        <tr>
                            <td>{{$sale->sold_at ? $sale->sold_at->timezone('Europe/Kiev')->format('Y-m-d') : '-'}}</td>
                            <td>{{$sale->document_number ?: '-'}}</td>
                            <td>{{rtrim(rtrim(number_format((float) $sale->quantity, 3, '.', ''), '0'), '.')}}</td>
                            <td>{{$sale->unit_price !== null ? number_format((float) $sale->unit_price, 2, '.', ' ').' '.$sale->currency : '-'}}</td>
                            <td>{{$sale->total_amount !== null ? number_format($sale->total_amount, 2, '.', ' ').' '.$sale->currency : '-'}}</td>
                            <td>
                                @if($sale->donorCar)
                                    <a href="{{ route('admin.donor-cars.show', $sale->donorCar) }}">{{ $sale->donorCar->vin }}</a>
                                @else
                                    {{ $sale->donor_vin ?: '-' }}
                                @endif
                            </td>
                            <td>{{$sale->counterparty ?: '-'}}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty">Продаж по этой позиции пока нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if($info->isNotEmpty() || $characteristics->isNotEmpty() || $extraRows->isNotEmpty())
        <div class="grid grid-2" style="margin-top:18px;">
            @if($info->isNotEmpty() || $extraRows->isNotEmpty())
                <div class="panel">
                    <h2 style="margin-top:0;">Данные карточки</h2>
                    <table>
                        <tbody>
                            @foreach($info->merge($extraRows) as $name => $value)
                                <tr>
                                    <th>{{ $name }}</th>
                                    <td>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($characteristics->isNotEmpty())
                <div class="panel">
                    <h2 style="margin-top:0;">Характеристики</h2>
                    <table>
                        <tbody>
                            @foreach($characteristics as $name => $value)
                                <tr>
                                    <th>{{ $name }}</th>
                                    <td>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <script>
            (() => {
                const photoUrls = @json($lightboxImageUrls->all());
                const lightbox = document.querySelector('[data-catalog-item-photo-lightbox]');
                const lightboxImage = lightbox?.querySelector('[data-catalog-item-photo-lightbox-image]');
                const photoCounter = lightbox?.querySelector('[data-catalog-item-photo-counter]');
                const prevButton = lightbox?.querySelector('[data-catalog-item-photo-prev]');
                const nextButton = lightbox?.querySelector('[data-catalog-item-photo-next]');
                const closeButton = lightbox?.querySelector('[data-close-catalog-item-photo-lightbox]');
                let currentPhotoIndex = 0;

                const showPhoto = (index) => {
                    if (!lightboxImage || photoUrls.length === 0) return;

                    currentPhotoIndex = (index + photoUrls.length) % photoUrls.length;
                    lightboxImage.src = photoUrls[currentPhotoIndex];
                    if (photoCounter) photoCounter.textContent = `${currentPhotoIndex + 1} / ${photoUrls.length}`;

                    const hasMultiple = photoUrls.length > 1;
                    if (prevButton) prevButton.hidden = !hasMultiple;
                    if (nextButton) nextButton.hidden = !hasMultiple;
                };

                document.querySelectorAll('[data-catalog-item-photo-trigger]').forEach((trigger) => {
                    trigger.addEventListener('click', (event) => {
                        event.preventDefault();
                        if (!lightbox) return;
                        showPhoto(Number(trigger.dataset.photoIndex || 0));
                        lightbox.showModal();
                    });
                });

                closeButton?.addEventListener('click', () => lightbox?.close());
                prevButton?.addEventListener('click', () => showPhoto(currentPhotoIndex - 1));
                nextButton?.addEventListener('click', () => showPhoto(currentPhotoIndex + 1));
                lightbox?.addEventListener('click', (event) => {
                    if (event.target === lightbox) lightbox.close();
                });
                lightbox?.addEventListener('keydown', (event) => {
                    if (event.key === 'ArrowLeft') showPhoto(currentPhotoIndex - 1);
                    if (event.key === 'ArrowRight') showPhoto(currentPhotoIndex + 1);
                });

                document.querySelectorAll('[data-nikolacars-photo-upload-form]').forEach((form) => {
                    const input = form.querySelector('[data-nikolacars-photo-input]');
                    const dropzone = form.querySelector('[data-nikolacars-photo-dropzone]');
                    if (!input || !dropzone) return;

                    const submitFiles = (files) => {
                        const imageFiles = Array.from(files || []).filter((file) => file.type.startsWith('image/'));
                        if (imageFiles.length === 0) return;

                        const transfer = new DataTransfer();
                        imageFiles.forEach((file) => transfer.items.add(file));
                        input.files = transfer.files;
                        form.submit();
                    };

                    input.addEventListener('change', () => submitFiles(input.files));
                    ['dragenter', 'dragover'].forEach((eventName) => {
                        dropzone.addEventListener(eventName, (event) => {
                            event.preventDefault();
                            dropzone.classList.add('is-dragover');
                        });
                    });
                    ['dragleave', 'drop'].forEach((eventName) => {
                        dropzone.addEventListener(eventName, () => dropzone.classList.remove('is-dragover'));
                    });
                    dropzone.addEventListener('drop', (event) => {
                        event.preventDefault();
                        submitFiles(event.dataTransfer?.files);
                    });
                });

                document.querySelectorAll('[data-nikolacars-prom-description-form]').forEach((form) => {
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        const button = form.querySelector('button[type="submit"]');
                        const status = form.querySelector('[data-nikolacars-prom-description-status]');
                        const oldText = button?.textContent;
                        if (button) { button.disabled = true; button.textContent = 'Сохраняем...'; }
                        if (status) status.textContent = '';
                        try {
                            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                            if (!response.ok) throw new Error('update failed');
                            if (button) { button.textContent = 'Сохранено'; setTimeout(() => { button.textContent = oldText || 'Сохранить'; }, 1200); }
                            if (status) status.textContent = 'Описание обновлено';
                        } catch (error) {
                            alert('Не удалось сохранить описание. Обновите страницу и попробуйте еще раз.');
                            if (button) button.textContent = oldText || 'Сохранить';
                        } finally {
                            if (button) button.disabled = false;
                        }
                    });
                });
            })();
        </script>

    <style>
        .part-catalog-show-grid {
            display: flex !important;
            flex-direction: column;
            gap: 18px;
        }
        .part-catalog-show-grid > .panel { order: 10; }
        .part-catalog-item-main-panel { position: relative; order: 1 !important; }
        .part-catalog-donor-panel { order: 2 !important; }
        .part-catalog-tesla-results-panel { order: 3 !important; }
        .part-catalog-tesla-results-heading {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .part-catalog-tesla-exact-tag { cursor: help; }
        .part-catalog-source-button { position: absolute; top: 14px; right: 14px; padding: 4px 8px; font-size: 12px; line-height: 1.2; }
        div.part-catalog-source-button { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; padding: 0; max-width: min(420px, 50%); }
        div.part-catalog-source-button .btn { padding: 4px 8px; font-size: 12px; line-height: 1.2; }
        .part-catalog-donor-links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .part-catalog-donor-links .tag { text-decoration: none; }
        .tag.tag-conflict {
            background: #7f1d1d;
            border-color: #7f1d1d;
            color: #fff;
        }
        .part-catalog-item-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(260px, 340px); gap: 22px; align-items: start; }
        .part-catalog-item-details { min-width: 0; }
        .part-catalog-part-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .part-catalog-invalid-part .stat {
            padding: 3px 8px;
            border-radius: 6px;
            background: #fee2e2;
            color: #991b1b;
        }
        .part-catalog-display-name-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }
        .part-catalog-price-history {
            margin-top: 10px;
            display: grid;
            gap: 5px;
            max-width: 420px;
        }
        .part-catalog-price-history__row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 6px 8px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #f8fafc;
            font-size: 13px;
        }
        .part-catalog-price-history__row span { color: var(--muted); }
        .part-catalog-name-row { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
        .part-catalog-name-inline-input {
            flex: 1 1 420px;
            min-width: min(420px, 100%);
            max-width: 100%;
        }
        .part-catalog-name-save-button { margin-left: 2px; }
        .part-catalog-name-edit-button {
            display: inline-grid;
            place-items: center;
            width: 24px;
            height: 24px;
            padding: 0;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--muted);
            line-height: 1;
            cursor: pointer;
        }
        .part-catalog-name-edit-button:hover { color: var(--accent); border-color: var(--accent); }
        .part-catalog-photo-manager { display: grid; gap: 10px; padding-top: 28px; }
        .part-catalog-photo-manager__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .part-catalog-photo-manager__item { position: relative; display: grid; gap: 6px; }
        .part-catalog-photo-manager__item img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
        .part-catalog-photo-manager__item--scheme img { object-fit: contain; padding: 6px; background: #fff; }
        .part-catalog-photo-manager__item a { cursor: zoom-in; }
        .part-catalog-photo-manager__missing { position: absolute; left: 8px; bottom: 8px; padding: 3px 7px; border-radius: 6px; background: rgba(17, 24, 39, .78); color: #fff; font-size: 12px; font-weight: 700; line-height: 1.2; }
        .part-catalog-photo-manager__delete {
            position: absolute;
            top: 6px;
            right: 6px;
            display: grid;
            place-items: center;
            width: 24px;
            height: 24px;
            min-width: 24px;
            min-height: 24px;
            padding: 0;
            border: 0;
            border-radius: 999px;
            background: #dc2626;
            color: #fff;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .22);
        }
        .part-catalog-photo-manager__delete:hover { background: #b91c1c; }
        .part-catalog-photo-manager__upload { display: grid; gap: 8px; padding-top: 8px; border-top: 1px solid var(--line); }
        .part-catalog-photo-manager__upload input[type="file"] { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .part-catalog-photo-manager__dropzone { display: grid; place-items: center; gap: 4px; min-height: 112px; padding: 14px; border: 1px dashed var(--line); border-radius: 8px; background: #fbfcfc; cursor: pointer; text-align: center; }
        .part-catalog-photo-manager__dropzone small { color: var(--muted); }
        .part-catalog-photo-manager__dropzone.is-dragover { border-color: var(--accent); background: #eef8f5; }
        .part-catalog-photo-manager__empty { padding: 14px; border: 1px dashed var(--line); border-radius: 8px; }
        .part-catalog-photo-lightbox {
            width: min(1180px, calc(100vw - 32px));
            max-height: calc(100vh - 32px);
            padding: 0;
            border: 0;
            border-radius: 14px;
            background: #0f171b;
            color: #fff;
            box-shadow: 0 24px 80px rgba(0, 0, 0, .42);
        }
        .part-catalog-photo-lightbox::backdrop { background: rgba(10, 17, 20, .72); }
        .part-catalog-photo-lightbox__toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; }
        .part-catalog-photo-lightbox__toolbar span { color: rgba(255, 255, 255, .78); font-size: 13px; }
        .part-catalog-photo-lightbox__close,
        .part-catalog-photo-lightbox__nav {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 8px;
            background: rgba(255, 255, 255, .08);
            color: #fff;
            cursor: pointer;
        }
        .part-catalog-photo-lightbox__close { font-size: 26px; line-height: 1; }
        .part-catalog-photo-lightbox__stage { position: relative; display: grid; place-items: center; min-height: min(720px, calc(100vh - 114px)); padding: 0 64px 20px; }
        .part-catalog-photo-lightbox__stage img { display: block; max-width: 100%; max-height: calc(100vh - 150px); object-fit: contain; border-radius: 10px; background: #fff; }
        .part-catalog-photo-lightbox__nav { position: absolute; top: 50%; transform: translateY(-50%); font-size: 34px; line-height: 1; }
        .part-catalog-photo-lightbox__nav--prev { left: 14px; }
        .part-catalog-photo-lightbox__nav--next { right: 14px; }
        .part-catalog-photo-lightbox__nav[hidden] { display: none; }
        .nikolacars-prom-description-form { display: grid; gap: 10px; margin: 16px 0; }
        .nikolacars-related-panel .tag { vertical-align: middle; }
        .nikolacars-current-related-row { background: #fbfcfc; }
        .nikolacars-current-related-row td:first-child { border-left: 4px solid var(--accent); }
        @media (max-width: 980px) {
            .part-catalog-item-layout { grid-template-columns: 1fr; }
            .part-catalog-photo-manager { padding-top: 0; }
            .part-catalog-photo-lightbox__stage { min-height: min(520px, calc(100vh - 104px)); padding: 0 14px 76px; }
            .part-catalog-photo-lightbox__stage img { max-height: calc(100vh - 178px); }
            .part-catalog-photo-lightbox__nav { top: auto; bottom: 18px; transform: none; }
            .part-catalog-photo-lightbox__nav--prev { left: calc(50% - 54px); }
            .part-catalog-photo-lightbox__nav--next { right: calc(50% - 54px); }
        }
    </style>
@endsection
