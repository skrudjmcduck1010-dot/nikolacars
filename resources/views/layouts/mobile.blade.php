<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f766e">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    @php
        $pageTitle = trim(strip_tags(html_entity_decode((string) ($title ?? $heading ?? 'Мобильный склад'), ENT_QUOTES, 'UTF-8')));
    @endphp
    <title>{{ $pageTitle === 'Мобильный склад' ? $pageTitle : $pageTitle.' · Мобильный склад' }}</title>
    <style>
        :root {
            --bg: #f3f6f5;
            --panel: #ffffff;
            --line: #d7e0dd;
            --text: #1d2a31;
            --muted: #68757a;
            --accent: #0f766e;
            --accent-soft: #dff3ee;
            --danger: #9f2d2d;
        }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: var(--bg); color: var(--text); }
        a { color: var(--accent); text-decoration: none; }
        button, .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 46px; border: 1px solid transparent; border-radius: 8px; padding: 11px 14px; background: var(--accent); color: #fff; font-weight: 700; cursor: pointer; text-align: center; }
        .btn-secondary { background: #fff; color: var(--text); border-color: var(--line); }
        .btn-block { width: 100%; }
        .mobile-shell { min-height: 100vh; padding-bottom: 92px; }
        .mobile-header { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: max(12px, env(safe-area-inset-top)) 14px 12px; background: rgba(255, 255, 255, .96); border-bottom: 1px solid var(--line); backdrop-filter: blur(10px); }
        .mobile-header__title { min-width: 0; }
        .mobile-header h1 { margin: 0; font-size: 20px; line-height: 1.15; }
        .mobile-header p { margin: 3px 0 0; color: var(--muted); font-size: 13px; line-height: 1.3; }
        .mobile-main { display: grid; gap: 14px; padding: 14px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 14px; }
        .flash { padding: 12px 14px; border-radius: 8px; background: #e4f3ee; color: var(--accent); font-weight: 700; }
        .flash-error { background: #f8e2e2; color: var(--danger); }
        .help { color: var(--muted); font-size: 13px; line-height: 1.35; }
        .error { margin-top: 5px; color: var(--danger); font-size: 12px; line-height: 1.3; }
        .tag { display: inline-flex; align-items: center; min-height: 26px; padding: 4px 9px; border-radius: 999px; background: var(--accent-soft); color: var(--accent); font-size: 12px; font-weight: 700; }
        .muted { color: var(--muted); }
        .form-grid { display: grid; gap: 13px; }
        .autocomplete { position: relative; }
        .suggestions { position: absolute; z-index: 20; top: calc(100% + 6px); left: 0; right: 0; max-height: 320px; overflow-y: auto; border: 1px solid var(--line); border-radius: 8px; background: #fff; box-shadow: 0 16px 34px rgba(25, 32, 36, .16); }
        .suggestion { display: grid; gap: 3px; width: 100%; min-height: auto; padding: 11px 12px; border: 0; border-bottom: 1px solid var(--line); border-radius: 0; background: #fff; color: var(--text); text-align: left; }
        .suggestion:last-child { border-bottom: 0; }
        .suggestion:focus, .suggestion:hover { outline: none; background: var(--accent-soft); }
        .suggestion__title { font-weight: 800; line-height: 1.25; }
        .suggestion__meta { color: var(--muted); font-size: 12px; line-height: 1.35; }
        .suggestion-empty { padding: 11px 12px; color: var(--muted); font-size: 13px; }
        label { display: block; margin-bottom: 6px; font-weight: 700; }
        input, select, textarea { width: 100%; min-height: 46px; border: 1px solid var(--line); border-radius: 8px; padding: 11px 12px; background: #fff; color: var(--text); font: inherit; }
        textarea { min-height: 94px; resize: vertical; }
        input[type="file"] { padding: 9px; }
        .search-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
        .donor-list { display: grid; gap: 10px; }
        .donor-card { display: grid; grid-template-columns: 94px minmax(0, 1fr); gap: 11px; padding: 13px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--text); }
        .donor-card__preview { grid-row: 1 / span 2; width: 94px; aspect-ratio: 4 / 3; overflow: hidden; align-self: start; border: 1px solid var(--line); border-radius: 8px; background: #e8efed; color: var(--muted); }
        .donor-card__preview img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .donor-card__preview-empty { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; padding: 8px; font-size: 12px; font-weight: 700; line-height: 1.2; text-align: center; }
        .donor-card__top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .donor-card__top, .donor-card > .donor-card__meta { min-width: 0; }
        .donor-card__vin { overflow-wrap: anywhere; font-size: 17px; font-weight: 800; line-height: 1.2; }
        .donor-card__meta { color: var(--muted); font-size: 13px; line-height: 1.35; }
        .mobile-actions { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: center; }
        .mobile-stat-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-top: 12px; }
        .mobile-stat { min-width: 0; padding: 10px; border: 1px solid var(--line); border-radius: 8px; background: #f8fbfa; }
        .mobile-stat__value { font-size: 20px; line-height: 1; font-weight: 800; }
        .mobile-stat__label { margin-top: 4px; color: var(--muted); font-size: 12px; line-height: 1.2; }
        .part-filter { display: grid; gap: 10px; }
        .part-filter__category { display: grid; gap: 6px; }
        .part-filter__category label { margin-bottom: 0; font-size: 13px; }
        .part-category-filter__toggle { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; min-height: 44px; padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--text); font-size: 14px; font-weight: 700; line-height: 1.25; text-align: left; }
        .part-category-filter__toggle::after { content: 'v'; flex: 0 0 auto; color: var(--muted); font-size: 13px; line-height: 1; }
        .part-category-filter__toggle span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .part-category-filter__toggle[aria-expanded="true"]::after { transform: rotate(180deg); }
        .part-category-filter__toggle:disabled { cursor: not-allowed; opacity: .65; }
        .part-category-filter__menu { display: grid; gap: 3px; max-height: 42vh; overflow: auto; padding: 6px; border: 1px solid var(--line); border-radius: 8px; background: #fff; box-shadow: 0 12px 26px rgba(15, 23, 42, .12); }
        .part-category-filter__menu[hidden] { display: none; }
        .part-category-filter__option { display: grid; grid-template-columns: 20px minmax(0, 1fr); gap: 9px; align-items: start; margin: 0; padding: 9px 8px; border-radius: 6px; color: var(--text); font-size: 13px; font-weight: 600; line-height: 1.25; }
        .part-category-filter__option:focus-within { background: var(--accent-soft); }
        .part-category-filter__option input { width: 18px; min-height: 18px; height: 18px; margin: 0; accent-color: var(--accent); }
        .part-category-filter__reset { justify-self: start; min-height: 34px; margin-top: 4px; padding: 6px 10px; border-color: var(--line); background: #fff; color: var(--muted); font-size: 13px; }
        .part-filter__chips { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 2px; scrollbar-width: none; }
        .part-filter__chips::-webkit-scrollbar { display: none; }
        .part-filter__chip { min-height: 36px; white-space: nowrap; border-color: var(--line); background: #fff; color: var(--text); }
        .part-filter__chip.is-active { border-color: var(--accent); background: var(--accent); color: #fff; }
        .part-list { display: grid; gap: 10px; }
        .part-card { display: grid; grid-template-columns: 78px minmax(0, 1fr); gap: 11px; padding: 12px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--text); }
        .part-card--danger { border-color: #efb7b7; background: #fff1f1; }
        .part-card--success { border-color: #aed8b7; background: #f0fbf2; }
        .part-card--sale { grid-template-columns: 1fr; }
        .part-card__photo-form { position: relative; width: 78px; align-self: start; }
        .part-card__photo-input { position: absolute; width: 1px; min-height: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .part-card__photo { position: relative; display: block; width: 78px; aspect-ratio: 1; overflow: hidden; margin: 0; border: 1px solid var(--line); border-radius: 8px; background: #e8efed; color: var(--muted); cursor: pointer; }
        .part-card__photo img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .part-card__photo-empty { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 18px; font-weight: 800; }
        .part-card__photo-badge { position: absolute; left: 5px; right: 5px; bottom: 5px; min-height: 21px; padding: 3px 5px; border-radius: 6px; background: rgba(29, 42, 49, .82); color: #fff; font-size: 11px; font-weight: 800; line-height: 1.2; text-align: center; }
        .part-card__body { display: grid; gap: 7px; min-width: 0; }
        .part-card__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .part-card__title { min-width: 0; overflow-wrap: anywhere; font-size: 15px; line-height: 1.25; font-weight: 800; }
        a.part-card__title { color: var(--accent); text-decoration: underline; text-decoration-thickness: 1px; text-underline-offset: 3px; }
        .part-card__status { display: inline-flex; align-items: center; justify-content: flex-end; gap: 5px; flex-wrap: wrap; flex: 0 0 auto; }
        .part-origin-badge { display: inline-flex; align-items: center; justify-content: center; width: 22px; min-width: 22px; height: 22px; border-radius: 999px; background: #e0f2fe; color: #075985; font-size: 12px; font-weight: 800; line-height: 1; }
        .part-card__meta { color: var(--muted); font-size: 12px; line-height: 1.35; overflow-wrap: anywhere; }
        .part-card__damage-form { display: grid; gap: 5px; }
        .part-card__damage-form label { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.2; }
        .part-card__damage-form select { min-height: 38px; padding: 8px 10px; font-size: 13px; }
        .part-card__foot { display: flex; gap: 7px; flex-wrap: wrap; align-items: center; }
        .tag-warning { background: #fff3cd; color: #866000; }
        .tag-danger { background: #f8e2e2; color: var(--danger); }
        .tag-paid { background: #e7f5e8; color: #2f7a3a; }
        .tag-muted { background: #eef2f2; color: var(--muted); }
        .photo-picker { display: grid; gap: 10px; }
        .photo-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .photo-preview-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .photo-preview { position: relative; }
        .photo-preview img { width: 100%; aspect-ratio: 1; object-fit: cover; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
        .photo-preview button { position: absolute; top: 5px; right: 5px; width: 30px; min-width: 30px; height: 30px; min-height: 30px; padding: 0; border-radius: 999px; background: rgba(29, 42, 49, .86); color: #fff; font-size: 18px; line-height: 1; }
        .mobile-photo-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .mobile-photo-grid.is-sorting { user-select: none; }
        .mobile-photo-grid--scheme { grid-template-columns: 1fr; }
        .mobile-photo-tile { position: relative; overflow: hidden; border: 1px solid var(--line); border-radius: 8px; background: #fff; transition: border-color .12s ease, box-shadow .12s ease, transform .12s ease, opacity .12s ease; }
        .mobile-photo-tile.is-dragging { opacity: .28; outline: 2px dashed var(--accent); outline-offset: -2px; background: var(--accent-soft); }
        .mobile-photo-tile.is-dragging img { opacity: .38; }
        .mobile-photo-tile.is-drop-target { border-color: var(--accent); box-shadow: inset 0 0 0 2px var(--accent); transform: scale(.98); }
        .mobile-photo-tile img { display: block; width: 100%; aspect-ratio: 1; object-fit: cover; }
        .mobile-photo-open { position: absolute; inset: 0; z-index: 1; display: block; width: 100%; min-height: 0; padding: 0; border: 0; border-radius: 8px; background: transparent; color: transparent; cursor: zoom-in; }
        .mobile-photo-open:focus-visible { outline: 3px solid var(--accent); outline-offset: -3px; }
        .mobile-photo-tile--scheme img { aspect-ratio: 4 / 3; object-fit: contain; background: #f8fbfa; }
        .mobile-photo-tile .tag { position: absolute; z-index: 2; left: 6px; bottom: 6px; pointer-events: none; }
        .mobile-photo-drag-preview { position: fixed; left: 0; top: 0; z-index: 40; pointer-events: none; overflow: hidden; contain: layout paint; will-change: transform; border: 2px solid var(--accent); border-radius: 8px; background: #fff; box-shadow: 0 18px 42px rgba(29, 42, 49, .34); opacity: .96; transform-origin: top left; transition: none; }
        .mobile-photo-drag-preview img { display: block; width: 100%; height: 100%; aspect-ratio: 1; object-fit: cover; }
        .mobile-photo-drag-handle { position: absolute; top: 6px; left: 6px; z-index: 3; width: 38px; min-width: 38px; height: 38px; min-height: 38px; padding: 0; border-color: rgba(255, 255, 255, .74); border-radius: 999px; background: rgba(29, 42, 49, .86); color: #fff; box-shadow: 0 8px 18px rgba(29, 42, 49, .22); touch-action: none; }
        .mobile-photo-drag-handle svg { width: 22px; height: 22px; fill: currentColor; }
        .mobile-photo-delete-form { position: absolute; top: 6px; right: 6px; z-index: 2; }
        .mobile-photo-delete-button { width: 38px; min-width: 38px; height: 38px; min-height: 38px; padding: 0; border-color: rgba(255, 255, 255, .74); border-radius: 999px; background: rgba(159, 45, 45, .92); box-shadow: 0 8px 18px rgba(29, 42, 49, .22); }
        .mobile-photo-delete-button svg { width: 19px; height: 19px; fill: currentColor; }
        .mobile-photo-source-tag { top: 6px; bottom: auto; left: 48px; height: 26px; min-height: 26px; padding: 4px 9px; line-height: 1; white-space: nowrap; background: #111827; color: #fff; box-shadow: 0 6px 16px rgba(25, 32, 36, .18); }
        body.is-mobile-photo-viewer-open { overflow: hidden; }
        .mobile-photo-viewer { position: fixed; inset: 0; z-index: 100; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; gap: 10px; padding: max(12px, env(safe-area-inset-top)) 12px max(14px, env(safe-area-inset-bottom)); background: rgba(5, 10, 14, .96); color: #fff; }
        .mobile-photo-viewer[hidden] { display: none; }
        .mobile-photo-viewer__bar { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-height: 44px; }
        .mobile-photo-viewer__counter { min-width: 0; color: rgba(255, 255, 255, .78); font-size: 13px; font-weight: 800; line-height: 1; }
        .mobile-photo-viewer__close,
        .mobile-photo-viewer__nav { min-height: 0; padding: 0; border: 1px solid rgba(255, 255, 255, .22); border-radius: 999px; background: rgba(255, 255, 255, .12); color: #fff; box-shadow: 0 10px 24px rgba(0, 0, 0, .28); }
        .mobile-photo-viewer__close { width: 44px; min-width: 44px; height: 44px; font-size: 28px; line-height: 1; }
        .mobile-photo-viewer__frame { position: relative; display: flex; align-items: center; justify-content: center; min-width: 0; min-height: 0; overflow: hidden; touch-action: pan-y; }
        .mobile-photo-viewer__image { display: block; max-width: 100%; max-height: 100%; object-fit: contain; user-select: none; -webkit-user-drag: none; }
        .mobile-photo-viewer__nav { position: absolute; top: 50%; z-index: 2; width: 44px; min-width: 44px; height: 54px; transform: translateY(-50%); font-size: 34px; line-height: 1; }
        .mobile-photo-viewer__nav--prev { left: 0; }
        .mobile-photo-viewer__nav--next { right: 0; }
        .mobile-photo-viewer__hint { min-height: 20px; color: rgba(255, 255, 255, .62); font-size: 12px; font-weight: 700; text-align: center; }
        .sticky-actions { position: fixed; left: 0; right: 0; bottom: 0; z-index: 12; padding: 10px 14px max(10px, env(safe-area-inset-bottom)); background: rgba(255, 255, 255, .96); border-top: 1px solid var(--line); backdrop-filter: blur(10px); }
        .mobile-scroll-top { position: fixed; right: 14px; bottom: calc(82px + max(10px, env(safe-area-inset-bottom))); z-index: 13; width: 48px; min-width: 48px; height: 48px; min-height: 48px; padding: 0; border-color: rgba(15, 118, 110, .24); border-radius: 999px; background: var(--accent); color: #fff; box-shadow: 0 12px 26px rgba(15, 118, 110, .28); font-size: 24px; line-height: 1; opacity: 0; pointer-events: none; transform: translateY(8px); transition: opacity .18s ease, transform .18s ease; }
        .mobile-scroll-top.is-visible { opacity: 1; pointer-events: auto; transform: translateY(0); }
        .empty { padding: 18px 0; color: var(--muted); text-align: center; }
        nav[role="navigation"] { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; justify-content: center; color: var(--muted); font-size: 13px; }
        nav[role="navigation"] > div:first-child { display: none; }
        nav[role="navigation"] a,
        nav[role="navigation"] span[aria-current="page"] span,
        nav[role="navigation"] span[aria-disabled="true"] span { display: inline-flex; align-items: center; justify-content: center; min-width: 38px; min-height: 38px; border: 1px solid var(--line); border-radius: 999px; padding: 8px 12px; background: #fff; color: var(--text); }
        nav[role="navigation"] span[aria-current="page"] span { background: var(--accent); color: #fff; border-color: var(--accent); }
        @media (min-width: 720px) {
            .mobile-shell { max-width: 720px; margin: 0 auto; border-left: 1px solid var(--line); border-right: 1px solid var(--line); background: var(--bg); }
            .mobile-scroll-top { right: calc((100vw - 720px) / 2 + 14px); }
        }
        @media (max-width: 380px) {
            .donor-card { grid-template-columns: 82px minmax(0, 1fr); }
            .donor-card__preview { width: 82px; }
            .mobile-stat-row { grid-template-columns: 1fr; }
            .part-card { grid-template-columns: 64px minmax(0, 1fr); }
            .part-card__photo { width: 64px; }
        }
    </style>
</head>
<body>
<div class="mobile-shell">
    <header class="mobile-header">
        <div class="mobile-header__title">
            <h1>{{ $heading ?? 'Мобильный склад' }}</h1>
            @isset($subheading)
                <p>{{ $subheading }}</p>
            @endisset
        </div>
        <a class="btn btn-secondary" href="{{ $desktopUrl ?? route('admin.dashboard') }}">ПК</a>
    </header>

    <main class="mobile-main">
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="flash flash-error">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>
</div>
<button type="button" class="btn mobile-scroll-top" aria-label="&#1053;&#1072;&#1074;&#1077;&#1088;&#1093;" aria-hidden="true" tabindex="-1" data-mobile-scroll-top>&#8593;</button>
<script>
    (() => {
        const scrollTopButton = document.querySelector('[data-mobile-scroll-top]');

        if (! scrollTopButton) {
            return;
        }

        let isTicking = false;

        const updateButton = () => {
            const shouldShow = window.scrollY > window.innerHeight;

            scrollTopButton.classList.toggle('is-visible', shouldShow);
            scrollTopButton.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
            scrollTopButton.tabIndex = shouldShow ? 0 : -1;
            isTicking = false;
        };

        const requestUpdate = () => {
            if (isTicking) {
                return;
            }

            isTicking = true;
            window.requestAnimationFrame(updateButton);
        };

        scrollTopButton.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        window.addEventListener('scroll', requestUpdate, { passive: true });
        window.addEventListener('resize', requestUpdate);
        updateButton();
    })();
</script>
</body>
</html>
