@extends('layouts.admin', ['heading' => 'Лог парсинга Tesla.com', 'subheading' => 'Что сейчас происходит в цикле поиска деталей Tesla.com'])

@section('content')
    <div class="grid grid-3" style="margin-bottom:18px;" data-tesla-log-summary>
        <div class="panel">
            <div class="help">Статус</div>
            <div class="stat" data-summary-status>{{ $logState['summary']['status'] }}</div>
            <div class="help">Браузер: <span data-summary-browser>{{ $logState['summary']['browser'] ?? '—' }}</span></div>
            <div class="help" data-summary-refreshed>Обновлено: {{ $logState['refreshed_at'] }}</div>
        </div>
        <div class="panel">
            <div class="help">Проверено без API error</div>
            <div class="stat" data-summary-checked>{{ $logState['summary']['checked'] !== null ? number_format($logState['summary']['checked'], 0, '.', ' ') : '—' }}</div>
            <div class="help">Осталось: <span data-summary-unchecked>{{ $logState['summary']['unchecked'] !== null ? number_format($logState['summary']['unchecked'], 0, '.', ' ') : '—' }}</span></div>
            <div class="help">Всего проверено: <span data-summary-checked-total>{{ $logState['summary']['checked_total'] !== null ? number_format($logState['summary']['checked_total'], 0, '.', ' ') : '—' }}</span> / <span data-summary-total>{{ $logState['summary']['total'] !== null ? number_format($logState['summary']['total'], 0, '.', ' ') : '—' }}</span></div>
            <div class="help">API error: <span data-summary-api-error>{{ $logState['summary']['api_error'] !== null ? number_format($logState['summary']['api_error'], 0, '.', ' ') : '—' }}</span> · Auth: <span data-summary-auth-required>{{ $logState['summary']['auth_required'] !== null ? number_format($logState['summary']['auth_required'], 0, '.', ' ') : '—' }}</span> · Security: <span data-summary-security-blocked>{{ $logState['summary']['security_blocked'] !== null ? number_format($logState['summary']['security_blocked'], 0, '.', ' ') : '—' }}</span></div>
        </div>
        <div class="panel">
            <div class="help">Текущая пачка</div>
            <div class="stat" data-summary-batch-count>{{ count($logState['summary']['current_batch']) }}</div>
            <div class="help" data-summary-event>{{ $logState['summary']['last_event'] ?? '—' }}</div>
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px;">
            <div>
                <h2 style="margin:0;">Пачка в работе</h2>
                <div class="help">ID строки и артикул из последней строки Checking batch.</div>
            </div>
            <a class="btn btn-small btn-secondary" href="{{ route('admin.activity-logs.tesla-official') }}">Обновить</a>
        </div>
        <div class="catalog-filter-row" data-current-batch>
            @forelse($logState['summary']['current_batch'] as $part)
                <span class="catalog-filter-pill">{{ $part }}</span>
            @empty
                <span class="help">Нет активной пачки в хвосте лога.</span>
            @endforelse
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px;">
            <div>
                <h2 style="margin:0;">Последние 50 проверенных артикулов</h2>
                <div class="help">Список обновляется вместе с логом. Каждый артикул ведет на карточку Tesla.com.</div>
            </div>
        </div>
        <div class="catalog-filter-row" data-latest-checked-items>
            @forelse($logState['latest_checked_items'] as $item)
                <a class="catalog-filter-pill" href="{{ $item['url'] }}" title="{{ $item['name'] ?: $item['part_number'] }}">
                    {{ $item['part_number'] }}
                    @if($item['status'] !== '')
                        <span class="help">({{ $item['status'] }})</span>
                    @endif
                </a>
            @empty
                <span class="help">Проверенных артикулов пока нет.</span>
            @endforelse
        </div>
    </div>

    <div class="grid grid-3" style="margin-bottom:18px;" data-log-files>
        @foreach(['main' => 'Основной лог', 'out' => 'STDOUT процесса', 'err' => 'STDERR процесса'] as $key => $label)
            @php($file = $logState['files'][$key] ?? null)
            <div class="panel" data-log-file="{{ $key }}">
                <div class="help">{{ $label }}</div>
                <strong data-log-file-name>{{ $file['name'] ?? 'Файл не найден' }}</strong>
                <div class="help">
                    <span data-log-file-size>{{ $file['size_label'] ?? '—' }}</span>
                    ·
                    <span data-log-file-modified>{{ $file['modified_at'] ?? '—' }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="panel">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px;">
            <div>
                <h2 style="margin:0;">Последние строки</h2>
                <div class="help">Страница обновляет данные каждые 6 секунд.</div>
            </div>
            <div class="tabs" role="tablist" style="margin:0;">
                <button type="button" class="btn btn-small" data-log-tab="main">Основной</button>
                <button type="button" class="btn btn-small btn-secondary" data-log-tab="out">STDOUT</button>
                <button type="button" class="btn btn-small btn-secondary" data-log-tab="err">STDERR</button>
            </div>
        </div>

        <pre data-log-output style="white-space:pre-wrap;overflow:auto;max-height:620px;background:#111827;color:#e5e7eb;border-radius:8px;padding:16px;font-size:13px;line-height:1.5;">{{ implode("\n", $logState['logs']['main']) ?: 'Лог пуст.' }}</pre>
    </div>

    <script>
        (() => {
            const statusUrl = @json(route('admin.activity-logs.tesla-official.status'));
            let state = @json($logState);
            let activeLog = 'main';
            const output = document.querySelector('[data-log-output]');
            const tabs = document.querySelectorAll('[data-log-tab]');
            const formatNumber = (value) => value === null || value === undefined ? '—' : new Intl.NumberFormat('ru-RU').format(value);
            const setText = (selector, value) => {
                const node = document.querySelector(selector);
                if (node) node.textContent = value;
            };

            const renderBatch = () => {
                const root = document.querySelector('[data-current-batch]');
                if (!root) return;

                const batch = state.summary.current_batch || [];
                root.innerHTML = '';

                if (batch.length === 0) {
                    const empty = document.createElement('span');
                    empty.className = 'help';
                    empty.textContent = 'Нет активной пачки в хвосте лога.';
                    root.appendChild(empty);
                    return;
                }

                batch.forEach((part) => {
                    const pill = document.createElement('span');
                    pill.className = 'catalog-filter-pill';
                    pill.textContent = part;
                    root.appendChild(pill);
                });
            };

            const renderLatestCheckedItems = () => {
                const root = document.querySelector('[data-latest-checked-items]');
                if (!root) return;

                const items = state.latest_checked_items || [];
                root.innerHTML = '';

                if (items.length === 0) {
                    const empty = document.createElement('span');
                    empty.className = 'help';
                    empty.textContent = 'Проверенных артикулов пока нет.';
                    root.appendChild(empty);
                    return;
                }

                items.forEach((item) => {
                    const link = document.createElement('a');
                    link.className = 'catalog-filter-pill';
                    link.href = item.url;
                    link.title = item.name || item.part_number || '';
                    link.textContent = item.part_number || `#${item.id}`;

                    if (item.status) {
                        const status = document.createElement('span');
                        status.className = 'help';
                        status.textContent = ` (${item.status})`;
                        link.appendChild(status);
                    }

                    root.appendChild(link);
                });
            };

            const renderFiles = () => {
                Object.entries(state.files || {}).forEach(([key, file]) => {
                    const root = document.querySelector(`[data-log-file="${key}"]`);
                    if (!root) return;

                    root.querySelector('[data-log-file-name]').textContent = file?.name || 'Файл не найден';
                    root.querySelector('[data-log-file-size]').textContent = file?.size_label || '—';
                    root.querySelector('[data-log-file-modified]').textContent = file?.modified_at || '—';
                });
            };

            const render = () => {
                setText('[data-summary-status]', state.summary.status || 'Нет данных');
                setText('[data-summary-browser]', state.summary.browser || '—');
                setText('[data-summary-refreshed]', `Обновлено: ${state.refreshed_at || '—'}`);
                setText('[data-summary-checked]', formatNumber(state.summary.checked));
                setText('[data-summary-unchecked]', formatNumber(state.summary.unchecked));
                setText('[data-summary-checked-total]', formatNumber(state.summary.checked_total));
                setText('[data-summary-total]', formatNumber(state.summary.total));
                setText('[data-summary-api-error]', formatNumber(state.summary.api_error));
                setText('[data-summary-auth-required]', formatNumber(state.summary.auth_required));
                setText('[data-summary-security-blocked]', formatNumber(state.summary.security_blocked));
                setText('[data-summary-batch-count]', formatNumber((state.summary.current_batch || []).length));
                setText('[data-summary-event]', state.summary.last_event || '—');
                renderBatch();
                renderLatestCheckedItems();
                renderFiles();

                if (output) {
                    output.textContent = (state.logs?.[activeLog] || []).join('\n') || 'Лог пуст.';
                }
            };

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    activeLog = tab.dataset.logTab;
                    tabs.forEach((button) => {
                        button.classList.toggle('btn-secondary', button !== tab);
                    });
                    render();
                });
            });

            const poll = async () => {
                try {
                    const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                    if (response.ok) {
                        state = await response.json();
                        render();
                    }
                } catch (error) {
                    // Keep the last rendered state if the poll fails.
                } finally {
                    setTimeout(poll, 6000);
                }
            };

            setTimeout(poll, 6000);
        })();
    </script>
@endsection
