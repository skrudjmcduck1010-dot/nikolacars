@extends('layouts.admin', [
    'heading' => 'Удаленные запчасти',
    'subheading' => 'Корзина удаленных позиций из товаров, доноров и NikolaCars',
])

@section('content')
    <style>
        .deleted-parts-table .deleted-part-actions {
            width: 1%;
            text-align: center;
            white-space: nowrap;
        }
        .deleted-part-restore-form {
            display: inline-flex;
        }
        .deleted-part-restore-button {
            width: 30px;
            height: 30px;
            padding: 0;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            background: #f0fdf4;
            color: #15803d;
        }
        .deleted-part-restore-button:hover {
            border-color: #86efac;
            background: #dcfce7;
        }
        .deleted-part-restore-button svg {
            width: 16px;
            height: 16px;
            display: block;
        }
        .deleted-part-link {
            color: var(--accent);
            font: inherit;
            text-decoration: underline;
            text-decoration-color: rgba(15, 118, 110, .28);
            text-decoration-thickness: 1px;
            text-underline-offset: 3px;
        }
        .deleted-part-link:hover {
            text-decoration-color: var(--accent);
        }
    </style>

    <div class="panel">
        <form method="GET" action="{{ route('admin.deleted-parts.index') }}" class="form-grid" style="margin-bottom:16px;">
            <div>
                <label for="deleted-part-search">Поиск</label>
                <input
                    id="deleted-part-search"
                    type="search"
                    name="search"
                    value="{{ $filters['search'] ?? '' }}"
                    placeholder="Название, артикул, код или VIN"
                >
            </div>
            <div class="full actions">
                <button type="submit">Показать</button>
                <a class="btn btn-secondary" href="{{ route('admin.deleted-parts.index') }}">Сбросить</a>
            </div>
        </form>

        <table class="deleted-parts-table">
            <thead>
                <tr>
                    <th>Удалено</th>
                    <th>Источник</th>
                    <th>Код</th>
                    <th>Артикул</th>
                    <th>Название</th>
                    <th>Донор</th>
                    <th>Кем</th>
                    <th class="deleted-part-actions">Действия</th>
                </tr>
            </thead>
            <tbody>
            @forelse($deletedParts as $part)
                @php($restoreLabel = trim((string) ($part->name ?: $part->part_number ?: $part->sku ?: $part->id)))
                <tr>
                    <td>{{ $part->deleted_at?->format('d.m.Y H:i') }}</td>
                    <td>{{ $part->source }}</td>
                    <td>
                        @if($part->sku)
                            <a class="deleted-part-link" href="{{ route('admin.deleted-parts.show', $part) }}">{{ $part->sku }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($part->part_number)
                            <a class="deleted-part-link" href="{{ route('admin.deleted-parts.show', $part) }}">{{ $part->part_number }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($part->name)
                            <a class="deleted-part-link" href="{{ route('admin.deleted-parts.show', $part) }}">{{ $part->name }}</a>
                        @else
                            <a class="deleted-part-link" href="{{ route('admin.deleted-parts.show', $part) }}">#{{ $part->id }}</a>
                        @endif
                    </td>
                    <td>
                        @if($part->donorCar)
                            <a href="{{ route('admin.donor-cars.show', $part->donorCar) }}">{{ $part->donorCar->vin }}</a>
                        @else
                            {{ $part->donor_vin ?: '—' }}
                        @endif
                    </td>
                    <td>{{ $part->deletedBy?->name ?: '—' }}</td>
                    <td class="deleted-part-actions">
                        <form
                            method="POST"
                            action="{{ route('admin.deleted-parts.restore', $part) }}"
                            class="deleted-part-restore-form"
                            onsubmit='return confirm(@json("Восстановить {$restoreLabel} из удаленных запчастей?"))'
                        >
                            @csrf
                            <button
                                type="submit"
                                class="deleted-part-restore-button"
                                title="Восстановить"
                                aria-label="Восстановить {{ $restoreLabel }}"
                            >
                                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 12a9 9 0 1 0 3-6.7"></path>
                                    <path d="M3 4v6h6"></path>
                                    <path d="M12 8v5l3 2"></path>
                                </svg>
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">Удаленных запчастей пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $deletedParts->links() }}
        </div>
    </div>
@endsection
