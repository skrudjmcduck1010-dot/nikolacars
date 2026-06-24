@extends('layouts.admin', ['heading' => $warehouse->name])

@section('content')
    <div class="panel">
        <h2>Ячейки</h2>
        @if(! $warehouse->usesStructuredLocations())
            <p class="help">Не используются</p>
        @else
        <table>
            <thead><tr><th></th><th></th><th></th><th></th><th></th><th></th></tr></thead>
            <tbody>
            @forelse($warehouse->locations as $location)
                <tr><td>{{ $location->full_code }}</td><td>{{ $location->floorLabel() }}</td><td>{{ $location->zone }}</td><td>{{ $location->row }}</td><td>{{ $location->shelf }}</td><td>{{ $location->cell }}</td></tr>
            @empty
                <tr><td colspan="6" class="empty">Для этого склада ячейки не назначены.</td></tr>
            @endforelse
            </tbody>
        </table>
        @endif
    </div>
@endsection
