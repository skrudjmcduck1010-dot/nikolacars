@extends('layouts.app')

@section('content')
    <h1>{{ $title }}</h1>
    @if(!empty($published_at))
        <p style="opacity:.7;">{{ $published_at->format('Y-m-d') }}</p>
    @endif
    <div class="content">
        {!! $content !!}
    </div>
@endsection
