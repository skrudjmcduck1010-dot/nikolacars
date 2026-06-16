@extends('layouts.admin', [
    'heading' => 'Ошибки',
    'subheading' => 'Товары, у которых найден конфликт в названии по языковым маркерам.',
])

@section('content')
    @php
        $partCatalogPresenter = app(\App\View\Admin\PartCatalog\PartCatalogIndexPresenter::class);
    @endphp

    <form method="GET" action="{{ route('admin.errors.index') }}" class="panel" style="margin-bottom:18px;">
        <div class="form-grid" style="align-items:end;">
            <div>
                <label for="errors-search">Поиск</label>
                <input id="errors-search" type="search" name="search" value="{{ $search }}" placeholder="Партномер или название">
            </div>
            <div>
                <label for="errors-source">Источник</label>
                <select id="errors-source" name="source">
                    <option value="">Все источники</option>
                    @foreach ($sources as $availableSource)
                        <option value="{{ $availableSource }}" @selected($source === $availableSource)>
                            {{ $sourceLabels[$availableSource] ?? $availableSource }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="actions full">
                <button type="submit" class="btn">Показать</button>
                @if ($search !== '' || $source !== '')
                    <a class="btn btn-secondary" href="{{ route('admin.errors.index') }}">Сбросить</a>
                @endif
            </div>
        </div>
    </form>

    <div class="panel" style="margin-bottom:18px;">
        <div class="topbar" style="margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">&#1058;&#1086;&#1074;&#1072;&#1088;&#1099; &#1089; &#1076;&#1083;&#1080;&#1085;&#1085;&#1099;&#1084; &#1072;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;&#1086;&#1084; &#1085;&#1072;&#1096;&#1077;&#1075;&#1086; &#1092;&#1086;&#1088;&#1084;&#1072;&#1090;&#1072;</h2>
                <div class="help" style="margin-top:6px;">
                    &#1064;&#1072;&#1073;&#1083;&#1086;&#1085;: {{ $articlePatternExample }} + &#1083;&#1080;&#1096;&#1085;&#1080;&#1077; &#1089;&#1080;&#1084;&#1074;&#1086;&#1083;&#1099;. &#1042;&#1089;&#1077;&#1075;&#1086; &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1081;: {{ $longSimilarArticleItems->total() }}.
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>&#1040;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;</th>
                    <th>&#1048;&#1089;&#1090;&#1086;&#1095;&#1085;&#1080;&#1082;</th>
                    <th>RU</th>
                    <th>UA</th>
                    <th>&#1052;&#1086;&#1076;&#1077;&#1083;&#1100;</th>
                    <th>&#1054;&#1073;&#1085;&#1086;&#1074;&#1083;&#1077;&#1085;</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($longSimilarArticleItems as $longArticleItem)
                    @php($sourceLabel = $sourceLabels[$longArticleItem->source] ?? $longArticleItem->source)
                    <tr>
                        <td style="width:24%;">
                            <a href="{{ $itemUrl($longArticleItem) }}"><strong>{{ $longArticleItem->part_number }}</strong></a>
                            <div class="help" style="margin-top:4px;">{{ $longArticleItem->name ?: '-' }}</div>
                        </td>
                        <td style="width:13%;">
                            @if ($longArticleItem->source_url && \Illuminate\Support\Str::startsWith($longArticleItem->source_url, ['http://', 'https://']))
                                <a href="{{ $longArticleItem->source_url }}" target="_blank" rel="noopener">{{ $sourceLabel }}</a>
                            @else
                                {{ $sourceLabel }}
                            @endif
                        </td>
                        <td style="width:20%;">{{ $longArticleItem->name_ru ?: '-' }}</td>
                        <td style="width:20%;">{{ $longArticleItem->name_ua ?: '-' }}</td>
                        <td>{{ $longArticleItem->model_label ?: '-' }}</td>
                        <td>{{ $longArticleItem->updated_at?->format('d.m.Y H:i') ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty">&#1058;&#1086;&#1074;&#1072;&#1088;&#1099; &#1089; &#1076;&#1083;&#1080;&#1085;&#1085;&#1099;&#1084; &#1072;&#1088;&#1090;&#1080;&#1082;&#1091;&#1083;&#1086;&#1084; &#1085;&#1072;&#1096;&#1077;&#1075;&#1086; &#1092;&#1086;&#1088;&#1084;&#1072;&#1090;&#1072; &#1085;&#1077; &#1085;&#1072;&#1081;&#1076;&#1077;&#1085;&#1099;.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $longSimilarArticleItems->links() }}
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px;">
        <div class="topbar" style="margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">&#1058;&#1086;&#1074;&#1072;&#1088;&#1099; &#1089; &#1086;&#1088;&#1080;&#1075;&#1080;&#1085;&#1072;&#1083; / &#1072;&#1085;&#1072;&#1083;&#1086;&#1075; &#1074; RU &#1080;&#1083;&#1080; UA &#1085;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1080;</h2>
                <div class="help" style="margin-top:6px;">
                    &#1055;&#1088;&#1086;&#1074;&#1077;&#1088;&#1103;&#1077;&#1090; &#1087;&#1086;&#1083;&#1103; RU &#1080; UA &#1085;&#1072; &#1089;&#1083;&#1086;&#1074;&#1072;: &#1086;&#1088;&#1080;&#1075;&#1080;&#1085;&#1072;&#1083;, &#1086;&#1088;&#1080;&#1075;&#1110;&#1085;&#1072;&#1083;, &#1072;&#1085;&#1072;&#1083;&#1086;&#1075;, " &#1073;&#1091; ", " &#1073;/&#1091; ". &#1042;&#1089;&#1077;&#1075;&#1086; &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1081;: {{ $localizedOriginMarkerItems->total() }}.
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>&#1058;&#1086;&#1074;&#1072;&#1088;</th>
                    <th>&#1048;&#1089;&#1090;&#1086;&#1095;&#1085;&#1080;&#1082;</th>
                    <th>RU</th>
                    <th>UA</th>
                    <th>&#1052;&#1086;&#1076;&#1077;&#1083;&#1100;</th>
                    <th>&#1054;&#1073;&#1085;&#1086;&#1074;&#1083;&#1077;&#1085;</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($localizedOriginMarkerItems as $originMarkerItem)
                    @php($sourceLabel = $sourceLabels[$originMarkerItem->source] ?? $originMarkerItem->source)
                    <tr>
                        <td style="width:24%;">
                            <a href="{{ $itemUrl($originMarkerItem) }}"><strong>{{ $originMarkerItem->part_number ?: '#'.$originMarkerItem->id }}</strong></a>
                            <div class="help" style="margin-top:4px;">{{ $originMarkerItem->name ?: '-' }}</div>
                        </td>
                        <td style="width:13%;">
                            @if ($originMarkerItem->source_url && \Illuminate\Support\Str::startsWith($originMarkerItem->source_url, ['http://', 'https://']))
                                <a href="{{ $originMarkerItem->source_url }}" target="_blank" rel="noopener">{{ $sourceLabel }}</a>
                            @else
                                {{ $sourceLabel }}
                            @endif
                        </td>
                        <td style="width:24%;">{{ $originMarkerItem->name_ru ?: '-' }}</td>
                        <td style="width:24%;">{{ $originMarkerItem->name_ua ?: '-' }}</td>
                        <td>{{ $originMarkerItem->model_label ?: '-' }}</td>
                        <td>{{ $originMarkerItem->updated_at?->format('d.m.Y H:i') ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty">&#1058;&#1086;&#1074;&#1072;&#1088;&#1099; &#1089; &#1086;&#1088;&#1080;&#1075;&#1080;&#1085;&#1072;&#1083; / &#1072;&#1085;&#1072;&#1083;&#1086;&#1075; &#1074; RU &#1080;&#1083;&#1080; UA &#1085;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1080; &#1085;&#1077; &#1085;&#1072;&#1081;&#1076;&#1077;&#1085;&#1099;.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $localizedOriginMarkerItems->links() }}
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px;">
        <div class="topbar" style="margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">&#1058;&#1086;&#1074;&#1072;&#1088;&#1099; &#1089; Tesla / &#1090;&#1077;&#1089;&#1083;&#1072; &#1074; &#1085;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1080;</h2>
                <div class="help" style="margin-top:6px;">
                    &#1055;&#1088;&#1086;&#1074;&#1077;&#1088;&#1103;&#1077;&#1090; &#1090;&#1086;&#1083;&#1100;&#1082;&#1086; RU &#1080; UA &#1085;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1103;. &#1042;&#1089;&#1077;&#1075;&#1086; &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1081;: {{ $teslaNameItems->total() }}.
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>&#1058;&#1086;&#1074;&#1072;&#1088;</th>
                    <th>&#1048;&#1089;&#1090;&#1086;&#1095;&#1085;&#1080;&#1082;</th>
                    <th>RU</th>
                    <th>UA</th>
                    <th>&#1052;&#1086;&#1076;&#1077;&#1083;&#1100;</th>
                    <th>&#1054;&#1073;&#1085;&#1086;&#1074;&#1083;&#1077;&#1085;</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($teslaNameItems as $teslaNameItem)
                    @php($sourceLabel = $sourceLabels[$teslaNameItem->source] ?? $teslaNameItem->source)
                    <tr>
                        <td style="width:24%;">
                            <a href="{{ $itemUrl($teslaNameItem) }}"><strong>{{ $teslaNameItem->part_number ?: '#'.$teslaNameItem->id }}</strong></a>
                            <div class="help" style="margin-top:4px;">{{ $teslaNameItem->name ?: '-' }}</div>
                        </td>
                        <td style="width:13%;">
                            @if ($teslaNameItem->source_url && \Illuminate\Support\Str::startsWith($teslaNameItem->source_url, ['http://', 'https://']))
                                <a href="{{ $teslaNameItem->source_url }}" target="_blank" rel="noopener">{{ $sourceLabel }}</a>
                            @else
                                {{ $sourceLabel }}
                            @endif
                        </td>
                        <td style="width:24%;">{{ $teslaNameItem->name_ru ?: '-' }}</td>
                        <td style="width:24%;">{{ $teslaNameItem->name_ua ?: '-' }}</td>
                        <td>{{ $teslaNameItem->model_label ?: '-' }}</td>
                        <td>{{ $teslaNameItem->updated_at?->format('d.m.Y H:i') ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty">&#1058;&#1086;&#1074;&#1072;&#1088;&#1099; &#1089; Tesla / &#1090;&#1077;&#1089;&#1083;&#1072; &#1074; &#1085;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1080; &#1085;&#1077; &#1085;&#1072;&#1081;&#1076;&#1077;&#1085;&#1099;.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $teslaNameItems->links() }}
        </div>
    </div>

    <div class="panel" style="margin-bottom:18px;">
        <div class="topbar" style="margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">Товары с model / модель в названии</h2>
                <div class="help" style="margin-top:6px;">
                    Проверяет только RU и UA названия. Всего позиций: {{ $modelNameItems->total() }}.
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Источник</th>
                    <th>RU</th>
                    <th>UA</th>
                    <th>Модель</th>
                    <th>Обновлен</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($modelNameItems as $modelNameItem)
                    @php($sourceLabel = $sourceLabels[$modelNameItem->source] ?? $modelNameItem->source)
                    <tr>
                        <td style="width:24%;">
                            <a href="{{ $itemUrl($modelNameItem) }}"><strong>{{ $modelNameItem->part_number ?: '#'.$modelNameItem->id }}</strong></a>
                            <div class="help" style="margin-top:4px;">{{ $modelNameItem->name ?: '-' }}</div>
                        </td>
                        <td style="width:13%;">
                            @if ($modelNameItem->source_url && \Illuminate\Support\Str::startsWith($modelNameItem->source_url, ['http://', 'https://']))
                                <a href="{{ $modelNameItem->source_url }}" target="_blank" rel="noopener">{{ $sourceLabel }}</a>
                            @else
                                {{ $sourceLabel }}
                            @endif
                        </td>
                        <td style="width:24%;">{{ $modelNameItem->name_ru ?: '-' }}</td>
                        <td style="width:24%;">{{ $modelNameItem->name_ua ?: '-' }}</td>
                        <td>{{ $modelNameItem->model_label ?: '-' }}</td>
                        <td>{{ $modelNameItem->updated_at?->format('d.m.Y H:i') ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty">Товары с model / модель в названии не найдены.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $modelNameItems->links() }}
        </div>
    </div>

    <div class="panel">
        <div class="topbar" style="margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">Конфликты в названиях</h2>
                <div class="help" style="margin-top:6px;">
                    Всего конфликтных товаров: {{ $totalConflictItems }}. В текущей выборке: {{ $items->total() }}.
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Источник</th>
                    <th>RU</th>
                    <th>UA</th>
                    <th>Конфликт</th>
                    <th>Обновлен</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $conflictItem) { ?>
                    <?php
                        $sourceLabel = $sourceLabels[$conflictItem->source] ?? $conflictItem->source;
                    $ruConflict = $partCatalogPresenter->localizedNameConflictText($conflictItem, 'ru');
                    $uaConflict = $partCatalogPresenter->localizedNameConflictText($conflictItem, 'ua');
                    ?>
                    <tr>
                        <td style="width:24%;">
                            <a href="{{ $itemUrl($conflictItem) }}">{{ $conflictItem->part_number ?: '#'.$conflictItem->id }}</a>
                            <div class="help" style="margin-top:4px;">{{ $conflictItem->name ?: '-' }}</div>
                            @if ($conflictItem->model_label)
                                <div class="help" style="margin-top:4px;">{{ $conflictItem->model_label }}</div>
                            @endif
                        </td>
                        <td style="width:13%;">
                            @if ($conflictItem->source_url && \Illuminate\Support\Str::startsWith($conflictItem->source_url, ['http://', 'https://']))
                                <a href="{{ $conflictItem->source_url }}" target="_blank" rel="noopener">{{ $sourceLabel }}</a>
                            @else
                                {{ $sourceLabel }}
                            @endif
                        </td>
                        <td style="width:19%;">{{ $conflictItem->name_ru ?: '-' }}</td>
                        <td style="width:19%;">{{ $conflictItem->name_ua ?: '-' }}</td>
                        <td style="width:17%;">
                            @if ($ruConflict !== '')
                                <span class="tag tag-danger">{{ $ruConflict }}</span>
                            @endif
                            @if ($uaConflict !== '')
                                <span class="tag tag-danger" style="margin-top:6px;">{{ $uaConflict }}</span>
                            @endif
                        </td>
                        <td>{{ $conflictItem->updated_at?->format('d.m.Y H:i') ?: '-' }}</td>
                    </tr>
                <?php } ?>
                @if ($items->isEmpty())
                    <tr>
                        <td colspan="6" class="empty">Конфликты в названиях не найдены.</td>
                    </tr>
                @endif
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $items->links() }}
        </div>
    </div>
@endsection
