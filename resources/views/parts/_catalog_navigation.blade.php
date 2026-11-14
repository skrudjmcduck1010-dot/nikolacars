<nav aria-label="{{ $isRu ? 'Категории запчастей' : 'Категорії запчастин' }}">
  <ul class="parts-category-tree" data-categories>
    @foreach(($initialCatalog['navigation'] ?? []) as $item)
      <li>
        <a href="{{ $item['url'] }}" class="parts-category parts-category-node{{ $item['active'] ? ' active' : '' }}{{ $item['ancestor'] ? ' is-ancestor' : '' }}{{ $item['count'] === 0 ? ' is-unavailable' : '' }}" style="--category-depth:{{ $item['depth'] }}" @if($item['active']) aria-current="page" @endif>
          <span class="parts-category-chevron" aria-hidden="true">{{ $item['has_children'] ? ($item['active'] || $item['ancestor'] ? '⌄' : '›') : '' }}</span>
          <span class="parts-category-label">{{ $item['label'] }}@if($item['count'] === 0)<small>{{ $isRu ? 'Нет в наличии' : 'Немає в наявності' }}</small>@endif</span>
          <b>{{ $item['count'] }}</b>
        </a>
      </li>
    @endforeach
  </ul>
</nav>
