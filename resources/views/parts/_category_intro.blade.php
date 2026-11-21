<section class="parts-model-intro" data-model-intro @if($currentPage !== 1 || ($seoNoindex ?? false)) hidden @endif aria-label="{{ $isRu ? 'Подбор деталей' : 'Підбір деталей' }}">
  <p>{{ $catalogLanding['intro'] }}</p>
  <p>{{ $catalogLanding['fitment'] }}</p>
</section>
