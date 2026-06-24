@php
    $currentPage = max(1, (int) ($currentPage ?? 1));
    $lastPage = max(1, (int) ($lastPage ?? 1));
    $total = max(0, (int) ($total ?? 0));
    $perPage = max(1, (int) ($perPage ?? 80));
    $from = $total === 0 ? 0 : (($currentPage - 1) * $perPage) + 1;
    $to = min($total, $currentPage * $perPage);
@endphp

<div class="donor-products-pagination" data-donor-products-pagination-root data-current-page="{{ $currentPage }}" data-last-page="{{ $lastPage }}">
    <div class="help donor-products-pagination__summary">
        @if($total > 0)
            Показано {{ $from }}-{{ $to }} из {{ $total }}
        @else
            Запчасти не найдены
        @endif
    </div>

    @if($lastPage > 1)
        <div class="donor-products-pagination__actions">
            <button type="button" class="btn btn-secondary btn-small" data-donor-products-page="{{ $currentPage - 1 }}" @disabled($currentPage <= 1)>Назад</button>
            <span class="help">Страница {{ $currentPage }} из {{ $lastPage }}</span>
            <button type="button" class="btn btn-secondary btn-small" data-donor-products-page="{{ $currentPage + 1 }}" @disabled($currentPage >= $lastPage)>Вперед</button>
        </div>
    @endif
</div>
