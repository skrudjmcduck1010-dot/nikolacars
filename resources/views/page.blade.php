@extends('layouts.app')

@php
  $isRu = ($locale ?? 'uk') === 'ru';

  $title = $isRu
    ? ($item->title_ru ?? $item->title_uk ?? $item->title ?? '')
    : ($item->title_uk ?? $item->title_ru ?? $item->title ?? '');

  $content = $isRu
    ? ($item->content_ru ?? $item->content_uk ?? $item->content ?? $item->html ?? '')
    : ($item->content_uk ?? $item->content_ru ?? $item->content ?? $item->html ?? '');
@endphp

@section('content')
  <h1>{{ $title }}</h1>

  <div class="content">
    {!! $content !!}
  </div>
@endsection
