@if($competitorRefresh !== null && $canManageCompetitorRefresh)
    @php
        $competitorRefreshStatus = $competitorRefresh['status'] ?? null;
        $competitorRefreshIsRunning = ($competitorRefresh['is_running'] ?? false) === true;
        $competitorRefreshStateLabel = $competitorRefreshIsRunning
            ? 'в работе'
            : ($competitorRefreshStatus === 'done'
                ? 'готово'
                : ($competitorRefreshStatus === 'failed'
                    ? 'ошибка'
                    : ($competitorRefreshStatus === 'stopped' ? 'остановлен' : 'ожидает')));
        $competitorRefreshButtonLabel = $competitorRefreshIsRunning
            ? 'Обновляется...'
            : ($competitorRefreshStatus === 'stopped' ? 'Продолжить обновление' : 'Обновить запчасти конкурента');
    @endphp
    <div
        class="panel tcars-refresh-panel"
        data-tcars-refresh-panel
        data-status-url="{{ route('admin.part-catalog.source-competitor-refresh.status', ['source' => $catalog['source']]) }}"
        style="margin-bottom:18px;"
    >
        <div class="tcars-refresh-panel__head">
            <div>
                <div class="help">{{ html_entity_decode('Парсинг') }} {{ $refreshSourceLabel }}</div>
                <strong data-tcars-refresh-message>{{ $competitorRefresh['stopped_message'] ?? $competitorRefresh['message'] ?? html_entity_decode('Готов к обновлению каталога конкурента.') }}</strong>
            </div>
            <div class="tcars-refresh-panel__actions">
                <span class="catalog-badge" data-tcars-refresh-state>
                    {{ $competitorRefreshStateLabel }}
                </span>
                <button
                    type="button"
                    class="btn btn-small"
                    data-tcars-refresh-button
                    data-start-url="{{ $competitorRefreshStartUrl }}"
                    @disabled($competitorRefreshIsRunning)
                >
                    {{ $competitorRefreshButtonLabel }}
                </button>
            </div>
        </div>
        <div class="tcars-refresh-progress">
            <span style="width: {{ (int) ($competitorRefresh['progress_percent'] ?? 0) }}%;" data-tcars-refresh-bar></span>
        </div>
        <div class="tcars-refresh-meta">
            <span data-tcars-refresh-progress-text>{{ (int) ($competitorRefresh['progress_percent'] ?? 0) }}%</span>
            <span>Проверено страниц: <strong data-tcars-refresh-pages>{{ $competitorRefresh['progress_pages_opened'] ?? '—' }}</strong></span>
            <span>Карточек в листингах: <strong data-tcars-refresh-cards>{{ (int) ($competitorRefresh['progress_items_found'] ?? $competitorRefresh['products_found'] ?? $competitorRefresh['listing_products_seen'] ?? $competitorRefresh['site_products_found'] ?? $competitorRefresh['product_pages_seen'] ?? $competitorRefresh['products_seen'] ?? $competitorRefresh['items_seen'] ?? 0) }}</strong></span>
            <span>Сканируется модель: <strong data-tcars-refresh-current-model>{{ $competitorRefresh['progress_current_model'] ?? '—' }}</strong></span>
            <span>Найдено новых позиций: <strong data-tcars-refresh-found>{{ (int) ($competitorRefresh['catalog_products_created'] ?? 0) }}</strong></span>
            <span>Изменено цен в: <strong data-price-changes-count>{{ (int) ($competitorRefresh['prices_changed'] ?? 0) }}</strong> товаров</span>
            <span>Обход сайта: <strong data-tcars-refresh-crawl-duration>{{ $competitorRefresh['crawl_duration_label'] ?? '—' }}</strong></span>
            <span>{{ html_entity_decode('Последний парсинг:') }} <strong data-tcars-refresh-finished>{{ $competitorRefresh['finished_label'] ?? $competitorRefresh['finished_at'] ?? '—' }}</strong></span>
        </div>
        @if(! empty($catalog['parsing_logic']))
            <div class="tcars-refresh-meta tcars-refresh-meta--details">
                <span>Источник: <strong>{{ $catalog['parsing_logic']['source'] }}</strong></span>
                <span>Обход: <strong>{{ $catalog['parsing_logic']['crawl'] }}</strong></span>
                <span>Карточка: <strong>{{ $catalog['parsing_logic']['detail'] }}</strong></span>
                <span>Сохранение: <strong>{{ $catalog['parsing_logic']['save'] }}</strong></span>
                <span>Фото: <strong>после парсинга внешние изображения переносятся в локальное хранилище</strong></span>
                @if($catalog['source'] === 'driveparts')
                    <span>Обновлено из листинга: <strong data-driveparts-listing-updated>{{ (int) ($competitorRefresh['catalog_products_updated'] ?? $competitorRefresh['products_updated'] ?? 0) }}</strong></span>
                    <span>Карточек новых товаров: <strong data-driveparts-detail-pages>{{ (int) ($competitorRefresh['product_compatibility_pages_fetched'] ?? 0) }}</strong></span>
                    <span>Карточек пропущено: <strong data-driveparts-detail-skipped>{{ (int) ($competitorRefresh['product_detail_pages_skipped'] ?? 0) }}</strong></span>
                @endif
            </div>
        @endif
    </div>
@endif
