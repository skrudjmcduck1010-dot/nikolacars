@php
  $relatedDefinitions = config('service_content', []);
  foreach (config('targeted_services', []) as $definition) {
    $relatedDefinitions[$definition['slug']] = array_merge($relatedDefinitions[$definition['slug']] ?? [], $definition);
  }
  $relatedSlugs = $relatedDefinitions[$serviceSlug]['related'] ?? [];
@endphp
@if($relatedSlugs)
<section class="service-article">
  <div class="container">
    <div class="service-article-card">
      <div class="service-related">
        <h2 class="service-related-title">{{ $isRu ? 'Связанные услуги Tesla' : 'Пов’язані послуги Tesla' }}</h2>
        <div class="service-related-grid">
          @foreach($relatedSlugs as $relatedSlug)
            @if(isset($relatedDefinitions[$relatedSlug]))
              <a class="service-related-link" href="{{ ($isRu ? '/ru/services/' : '/services/').$relatedSlug.'/' }}">
                <span>{{ $relatedDefinitions[$relatedSlug][$isRu ? 'name_ru' : 'name_uk'] }}</span>
              </a>
            @endif
          @endforeach
        </div>
      </div>
    </div>
  </div>
</section>
@endif
