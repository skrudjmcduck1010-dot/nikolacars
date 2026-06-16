@extends('layouts.admin', ['heading' => 'Движение #'.$movement->id])

@section('content')
    <div class="panel">
        <p><strong>Тип:</strong> {{ [
            'intake' => 'приемка',
            'move' => 'перемещение',
            'reserve' => 'резерв',
            'unreserve' => 'снятие резерва',
            'sale' => 'продажа',
            'writeoff' => 'списание',
            'adjustment' => 'корректировка',
        ][$movement->type] ?? $movement->type }}</p>
        <p><strong>Товар:</strong> {{ $movement->product->name }}</p>
        <p><strong>Количество:</strong> {{ $movement->quantity }}</p>
        <p><strong>Откуда:</strong> {{ $movement->fromLocation->full_code ?? '—' }}</p>
        <p><strong>Куда:</strong> {{ $movement->toLocation->full_code ?? '—' }}</p>
        <p><strong>Контрагент:</strong> {{ $movement->counterparty->name ?? '—' }}</p>
        <p><strong>Документ:</strong> {{ $movement->document_number ?? '—' }}</p>
        <p><strong>Причина:</strong> {{ $movement->reason ?? '—' }}</p>
        <p><strong>Комментарий:</strong> {{ $movement->comment ?? '—' }}</p>
    </div>
@endsection
