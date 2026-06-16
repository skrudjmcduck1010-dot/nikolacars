@extends('layouts.admin', [
    'heading' => 'Названия и маркеры',
    'subheading' => 'Текущие пары RU / UA из названий каталога.',
])

@section('content')
    <style>
        .language-marker-form { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) auto; gap: 12px; align-items: end; margin-top: 14px; }
        .language-marker-table td { vertical-align: middle; }
        .unclassified-word-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .language-unclassified-name { min-width: 260px; overflow-wrap: anywhere; }
        @media (max-width: 760px) {
            .language-marker-form { grid-template-columns: 1fr; }
        }
    </style>

    <div data-name-markers-content>
    <div class="panel" style="margin-bottom:18px;">
        @php
            $showAllLanguageMarkersUrl = route('admin.name-markers.index', array_merge(request()->except(['language_markers_page']), [
                'show_all_language_markers' => 1,
            ]));
            $paginateLanguageMarkersUrl = route('admin.name-markers.index', request()->except(['show_all_language_markers', 'language_markers_page']));
        @endphp
        <div class="topbar" style="margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">Маркеры языка TeslaPartsUkraine, TSK</h2>
                <div class="help" style="margin-top:6px;">
                    Эти слова участвуют в определении, куда положить название: в name_ua или name_ru. Буквы считаются отдельно:
                    УКР {{ implode(', ', $languageMarkerLetters['ua']) }} · РУ {{ implode(', ', $languageMarkerLetters['ru']) }}.
                </div>
                <div class="help" style="margin-top:6px;">
                    @if ($showAllLanguageMarkers)
                        Показаны все {{ $languageMarkerCount }} маркеров.
                    @else
                        Всего маркеров: {{ $languageMarkerCount }}.
                    @endif
                </div>
            </div>
            <div class="actions">
                @if ($showAllLanguageMarkers)
                    <a class="btn btn-secondary" href="{{ $paginateLanguageMarkersUrl }}">Постранично</a>
                @else
                    <a class="btn btn-secondary" href="{{ $showAllLanguageMarkersUrl }}">Показать все</a>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('admin.name-markers.language-markers.store') }}" class="language-marker-form">
            @csrf
            <div>
                <label for="language-marker-ua">УКР маркер</label>
                <input id="language-marker-ua" name="ua_marker" value="{{ old('ua_marker') }}" placeholder="например: передній">
            </div>
            <div>
                <label for="language-marker-ru">РУ маркер</label>
                <input id="language-marker-ru" name="ru_marker" value="{{ old('ru_marker') }}" placeholder="например: передний">
            </div>
            <button type="submit" class="btn">Добавить</button>
        </form>

        <table class="language-marker-table" style="margin-top:16px;">
            <thead>
                <tr>
                    <th>УКР</th>
                    <th>РУ</th>
                    <th>Добавлен</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($languageMarkers as $marker)
                    <tr>
                        <td style="width:34%;">{{ $marker->ua_marker }}</td>
                        <td style="width:34%;">{{ $marker->ru_marker }}</td>
                        <td style="width:18%;">{{ $marker->created_at?->format('d.m.Y H:i') ?: '-' }}</td>
                        <td class="actions" style="width:14%;">
                            <form method="POST" action="{{ route('admin.name-markers.language-markers.rotate', $marker) }}" onsubmit="return confirm('Поменять УКР и РУ маркеры местами и перепроверить названия?')">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn-small btn-secondary">Ротейте</button>
                            </form>
                            <form method="POST" action="{{ route('admin.name-markers.language-markers.destroy', $marker) }}" onsubmit="return confirm('Удалить маркер языка и откатить названия, которые были определены по нему?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-small btn-danger">Удалить</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="empty">Запустите миграции, чтобы включить маркеры.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if (method_exists($languageMarkers, 'links'))
            <div style="margin-top:16px;">
                {{ $languageMarkers->links() }}
            </div>
        @endif

        <div style="margin-top:22px;">
            <div class="topbar" style="margin-bottom:12px;">
                <div>
                    <h3 style="margin:0;">Слова из неопределённых названий</h3>
                    <div class="help" style="margin-top:6px;">{{ $unclassifiedLocalizedNameWords->total() }} слов, отсортированы по частоте.</div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Слово</th>
                        <th>Кол-во</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($unclassifiedLocalizedNameWords as $word)
                        <tr>
                            <td style="width:52%;">{{ $word->word }}</td>
                            <td>{{ $word->count }}</td>
                            <td class="actions" style="width:34%;">
                                <form method="POST" action="{{ route('admin.name-markers.language-markers.store') }}" class="unclassified-word-actions" data-unclassified-word-form data-word="{{ $word->word }}">
                                    @csrf
                                    <input type="hidden" name="ua_marker">
                                    <input type="hidden" name="ru_marker">
                                    <button type="button" class="btn-small btn-secondary" data-add-word-marker="ua">Добавить УКР</button>
                                    <button type="button" class="btn-small btn-secondary" data-add-word-marker="ru">Добавить РУ</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="empty">Нет слов для подсчёта.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div style="margin-top:16px;">
                {{ $unclassifiedLocalizedNameWords->links() }}
            </div>
        </div>

        <div style="margin-top:22px;">
            <div class="topbar" style="margin-bottom:12px;">
                <div>
                    <h3 style="margin:0;">Не определены как Название РУ или УКР ({{ $unclassifiedLocalizedNameCount }})</h3>
                    <div class="help" style="margin-top:6px;">Показаны последние {{ $unclassifiedLocalizedNameItems->count() }} названий из каталогов TeslaPartsUkraine и TSK, где name_ru и name_ua пустые.</div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Парт №</th>
                        <th>Модель</th>
                        <th>Источник</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($unclassifiedLocalizedNameItems as $item)
                        @php
                            $sourceLabel = $item->source === 'tsk' ? 'TSK' : 'TeslaPartsUkraine';
                        @endphp
                        <tr>
                            <td class="language-unclassified-name">
                                <a href="{{ $itemUrl($item) }}">{{ $item->name }}</a>
                            </td>
                            <td>{{ $item->part_number ?: '-' }}</td>
                            <td>{{ $item->model_label ?: '-' }}</td>
                            <td>
                                @if ($item->source_url && \Illuminate\Support\Str::startsWith($item->source_url, ['http://', 'https://']))
                                    <a href="{{ $item->source_url }}" target="_blank" rel="noopener">{{ $sourceLabel }}</a>
                                @else
                                    <span class="help">{{ $sourceLabel }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="empty">Все названия TeslaPartsUkraine и TSK уже определены.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    </div>

    <script>
        const showNameMarkerStatus = (message) => {
            if (!message) {
                return;
            }

            let flash = document.querySelector('.flash');
            if (!flash) {
                flash = document.createElement('div');
                flash.className = 'flash';
                const content = document.querySelector('[data-name-markers-content]');
                content?.parentNode?.insertBefore(flash, content);
            }

            flash.textContent = message;
        };

        const refreshNameMarkersContent = async () => {
            const response = await fetch(window.location.href, {
                headers: {
                    'Accept': 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Не удалось обновить таблицы.');
            }

            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextContent = doc.querySelector('[data-name-markers-content]');
            const currentContent = document.querySelector('[data-name-markers-content]');

            if (!nextContent || !currentContent) {
                throw new Error('Не удалось найти обновлённые таблицы.');
            }

            currentContent.innerHTML = nextContent.innerHTML;
            bindNameMarkersPage();
        };

        const bindUnclassifiedWordForms = () => document.querySelectorAll('[data-unclassified-word-form]').forEach((form) => {
            if (form.dataset.bound === '1') {
                return;
            }
            form.dataset.bound = '1';

            const word = form.dataset.word || '';
            const uaInput = form.querySelector('input[name="ua_marker"]');
            const ruInput = form.querySelector('input[name="ru_marker"]');

            form.querySelectorAll('[data-add-word-marker]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const locale = button.dataset.addWordMarker;
                    const promptText = locale === 'ua'
                        ? `Введите украинский перевод для русского слова "${word}"`
                        : `Введите русский перевод для украинского слова "${word}"`;
                    const translation = window.prompt(promptText, '');

                    if (translation === null) {
                        return;
                    }

                    const cleanTranslation = translation.trim();

                    if (!cleanTranslation || !uaInput || !ruInput) {
                        return;
                    }

                    if (locale === 'ua') {
                        uaInput.value = cleanTranslation;
                        ruInput.value = word;
                    } else {
                        uaInput.value = word;
                        ruInput.value = cleanTranslation;
                    }

                    button.disabled = true;

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: new FormData(form),
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            const message = payload.message || Object.values(payload.errors || {})?.flat()?.[0] || 'Не удалось добавить маркер.';
                            throw new Error(message);
                        }

                        showNameMarkerStatus(payload.message || 'Маркер языка добавлен.');
                        await refreshNameMarkersContent();
                    } catch (error) {
                        alert(error.message || 'Не удалось добавить маркер.');
                    } finally {
                        button.disabled = false;
                    }
                });
            });
        });

        const bindNameMarkersPage = () => {
            bindUnclassifiedWordForms();
        };

        bindNameMarkersPage();
    </script>
@endsection
