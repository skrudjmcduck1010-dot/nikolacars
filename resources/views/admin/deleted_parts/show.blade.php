@extends('layouts.admin', [
    'heading' => 'Удаленная запчасть',
    'subheading' => trim((string) ($deletedPart->sku ?: $deletedPart->part_number ?: $deletedPart->name ?: '#'.$deletedPart->id)),
])

@section('content')
    @php
        $catalogSnapshot = is_array($deletedPart->part_catalog_item_snapshot ?? null)
            ? $deletedPart->part_catalog_item_snapshot
            : [];
        $productSnapshots = collect($deletedPart->related_product_snapshots ?: [])
            ->filter(fn ($snapshot): bool => is_array($snapshot) && $snapshot !== [])
            ->values();

        if ($productSnapshots->isEmpty() && is_array($deletedPart->product_snapshot) && $deletedPart->product_snapshot !== []) {
            $productSnapshots = collect([$deletedPart->product_snapshot]);
        }

        $displayValue = function (mixed $value): string {
            if ($value instanceof \ArrayObject) {
                $value = $value->getArrayCopy();
            }

            if (is_array($value)) {
                return $value === []
                    ? '—'
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $value = trim((string) $value);

            return $value !== '' ? $value : '—';
        };

        $restoreLabel = trim((string) ($deletedPart->name ?: $deletedPart->part_number ?: $deletedPart->sku ?: $deletedPart->id));
        $summaryRows = [
            'Удалено' => $deletedPart->deleted_at?->format('d.m.Y H:i'),
            'Источник' => $deletedPart->source,
            'Код' => $deletedPart->sku,
            'Артикул' => $deletedPart->part_number,
            'Название' => $deletedPart->name,
            'Донор' => $deletedPart->donorCar?->vin ?: $deletedPart->donor_vin,
            'Кем удалено' => $deletedPart->deletedBy?->name,
            'ID товара' => $deletedPart->product_id,
            'ID строки каталога' => $deletedPart->part_catalog_item_id,
        ];
        $productFields = [
            'id' => 'ID',
            'sku' => 'Код',
            'external_sku' => 'Артикул',
            'name' => 'Название',
            'slug' => 'Slug',
            'donor_car_id' => 'ID донора',
            'source_part_catalog_item_id' => 'ID строки каталога',
            'storage_status' => 'Статус хранения',
            'notes' => 'Повреждения',
            'selling_price' => 'Цена продажи',
            'currency' => 'Валюта',
            'is_active' => 'Активна',
        ];
        $catalogFields = [
            'id' => 'ID',
            'source' => 'Источник',
            'source_url' => 'Source URL',
            'part_number' => 'Артикул',
            'name' => 'Название EN',
            'name_ru' => 'Название RU',
            'name_ua' => 'Название UA',
            'price_amount' => 'Цена',
            'currency' => 'Валюта',
            'model_label' => 'Модель',
            'compatibility_text' => 'Совместимость',
            'condition' => 'Состояние',
            'availability' => 'Наличие',
        ];
    @endphp

    <style>
        .deleted-part-actions {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }
        .deleted-part-detail-table th {
            white-space: nowrap;
            width: 220px;
        }
        .deleted-part-snapshot-table td {
            vertical-align: top;
        }
        .deleted-part-snapshot-value {
            max-width: 620px;
            overflow-wrap: anywhere;
        }
    </style>

    <div class="deleted-part-actions">
        <a class="btn btn-secondary" href="{{ route('admin.deleted-parts.index') }}">Назад к удаленным</a>
        <form
            method="POST"
            action="{{ route('admin.deleted-parts.restore', $deletedPart) }}"
            class="inline-form"
            onsubmit='return confirm(@json("Восстановить {$restoreLabel} из удаленных запчастей?"))'
        >
            @csrf
            <button type="submit">Восстановить</button>
        </form>
    </div>

    <div class="panel">
        <h2 class="section-title" style="margin-top:0;">Данные удаления</h2>
        <table class="deleted-part-detail-table">
            <tbody>
                @foreach($summaryRows as $label => $value)
                    <tr>
                        <th>{{ $label }}</th>
                        <td>
                            @if($label === 'Донор' && $deletedPart->donorCar)
                                <a href="{{ route('admin.donor-cars.show', $deletedPart->donorCar) }}">{{ $value }}</a>
                            @else
                                {{ $displayValue($value) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="panel">
        <h2 class="section-title" style="margin-top:0;">Снимок товара</h2>
        @if($productSnapshots->isEmpty())
            <p class="empty">Снимок товара не сохранен.</p>
        @else
            @foreach($productSnapshots as $snapshot)
                <table class="deleted-part-snapshot-table" style="margin-bottom:16px;">
                    <tbody>
                        @foreach($productFields as $field => $label)
                            <tr>
                                <th>{{ $label }}</th>
                                <td class="deleted-part-snapshot-value">{{ $displayValue($snapshot[$field] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        @endif
    </div>

    <div class="panel">
        <h2 class="section-title" style="margin-top:0;">Снимок каталога</h2>
        @if($catalogSnapshot === [])
            <p class="empty">Снимок строки каталога не сохранен.</p>
        @else
            <table class="deleted-part-snapshot-table">
                <tbody>
                    @foreach($catalogFields as $field => $label)
                        <tr>
                            <th>{{ $label }}</th>
                            <td class="deleted-part-snapshot-value">{{ $displayValue($catalogSnapshot[$field] ?? null) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
