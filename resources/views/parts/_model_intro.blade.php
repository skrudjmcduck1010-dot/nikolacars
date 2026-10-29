@php
  $usefulSlugs = $isRu
    ? ['kuzov', 'elektrika', 'komponenty-zakrytiia', 'podveska', 'visokovoltna-batareia']
    : ['kuzov', 'elektrika', 'komponenti-zakrittia', 'pidviska', 'visokovoltna-batareia'];
  $modelLinks = collect($categories)->filter(fn ($category) =>
    in_array($category['slug'] ?? '', $usefulSlugs, true) && (int) ($category['count'] ?? 0) > 0
  );
  $showModelIntro = $currentPage === 1 && !(($seoNoindex ?? false));
@endphp
<section class="parts-model-intro" data-model-intro @if(!$showModelIntro) hidden @endif aria-label="{{ $isRu ? 'Подбор запчастей' : 'Підбір запчастин' }}">
  <p>{{ $modelLanding['intro'] }}</p>
  <p>{{ $modelLanding['fitment'] }}</p>
  @if($modelLinks->isNotEmpty())
    <nav class="parts-model-links" aria-label="{{ $modelLanding['links_label'] }}">
      @foreach($modelLinks as $category)
        <a href="{{ $sectionUrl($selectedModelSlug, $category['slug']) }}">{{ $category['label'] }}</a>
      @endforeach
    </nav>
  @endif
  @if($selectedModelSlug === 'model-3-06-2017-12-2023' && collect($models)->contains('slug', 'model-3-highland-01-2024'))
    <p class="parts-model-generation">{{ $isRu ? 'Ищете детали для Highland?' : 'Шукаєте деталі для Highland?' }} <a href="{{ $sectionUrl('model-3-highland-01-2024') }}">Tesla Model 3 Highland</a>.</p>
  @endif
</section>
