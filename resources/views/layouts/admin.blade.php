<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">
    @php
        $pageTitle = trim(strip_tags(html_entity_decode((string) ($title ?? $heading ?? 'НиколаКарз'), ENT_QUOTES, 'UTF-8')));
    @endphp
    <title>{{ $pageTitle === 'НиколаКарз' ? $pageTitle : $pageTitle.' · НиколаКарз' }}</title>
    <style>
        :root {
            --bg: #f4f6f7;
            --panel: #ffffff;
            --line: #d9e0e3;
            --text: #1d2a31;
            --muted: #6a7479;
            --accent: #0f766e;
            --accent-soft: #d9f1ed;
            --danger: #9f2d2d;
            --warning: #ab6a00;
        }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: linear-gradient(180deg, #edf3f3 0%, var(--bg) 100%); color: var(--text); }
        a { color: var(--accent); text-decoration: none; }
        .shell { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
        .sidebar { padding: 24px; background: #17242d; color: #eef4f4; }
        .sidebar a { color: #eef4f4; display: block; padding: 8px 0; opacity: .92; }
        .sidebar-count { color: #b8c7cd; font-size: 12px; opacity: .86; }
        .sidebar .group { margin-top: 22px; font-size: 12px; text-transform: uppercase; letter-spacing: .12em; opacity: .6; }
        .brand { display: grid; gap: 10px; margin-bottom: 18px; }
        .brand img { display: block; width: 176px; max-width: 100%; height: auto; }
        .brand span { font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #c7d2d7; }
        .main { padding: 28px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 16px; }
        .topbar .icon-btn { width: 38px; height: 38px; padding: 0; border-radius: 999px; font-size: 18px; line-height: 1; }
        .settings-menu { position: relative; }
        .settings-menu summary { list-style: none; }
        .settings-menu summary.tag { display: inline-flex; align-items: center; cursor: pointer; }
        .settings-menu summary.tag:focus-visible { outline: 2px solid rgba(15, 118, 110, .35); outline-offset: 2px; }
        .settings-menu summary::-webkit-details-marker { display: none; }
        .settings-menu-panel { position: absolute; right: 0; top: calc(100% + 8px); z-index: 20; min-width: 230px; padding: 8px; border: 1px solid var(--line); border-radius: 14px; background: var(--panel); box-shadow: 0 18px 46px rgba(25, 32, 36, .16); }
        .settings-menu-panel a { display: block; padding: 9px 10px; border-radius: 10px; color: var(--text); font-weight: 600; }
        .settings-menu-panel a:hover { background: var(--accent-soft); color: var(--accent); }
        .breadcrumbs { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; color: var(--muted); font-size: 13px; }
        .breadcrumbs a { color: var(--muted); }
        .breadcrumbs a:hover { color: var(--accent); }
        .breadcrumbs .separator { color: #9aa2a6; }
        .breadcrumbs .current { color: var(--text); font-weight: 600; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 18px; padding: 20px; box-shadow: 0 10px 30px rgba(25, 32, 36, .05); }
        .grid { display: grid; gap: 18px; }
        .grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .stat { font-size: 28px; font-weight: 700; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
        .actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .btn, button { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; border: 1px solid transparent; padding: 10px 16px; font-weight: 600; background: var(--accent); color: white; cursor: pointer; }
        .btn-small { padding: 6px 12px; font-size: 12px; }
        .btn-secondary { background: transparent; color: var(--text); border-color: var(--line); }
        .btn-danger { background: var(--danger); }
        .tag { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; background: var(--accent-soft); color: var(--accent); }
        .tag-warning { background: #f6ead0; color: var(--warning); }
        .tag-danger { background: #f4d9d9; color: var(--danger); }
        .tag-exchange { background: #ffedd5; color: #c2410c; }
        .tag-exchange-with-rate { display: inline-grid; justify-items: center; gap: 1px; line-height: 1.1; text-align: center; }
        .tag-exchange-rate { font-size: 9px; font-weight: 600; }
        .topbar-rate { display: inline-grid; gap: 2px; min-height: 38px; padding: 5px 12px; border: 1px solid var(--line); border-radius: 14px; background: #fff7ed; color: #9a3412; line-height: 1.1; white-space: nowrap; }
        .topbar-rate__label { font-size: 11px; font-weight: 700; color: #c2410c; }
        .topbar-rate__value { font-size: 14px; font-weight: 800; color: #7c2d12; }
        .tag-paid { background: #14532d; color: #ffffff; }
        .tag-archived { background: #e5e7eb; color: #64748b; }
        .donor-status { display: inline-flex; align-items: center; min-height: 26px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 400; line-height: 1.2; white-space: nowrap; }
        .donor-status--transit { background: #dcfce7; color: #166534; }
        .donor-status--sto { background: #fef3c7; color: #92400e; }
        .donor-status--dismantling { background: #e0f2fe; color: #075985; }
        .donor-status--dismantled { background: #ede9fe; color: #5b21b6; }
        .donor-status-select { width: auto; min-width: 140px; border: 0; cursor: pointer; }
        .donor-cost-note { display: inline-flex; align-items: center; min-height: 24px; margin-left: 8px; padding: 3px 8px; border-radius: 999px; background: #fee2e2; color: #991b1b; font-size: 12px; line-height: 1.2; white-space: nowrap; }
        .donor-status-select:disabled { cursor: wait; opacity: .7; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 12px; background: white; color: var(--text); }
        textarea { min-height: 110px; resize: vertical; }
        .full { grid-column: 1 / -1; }
        .help { color: var(--muted); font-size: 13px; }
        .error { color: var(--danger); font-size: 13px; margin-top: 4px; }
        .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
        .photo-item { display: grid; gap: 8px; color: var(--text); }
        .photo-item img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border: 1px solid var(--line); border-radius: 12px; background: white; }
        .photo-item span { display: flex; align-items: center; gap: 8px; font-size: 13px; }
        .photo-item input[type="checkbox"] { width: auto; }
        .flash { margin-bottom: 18px; padding: 12px 16px; border-radius: 14px; background: #e5f2ed; color: var(--accent); }
        .background-status { display: none; margin-bottom: 18px; padding: 12px 16px; border: 1px solid var(--line); border-radius: 14px; background: #fff7d6; color: #7a4b00; }
        .background-status.is-visible { display: block; }
        .background-status.is-done { background: #e5f2ed; color: var(--accent); }
        .background-status.is-failed { background: #f8e2e2; color: #8b2a2a; }
        .background-status-title { font-weight: 800; }
        .background-status-message { margin-top: 4px; }
        .scroll-top-btn { position: fixed; left: 18px; bottom: 18px; z-index: 1000; width: 46px; height: 46px; padding: 0; border: 1px solid rgba(15, 118, 110, .22); border-radius: 999px; background: rgba(255, 255, 255, .28); color: rgba(15, 118, 110, .72); box-shadow: 0 8px 24px rgba(25, 32, 36, .12); backdrop-filter: blur(6px); transition: background .18s ease, color .18s ease, opacity .18s ease, transform .18s ease; }
        .scroll-top-btn:hover, .scroll-top-btn:focus-visible { background: rgba(255, 255, 255, .78); color: var(--accent); opacity: 1; transform: translateY(-2px); outline: none; }
        .scroll-top-btn svg { display: block; width: 22px; height: 22px; }
        .empty { color: var(--muted); padding: 18px 0; }
        .inline-form { display: inline; }
        .table-preview { width: 74px; height: 54px; object-fit: cover; border-radius: 8px; border: 1px solid var(--line); display: block; background: white; }
        .preview-placeholder { display: inline-flex; width: 74px; height: 54px; align-items: center; justify-content: center; border: 1px dashed var(--line); border-radius: 8px; color: var(--muted); font-size: 12px; text-align: center; }
        .donor-car-card { display: grid; gap: 14px; }
        .donor-car-title { font-size: 32px; line-height: 1.15; font-weight: 800; }
        .section-title { margin: 10px 0 0; font-size: 20px; line-height: 1.25; }
        .photo-upload-form { margin-top: 10px; padding-top: 18px; border-top: 1px solid var(--line); }
        .warehouse-location-form { grid-template-columns: minmax(150px, 1.2fr) repeat(5, minmax(70px, 1fr)); gap: 8px; }
        .location-floor-list { display: grid; gap: 12px; margin-bottom: 12px; }
        .location-floor-group { display: grid; gap: 8px; }
        .location-floor-title { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
        .location-floor-title .btn-small { width: 26px; height: 26px; padding: 0; line-height: 1; }
        .location-cell-tag { display: inline-grid; gap: 2px; padding: 4px 10px; border-radius: 12px; background: var(--accent-soft); color: var(--accent); font-size: 12px; line-height: 1.15; }
        .location-cell-tag small { color: var(--muted); font-size: 10px; font-weight: 600; }
        .location-cell-tag--warning { background: #f6ead0; color: var(--warning); }
        .modal { width: min(640px, calc(100vw - 32px)); border: 1px solid var(--line); border-radius: 18px; padding: 20px; background: var(--panel); color: var(--text); box-shadow: 0 24px 70px rgba(25, 32, 36, .25); }
        .modal::backdrop { background: rgba(29, 42, 49, .35); }
        .modal-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
        .modal-header h2 { margin: 0; font-size: 22px; }
        nav[role="navigation"] { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; color: var(--muted); font-size: 13px; }
        nav[role="navigation"] > div { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        nav[role="navigation"] svg { width: 20px; height: 20px; display: block; }
        nav[role="navigation"] a,
        nav[role="navigation"] span[aria-current="page"] span,
        nav[role="navigation"] span[aria-disabled="true"] span { display: inline-flex; align-items: center; justify-content: center; min-width: 38px; min-height: 38px; border: 1px solid var(--line); border-radius: 999px; padding: 8px 12px; background: white; color: var(--text); }
        nav[role="navigation"] span[aria-current="page"] span { background: var(--accent); color: white; border-color: var(--accent); }
        nav[role="navigation"] span[aria-disabled="true"] span { color: #a7afb2; background: transparent; }
        @media (max-width: 980px) {
            .shell { grid-template-columns: 1fr; }
            .grid-4, .grid-2, .form-grid, .warehouse-location-form { grid-template-columns: 1fr; }
            .sidebar { padding-bottom: 10px; }
            .main { padding: 16px; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .donor-car-title { font-size: 26px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        @php
            $currentUser = auth()->user();
            $canManageCashbook = $currentUser?->hasPermission('cashbook.manage');
            $canManageValeraCashbook = $currentUser?->hasPermission('valera_cashbook.manage');
            $canManageStoWorkOrders = $currentUser?->hasPermission('sto_work_orders.manage');
            $canManageCustomerOrders = $currentUser?->hasPermission('customer_orders.manage');
            $canManageStoEmployees = $currentUser?->hasPermission('sto_employees.manage');
            $canManageUsers = $currentUser?->hasPermission('admin.users.manage');
            $canManageDonorCars = $currentUser?->hasPermission('donor_cars.manage');
            $canManageCounterparties = $currentUser?->hasPermission('counterparties.manage');
            $canManageMobileParts = $currentUser?->hasPermission('mobile_parts.manage');
            $canManageWarehouses = $currentUser?->hasPermission('warehouses.manage');
            $canManagePurchases = $currentUser?->hasPermission('purchases.manage');
            $canManageNikolaCarsCatalog = $currentUser?->hasPermission('nikolacars_catalog.manage');
            $canViewNikolaCarsSales = $currentUser?->hasPermission('nikolacars_sales.view');
            $canManageCategories = $currentUser?->hasPermission('categories.manage');
            $canManageBrands = $currentUser?->hasPermission('brands.manage');
            $canManageProducts = $currentUser?->hasPermission('products.manage');
            $canViewTeslaCatalog = $currentUser?->hasPermission('tesla_catalog.view');
            $canViewTeslaOfficialCatalog = $canViewTeslaCatalog;
            $canViewPartCatalog = $currentUser?->hasPermission('part_catalog.view');
            $canViewTeslaPartsUkraineCatalog = $currentUser?->hasPermission('teslapartsukraine_catalog.view');
            $canViewTskCatalog = $currentUser?->hasPermission('tsk_catalog.view');
            $canViewStockTeslaCatalog = $currentUser?->hasPermission('stock_tesla_catalog.view');
            $canViewCompetitorsRu = $currentUser?->hasPermission('competitors_ru.view');
            $canViewDrivePartsCatalog = $currentUser?->hasPermission('driveparts_catalog.view');
            $canViewDkPartsCatalog = $currentUser?->hasPermission('dkparts_catalog.view');
            $canViewErazborkaCatalog = $currentUser?->hasPermission('erazborka_catalog.view');
            $canViewTopRazborkaCatalog = $currentUser?->hasPermission('toprazborka_catalog.view');
            $canViewTeslaWestPartsCatalog = $currentUser?->hasPermission('teslawestparts_catalog.view');
            $canViewTeslaCompanyCatalog = $currentUser?->hasPermission('teslacompany_catalog.view');
            $canViewExchangeRates = $currentUser?->hasPermission('exchange_rates.view');
            $hasWarehouseMenu = $canManageMobileParts || $canManageWarehouses || $canManageDonorCars || $canManageCounterparties || $canManageStoEmployees;
            $hasReferenceMenu = $canManageCategories || $canManageBrands || $canManageProducts || $canViewTeslaCatalog;
            $hasCompetitorMenu = $canViewPartCatalog || $canViewTeslaPartsUkraineCatalog || $canViewTskCatalog || $canViewStockTeslaCatalog || $canViewCompetitorsRu || $canViewDrivePartsCatalog || $canViewDkPartsCatalog || $canViewErazborkaCatalog || $canViewTopRazborkaCatalog || $canViewTeslaWestPartsCatalog || $canViewTeslaCompanyCatalog || $canViewTeslaOfficialCatalog;
            $canViewReports = $currentUser?->hasPermission('reports.view');
            $canViewActivityLogs = $currentUser?->hasPermission('activity_logs.view');
            $sidebarActivityLogCount = $canViewActivityLogs
                ? ' ('.number_format((int) \App\Models\AdminActivityLog::query()->count(), 0, '.', ' ').')'
                : '';
            $sidebarCatalogSources = ['tcarservice', 'teslapartsukraine', 'tsk', 'stock-tesla', 'teslahelp', 'driveparts', 'dkparts', 'erazborka', 'toprazborka', 'teslawestparts', 'teslacompany', 'tesla_official'];
            $sidebarCatalogCounts = $hasCompetitorMenu
                ? \Illuminate\Support\Facades\Cache::remember('part-catalog:sidebar-unique-counts:v3', now()->addMinutes(15), function () use ($sidebarCatalogSources): array {
                    $partNumberColumn = \Illuminate\Support\Facades\Schema::hasColumn('part_catalog_items', 'part_number_compact')
                        ? 'part_number_compact'
                        : 'part_number';

                    return collect($sidebarCatalogSources)
                        ->mapWithKeys(function (string $source) use ($partNumberColumn): array {
                            $query = \App\Models\PartCatalogItem::query()->where('source', $source);

                            if ($source === 'teslapartsukraine') {
                                $query
                                    ->where(function ($builder): void {
                                        $builder
                                            ->whereNotNull('raw_attributes->product_url')
                                            ->orWhereNotNull('raw_attributes->listing_product_url');
                                    })
                                    ->where(function ($builder): void {
                                        $builder
                                            ->whereNull('source_url')
                                            ->orWhere('source_url', 'not like', '%route=tesla/catalog/product%');
                                    });
                            }

                            $count = (int) $query
                                ->selectRaw("count(distinct nullif({$partNumberColumn}, '')) + sum(case when {$partNumberColumn} is null or {$partNumberColumn} = '' then 1 else 0 end) as unique_parts_count")
                                ->value('unique_parts_count');

                            return [$source => $count];
                        })
                        ->all();
                })
                : [];
            $sidebarCatalogCount = fn (string $source): string => isset($sidebarCatalogCounts[$source])
                ? ' ('.number_format((int) $sidebarCatalogCounts[$source], 0, '.', ' ').')'
                : '';
        @endphp
        <div class="brand">
            <img src="{{ asset('nikolacars-logo.png') }}" alt="НиколаКарз">
        </div>
        <a href="{{ route('admin.dashboard') }}">Панель</a>

        <div class="group">СТО</div>
        @if ($canManageCashbook)
            <a href="{{ route('admin.cashbook.index') }}">Касса и работы</a>
        @endif
        @if ($canManageValeraCashbook)
            <a href="{{ route('admin.valera-cashbook.index') }}">Касса Валера</a>
        @endif
        @if ($canManageStoWorkOrders)
            <a href="{{ route('admin.sto-work-orders.index') }}">Заказ-наряды</a>
            <a href="{{ route('admin.customer-orders.index') }}">Заказы</a>
        @endif
        @if ($canManageCustomerOrders && ! $canManageStoWorkOrders)
            <a href="{{ route('admin.customer-orders.index') }}">Заказы</a>
        @endif
        @if ($canManagePurchases)
            <a href="{{ route('admin.purchases.index') }}">Закупки</a>
        @endif
        @if ($canManageNikolaCarsCatalog)
            <a href="{{ route('admin.zapchasti.index') }}">Запчасти НиколаКарз</a>
        @endif
        @if ($canViewNikolaCarsSales)
            <a href="{{ route('admin.nikolacars-sales.index') }}">Продажи</a>
        @endif

        @if ($hasWarehouseMenu)
            <div class="group">Склад</div>
        @endif
        @if ($canManageMobileParts)
            <a href="{{ route('admin.mobile.parts.index') }}">Мобильное добавление</a>
        @endif
        @if ($canManageWarehouses)
            <a href="{{ route('admin.warehouses.index') }}">Склад</a>
        @endif
        @if ($canManageDonorCars)
            <a href="{{ route('admin.donor-cars.index') }}">Доноры</a>
        @endif
        @if ($canManageCounterparties)
            <a href="{{ route('admin.counterparties.index') }}">Контрагенты</a>
        @endif
        @if ($canManageStoEmployees)
            <a href="{{ route('admin.sto-employees.index') }}">Сотрудники</a>
        @endif

        @if ($canViewReports)
            <div class="group">Отчеты</div>
            <a href="{{ route('admin.reports.monthly') }}">По месяцам</a>
        @endif

        @if ($hasReferenceMenu)
            <div class="group">Справочники</div>
        @endif
        @if ($canManageCategories)
            <a href="{{ route('admin.categories.index') }}">Категории</a>
        @endif
        @if ($canManageBrands)
            <a href="{{ route('admin.brands.index') }}">Бренды</a>
        @endif
        @if ($canManageProducts)
            <a href="{{ route('admin.products.index') }}">Запчасти</a>
        @endif
        @if ($hasCompetitorMenu)
            <div class="group">Конкуренты</div>
        @endif
        @if ($canViewPartCatalog)
            <a href="{{ route('admin.part-catalog.index') }}">TCARS<span class="sidebar-count">{{ $sidebarCatalogCount('tcarservice') }}</span></a>
        @endif
        @if ($canViewTeslaCompanyCatalog)
            <a href="{{ route('admin.teslacompany-catalog.index') }}">TeslaCompany<span class="sidebar-count">{{ $sidebarCatalogCount('teslacompany') }}</span></a>
        @endif
        @if ($canViewTeslaPartsUkraineCatalog)
            <a href="{{ route('admin.teslapartsukraine-catalog.index') }}">TeslaPartsUkraine<span class="sidebar-count">{{ $sidebarCatalogCount('teslapartsukraine') }}</span></a>
        @endif
        @if ($canViewTskCatalog)
            <a href="{{ route('admin.tsk-catalog.index') }}">TSK<span class="sidebar-count">{{ $sidebarCatalogCount('tsk') }}</span></a>
        @endif
        @if ($canViewStockTeslaCatalog)
            <a href="{{ route('admin.stock-tesla-catalog.index') }}">Stock Tesla<span class="sidebar-count">{{ $sidebarCatalogCount('stock-tesla') }}</span></a>
        @endif
        @if ($canViewDrivePartsCatalog)
            <a href="{{ route('admin.driveparts-catalog.index') }}">DriveParts<span class="sidebar-count">{{ $sidebarCatalogCount('driveparts') }}</span></a>
        @endif
        @if ($canViewDkPartsCatalog)
            <a href="{{ route('admin.dkparts-catalog.index') }}">DK-Parts<span class="sidebar-count">{{ $sidebarCatalogCount('dkparts') }}</span></a>
        @endif
        @if ($canViewErazborkaCatalog)
            <a href="{{ route('admin.erazborka-catalog.index') }}">Erazborka<span class="sidebar-count">{{ $sidebarCatalogCount('erazborka') }}</span></a>
        @endif
        @if ($canViewTopRazborkaCatalog)
            <a href="{{ route('admin.toprazborka-catalog.index') }}">TopRazborka<span class="sidebar-count">{{ $sidebarCatalogCount('toprazborka') }}</span></a>
        @endif
        @if ($canViewTeslaWestPartsCatalog)
            <a href="{{ route('admin.teslawestparts-catalog.index') }}">Tesla West Parts<span class="sidebar-count">{{ $sidebarCatalogCount('teslawestparts') }}</span></a>
        @endif
        @if ($canViewTeslaOfficialCatalog)
            <a href="{{ route('admin.tesla-official-catalog.index') }}">Tesla.com<span class="sidebar-count">{{ $sidebarCatalogCount('tesla_official') }}</span></a>
        @endif
        @if ($canViewCompetitorsRu)
            <a href="{{ route('admin.competitors-ru.index') }}">КонкурентыРУ<span class="sidebar-count">{{ $sidebarCatalogCount('teslahelp') }}</span></a>
        @endif

        @if ($canViewActivityLogs)
            <div class="group">Журнал</div>
            <a href="{{ route('admin.activity-logs.index') }}">Журнал действий<span class="sidebar-count">{{ $sidebarActivityLogCount }}</span></a>
            <a href="{{ route('admin.activity-logs.tesla-official') }}">Лог парсинга Tesla.com</a>
        @endif
    </aside>

    <main class="main">
        @php
            $routeName = request()->route()?->getName();
            $resourceLabels = [
                'warehouses' => 'Склад',
                'locations' => 'Ячейки',
                'categories' => 'Категории',
                'brands' => 'Бренды',
                'donor-cars' => 'Доноры',
                'counterparties' => 'Контрагенты',
                'customer-orders' => 'Заказы',
                'tesla-official-catalog' => 'Каталог Tesla.com',
                'part-catalog' => 'Каталог TCARS',
                'teslapartsukraine-catalog' => 'Каталог TeslaPartsUkraine',
                'tsk-catalog' => 'Каталог TSK',
                'stock-tesla-catalog' => 'Каталог Stock Tesla',
                'competitors-ru' => 'КонкурентыРУ',
                'driveparts-catalog' => 'Каталог DriveParts',
                'dkparts-catalog' => 'Каталог DK-Parts',
                'erazborka-catalog' => 'Каталог Erazborka',
                'toprazborka-catalog' => 'Каталог TopRazborka',
                'teslawestparts-catalog' => 'Каталог Tesla West Parts',
                'teslacompany-catalog' => 'Каталог TeslaCompany',
                'zapchasti' => 'Запчасти НиколаКарз',
                'nikolacars-sales' => 'Продажи',
                'dictionary' => 'Словарь',
                'errors' => 'Ошибки',
                'products' => 'Запчасти',
                'stock-items' => 'Остатки',
                'purchases' => 'Закупки',
                'movements' => 'Движения',
                'reservations' => '',
                'cashbook' => 'Касса и работы',
                'valera-cashbook' => 'Касса Валера',
                'cashbook-labels' => 'Настройки меток',
                'sto-work-orders' => 'Заказ-наряды',
                'sto-employees' => 'Сотрудники',
                'users' => 'Доступы пользователей',
            ];
            $actionLabels = [
                'intake' => 'Приемка',
                'move' => 'Перемещение',
                'reserve' => '',
                'unreserve' => 'Снятие резерва',
                'sale' => 'Продажа',
                'writeoff' => 'Списание',
                'adjustment' => 'Корректировка',
            ];
            $resourceIndexRoutes = [
                'products' => 'admin.zapchasti.index',
            ];
            $breadcrumbs = [['label' => 'Панель', 'url' => route('admin.dashboard')]];
            $topbarRateDate = \Illuminate\Support\Carbon::today();
            $topbarUsdRate = app(\App\Services\ExchangeRateService::class)->displayUsdRate($topbarRateDate);

            if ($routeName && $routeName !== 'admin.dashboard') {
                $routeParts = explode('.', $routeName);
                $resource = $routeParts[1] ?? null;
                $action = $routeParts[2] ?? null;

                if ($resource === 'actions') {
                    $breadcrumbs[] = ['label' => 'Операции', 'url' => null];
                    $breadcrumbs[] = ['label' => $actionLabels[request()->route('type')] ?? ($heading ?? 'Операция'), 'url' => null];
                } elseif ($resource === 'exchange-rates') {
                    $breadcrumbs[] = ['label' => $heading ?? 'Курсы валют', 'url' => null];
                } elseif ($resource === 'reports') {
                    $breadcrumbs[] = ['label' => 'Отчеты', 'url' => null];
                    $breadcrumbs[] = ['label' => $heading ?? 'Страница', 'url' => null];
                } elseif (isset($resourceLabels[$resource])) {
                    $indexRoute = $resourceIndexRoutes[$resource] ?? "admin.{$resource}.index";
                    $breadcrumbs[] = [
                        'label' => $resourceLabels[$resource],
                        'url' => $action === 'index' ? null : route($indexRoute),
                    ];

                    if ($action && $action !== 'index' && ($heading ?? null) !== ($resourceLabels[$resource] ?? null)) {
                        $breadcrumbs[] = ['label' => $heading ?? 'Страница', 'url' => null];
                    }
                }
            }
        @endphp

        <div class="topbar">
            <div>
                <nav class="breadcrumbs" aria-label="Хлебные крошки">
                    @foreach ($breadcrumbs as $crumb)
                        @if (! $loop->first)
                            <span class="separator">/</span>
                        @endif

                        @if ($crumb['url'] && ! $loop->last)
                            <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                        @else
                            <span class="current">{{ $crumb['label'] }}</span>
                        @endif
                    @endforeach
                </nav>
                <div class="page-heading-row">
                    <h1 style="margin:0;">{{ $heading ?? 'НиколаКарз' }}</h1>
                    @yield('heading-actions')
                </div>
                @isset($subheading)
                    <div class="help" style="margin-top:6px;">{{ $subheading }}</div>
                @endisset
            </div>
            <div class="actions">
                @yield('topbar-actions')
                <span class="topbar-rate" title="{{ $topbarUsdRate['source_label'] ?? '' }}">
                    <span class="topbar-rate__label">Курс на сегодня, {{ $topbarRateDate->format('d.m.Y') }}</span>
                    <span class="topbar-rate__value">$ {{ number_format((float) ($topbarUsdRate['rate'] ?? 0), 2, '.', ' ') }}</span>
                </span>
                @if ($canManageUsers)
                    <details class="settings-menu">
                        <summary class="tag">{{ auth()->user()->name }} · {{ auth()->user()->roleLabel() }}</summary>
                        <div class="settings-menu-panel">
                            <a href="{{ route('admin.users.index') }}">Доступы</a>
                            <a href="{{ route('admin.dictionary.index') }}">Словарь</a>
                            <a href="{{ route('admin.errors.index') }}">Ошибки</a>
                        </div>
                    </details>
                @else
                    <span class="tag">{{ auth()->user()->name }} · {{ auth()->user()->roleLabel() }}</span>
                @endif
                @if ($canViewExchangeRates)
                    <a class="btn btn-small btn-secondary icon-btn" href="{{ route('admin.exchange-rates.index') }}" aria-label="Курсы валют" title="Курсы валют">$</a>
                @endif
                @if ($canManageCashbook && str_starts_with((string) $routeName, 'admin.cashbook'))
                    <a class="btn btn-small btn-secondary" href="{{ route('admin.cashbook-labels.index') }}">Настройки</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn-secondary">Выйти</button>
                </form>
            </div>
        </div>

        @php
            $officialDownloadRouteDonor = request()->route('donorCar');
            $officialDownloadDonorId = $officialDownloadRouteDonor instanceof \App\Models\DonorCar
                ? (int) $officialDownloadRouteDonor->id
                : null;
        @endphp
        <div class="background-status" data-official-download-status data-official-download-current-donor="{{ $officialDownloadDonorId }}"></div>

        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="flash" style="background:#f8e2e2;color:#8b2a2a;">
                {{ $errors->first() }}
            </div>
        @endif

        @yield('content')

        @if (str_starts_with((string) $routeName, 'admin.donor-cars'))
            <button type="button" class="scroll-top-btn" data-scroll-top aria-label="Наверх" title="Наверх">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m18 15-6-6-6 6"></path>
                </svg>
            </button>
        @endif
    </main>
</div>
@if (str_starts_with((string) $routeName, 'admin.donor-cars'))
    <script>
        (() => {
            const scrollTopButton = document.querySelector('[data-scroll-top]');

            scrollTopButton?.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();
    </script>
@endif
<script>
    (() => {
        const statusBox = document.querySelector('[data-official-download-status]');
        const endpoint = @json(route('admin.donor-cars.official-downloads.status'));
        const currentDonorCarId = Number(statusBox?.dataset.officialDownloadCurrentDonor || '0') || null;
        const activeStorageKey = currentDonorCarId ? `officialCatalogDownloadActive:${currentDonorCarId}` : null;
        let pollTimer = null;

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const latestDownload = (downloads) => {
            if (! currentDonorCarId) {
                return null;
            }

            const sorted = downloads
                .filter((download) => Number(download.donor_car_id || '0') === currentDonorCarId)
                .sort((left, right) => {
                const leftTime = Date.parse(left.finished_at || left.started_at || '') || 0;
                const rightTime = Date.parse(right.finished_at || right.started_at || '') || 0;

                return rightTime - leftTime;
            });

            return sorted.find((download) => download.state === 'running') || sorted[0] || null;
        };

        const render = (download) => {
            if (! statusBox || ! download) {
                return;
            }

            if (! currentDonorCarId || Number(download.donor_car_id || '0') !== currentDonorCarId) {
                return;
            }

            const state = download.state || 'running';
            const title = state === 'done'
                ? 'Официальный каталог выкачан'
                : state === 'failed'
                    ? 'Ошибка выкачки официального каталога'
                    : 'Выкачка официального каталога';

            statusBox.classList.toggle('is-done', state === 'done');
            statusBox.classList.toggle('is-failed', state === 'failed');
            statusBox.classList.add('is-visible');
            statusBox.innerHTML = `
                <div class="background-status-title">${escapeHtml(title)}${download.vin ? `: ${escapeHtml(download.vin)}` : ''}</div>
                <div class="background-status-message">${escapeHtml(download.message || 'Загрузка выполняется в фоне.')}</div>
            `;

            if (state === 'running') {
                if (activeStorageKey) {
                    localStorage.setItem(activeStorageKey, '1');
                }
                startPolling();
            } else {
                if (activeStorageKey) {
                    localStorage.removeItem(activeStorageKey);
                }
                stopPolling();
            }
        };

        const hideStatus = () => {
            if (! statusBox) {
                return;
            }

            statusBox.classList.remove('is-visible', 'is-done', 'is-failed');
            statusBox.innerHTML = '';

            if (activeStorageKey) {
                localStorage.removeItem(activeStorageKey);
            }

            stopPolling();
        };

        const poll = async () => {
            try {
                const response = await fetch(endpoint, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (! response.ok) {
                    return;
                }

                const payload = await response.json();
                const download = latestDownload(payload.downloads || []);

                if (download) {
                    render(download);
                } else {
                    hideStatus();
                }
            } catch (error) {
                console.error(error);
            }
        };

        if (! currentDonorCarId) {
            localStorage.removeItem('officialCatalogDownloadActive');

            return;
        }

        function startPolling() {
            if (pollTimer) {
                return;
            }

            pollTimer = window.setInterval(poll, 4000);
        }

        function stopPolling() {
            if (! pollTimer) {
                return;
            }

            window.clearInterval(pollTimer);
            pollTimer = null;
        }

        window.officialDownloadStatus = {
            showRunning(message, donorCarId = currentDonorCarId) {
                render({
                    state: 'running',
                    donor_car_id: donorCarId,
                    message: message || 'Выкачка официального каталога запущена в фоне.',
                });
            },
            showDownload(download) {
                render(download);
            },
            pollNow: poll,
            startPolling,
        };

        if (activeStorageKey && localStorage.getItem(activeStorageKey) === '1') {
            window.officialDownloadStatus.showRunning('Проверяю статус выкачки официального каталога.');
            poll();
        } else {
            poll();
        }
    })();
</script>
<script>
    (() => {
        const paymentFieldPairs = [
            ['payment_type', 'received_amount'],
            ['payment_method', 'amount'],
        ];

        const setupPaymentPartsForm = (form, typeField, amountField) => {
            if (form.dataset.paymentPartsReady === '1') return;
            if (form.matches('[data-customer-order-payment-form]')) return;

            const firstSelect = form.querySelector(`select[name="payments[0][${typeField}]"]`);
            const firstInput = form.querySelector(`input[name="payments[0][${amountField}]"]`);
            if (!firstSelect || !firstInput) return;

            const typeLabel = firstSelect.closest('label') || firstSelect.parentElement;
            const amountLabel = firstInput.closest('label') || firstInput.parentElement;
            if (!typeLabel || !amountLabel) return;

            form.dataset.paymentPartsReady = '1';

            const rows = document.createElement('div');
            rows.style.display = 'grid';
            rows.style.gap = '8px';
            rows.dataset.paymentPartsRows = '1';

            const buildRow = (select, input, removable = false) => {
                const row = document.createElement('div');
                row.style.display = 'grid';
                row.style.gridTemplateColumns = 'minmax(130px, 1fr) minmax(120px, 1fr) auto';
                row.style.gap = '8px';
                row.style.alignItems = 'end';
                row.dataset.paymentPartRow = '1';
                row.append(select.closest('label') || select, input.closest('label') || input);

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-secondary btn-small';
                button.textContent = removable ? '\u00d7' : '+';
                button.setAttribute('aria-label', removable ? 'Удалить часть оплаты' : 'Добавить часть оплаты');
                button.addEventListener('click', () => removable ? row.remove() : addRow());
                row.append(button);

                return row;
            };

            const addRow = () => {
                const index = rows.querySelectorAll('[data-payment-part-row]').length;
                const selectLabel = typeLabel.cloneNode(true);
                const amountLabelClone = amountLabel.cloneNode(true);
                const select = selectLabel.querySelector('select');
                const input = amountLabelClone.querySelector('input');
                select.name = `payments[${index}][${typeField}]`;
                input.name = `payments[${index}][${amountField}]`;
                input.value = '';
                rows.append(buildRow(select, input, true));
            };

            typeLabel.before(rows);
            rows.append(buildRow(firstSelect, firstInput));
        };

        const initPaymentPartsForms = () => {
            document.querySelectorAll('form').forEach((form) => {
                paymentFieldPairs.forEach(([typeField, amountField]) => setupPaymentPartsForm(form, typeField, amountField));
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPaymentPartsForms);
        } else {
            initPaymentPartsForms();
        }
    })();
</script>
</body>
</html>
