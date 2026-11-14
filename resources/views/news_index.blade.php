@extends('layouts.app')
@php $newsIsRu = ($locale ?? 'uk') === 'ru'; @endphp
@section('title', $newsIsRu ? 'Новости NikolaCars' : 'Новини NikolaCars')
@section('description', $newsIsRu ? 'Информация об услугах и запчастях NikolaCars.' : 'Інформація про послуги та запчастини NikolaCars.')
@push('head')
<meta name="robots" content="noindex, follow">
@endpush
@section('content')
<section class="page-simple">
  <div class="container">
    <div class="page-simple-card">
      <h1 class="page-simple-title">{{ $newsIsRu ? 'Новости NikolaCars' : 'Новини NikolaCars' }}</h1>
      <p class="page-simple-text">
        {{ $newsIsRu ? 'Актуальную информацию о ремонте Tesla и подборе деталей вы найдёте в разделах услуг и каталоге запчастей.' : 'Актуальну інформацію про ремонт Tesla та підбір деталей ви знайдете в розділах послуг і каталозі запчастин.' }}
      </p>
      <p><a href="{{ $newsIsRu ? '/ru/services/' : '/services/' }}">{{ $newsIsRu ? 'Услуги сервиса' : 'Послуги сервісу' }}</a> · <a href="{{ $newsIsRu ? '/ru/parts/' : '/parts/' }}">{{ $newsIsRu ? 'Каталог запчастей' : 'Каталог запчастин' }}</a></p>
    </div>
  </div>
</section>
@endsection
