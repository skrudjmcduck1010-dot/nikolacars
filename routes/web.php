<?php

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CashbookLabelController;
use App\Http\Controllers\Admin\CashTransactionController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CounterpartyController;
use App\Http\Controllers\Admin\CustomerOrderController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeletedPartController;
use App\Http\Controllers\Admin\DonorCarController;
use App\Http\Controllers\Admin\ErrorController;
use App\Http\Controllers\Admin\ExchangeRateController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\MonthlyReportController;
use App\Http\Controllers\Admin\MovementController;
use App\Http\Controllers\Admin\NameMarkerController;
use App\Http\Controllers\Admin\NikolaCarsSaleController;
use App\Http\Controllers\Admin\PartCatalogController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\StockActionController;
use App\Http\Controllers\Admin\StockItemController;
use App\Http\Controllers\Admin\StoEmployeeController;
use App\Http\Controllers\Admin\StoWorkOrderController;
use App\Http\Controllers\Admin\UserAccessController;
use App\Http\Controllers\Admin\ValeraCashTransactionController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\PromFeedController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/prom/donor-products.yml', [PromFeedController::class, 'donorProducts'])
    ->name('prom.donor-products.feed');
Route::get('/prom/nikolacars-products.yml', [PromFeedController::class, 'nikolaCarsProducts'])
    ->name('prom.nikolacars-products.feed');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::prefix('admin')->name('admin.')->middleware(['admin.log'])->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('activity-logs', [AdminActivityLogController::class, 'index'])
            ->middleware('permission:activity_logs.view')
            ->name('activity-logs.index');
        Route::get('activity-logs/tesla-official', [AdminActivityLogController::class, 'teslaOfficial'])
            ->middleware('permission:activity_logs.view')
            ->name('activity-logs.tesla-official');
        Route::get('activity-logs/tesla-official/status', [AdminActivityLogController::class, 'teslaOfficialStatus'])
            ->middleware('permission:activity_logs.view')
            ->name('activity-logs.tesla-official.status');
        Route::get('users', [UserAccessController::class, 'index'])
            ->middleware('permission:admin.users.manage')
            ->name('users.index');
        Route::patch('users/{user}', [UserAccessController::class, 'update'])
            ->middleware('permission:admin.users.manage')
            ->name('users.update');
        Route::get('name-markers', [NameMarkerController::class, 'index'])
            ->middleware('permission:admin.users.manage')
            ->name('name-markers.index');
        Route::redirect('dictionary', 'name-markers')
            ->middleware('permission:admin.users.manage')
            ->name('dictionary.index');
        Route::get('errors', ErrorController::class)
            ->middleware('permission:admin.users.manage')
            ->name('errors.index');
        Route::patch('name-markers/name-pairs', [NameMarkerController::class, 'updateNamePair'])
            ->middleware('permission:admin.users.manage')
            ->name('name-markers.name-pairs.update');
        Route::post('name-markers/language-markers', [NameMarkerController::class, 'storeLanguageMarker'])
            ->middleware('permission:admin.users.manage')
            ->name('name-markers.language-markers.store');
        Route::patch('name-markers/language-markers/{marker}/rotate', [NameMarkerController::class, 'rotateLanguageMarker'])
            ->middleware('permission:admin.users.manage')
            ->name('name-markers.language-markers.rotate');
        Route::delete('name-markers/language-markers/{marker}', [NameMarkerController::class, 'destroyLanguageMarker'])
            ->middleware('permission:admin.users.manage')
            ->name('name-markers.language-markers.destroy');

        Route::resource('warehouses', WarehouseController::class)
            ->middleware('permission:warehouses.manage');
        Route::resource('locations', LocationController::class)
            ->middleware('permission:locations.manage');
        Route::middleware('permission:mobile_parts.manage')->group(function (): void {
            Route::get('mobile/parts', [DonorCarController::class, 'mobileParts'])->name('mobile.parts.index');
            Route::get('mobile/parts/search', [DonorCarController::class, 'partSuggestions'])->name('mobile.parts.search');
            Route::get('mobile/donor-cars/{donorCar}/parts', [DonorCarController::class, 'mobileDonorParts'])->name('mobile.donor-cars.parts.show');
            Route::get('mobile/donor-cars/{donorCar}/parts/search', [DonorCarController::class, 'mobileProductSuggestions'])->name('mobile.donor-cars.products.search');
            Route::get('mobile/donor-cars/{donorCar}/parts/create', [DonorCarController::class, 'mobileCreateProduct'])->name('mobile.donor-cars.products.create');
            Route::post('mobile/donor-cars/{donorCar}/parts', [DonorCarController::class, 'mobileStoreProduct'])->name('mobile.donor-cars.products.store');
            Route::get('mobile/donor-cars/{donorCar}/parts/{product}', [DonorCarController::class, 'mobileMissingProduct']);
            Route::get('mobile/donor-cars/{donorCar}/parts/{product}/edit', [DonorCarController::class, 'mobileEditProduct'])->name('mobile.donor-cars.products.edit');
            Route::patch('mobile/donor-cars/{donorCar}/parts/{product}', [DonorCarController::class, 'mobileUpdateProduct'])->name('mobile.donor-cars.products.update');
            Route::post('mobile/donor-cars/{donorCar}/parts/{product}/photos', [DonorCarController::class, 'mobileStoreProductPhoto'])->name('mobile.donor-cars.products.photos.store');
            Route::delete('mobile/donor-cars/{donorCar}/parts/{product}/photos', [DonorCarController::class, 'mobileDestroyProductPhoto'])->name('mobile.donor-cars.products.photos.destroy');
            Route::patch('mobile/donor-cars/{donorCar}/parts/{product}/photos/order', [DonorCarController::class, 'mobileUpdateProductPhotoOrder'])->name('mobile.donor-cars.products.photos.order');
            Route::patch('mobile/donor-cars/{donorCar}/parts/{product}/damage-status', [DonorCarController::class, 'mobileUpdateProductDamageStatus'])->name('mobile.donor-cars.products.damage-status.update');
        });
        Route::get('stock-items/search', [StockActionController::class, 'stockItemOptions'])
            ->middleware('permission:stock_actions.manage')
            ->name('stock-items.search');
        Route::resource('stock-items', StockItemController::class)
            ->middleware('permission:stock_items.manage')
            ->parameters(['stock-items' => 'stockItem']);
        Route::resource('purchases', PurchaseController::class)
            ->only(['index', 'create', 'store', 'show', 'destroy'])
            ->middleware('permission:purchases.manage');
        Route::resource('movements', MovementController::class)
            ->only(['index', 'show'])
            ->middleware('permission:movements.view');
        Route::middleware('permission:reservations.manage')->group(function (): void {
            Route::get('reservations/products/search', [ReservationController::class, 'productOptions'])->name('reservations.products.search');
            Route::get('reservations/stock-items/search', [ReservationController::class, 'stockItemOptions'])->name('reservations.stock-items.search');
            Route::resource('reservations', ReservationController::class);
        });
        Route::middleware('permission:stock_actions.manage')->group(function (): void {
            Route::get('actions/products/search', [StockActionController::class, 'productOptions'])->name('actions.products.search');
            Route::get('actions/{type}', [StockActionController::class, 'create'])->name('actions.create');
            Route::post('actions', [StockActionController::class, 'store'])->name('actions.store');
        });

        Route::middleware('permission:donor_cars.manage')->group(function (): void {
            Route::get('donor-cars/parts/search', [DonorCarController::class, 'partSuggestions'])->name('donor-cars.parts.search');
            Route::post('donor-cars/{donorCar}/photos', [DonorCarController::class, 'storePhotos'])->name('donor-cars.photos.store');
            Route::delete('donor-cars/{donorCar}/photos', [DonorCarController::class, 'destroyPhoto'])->name('donor-cars.photos.destroy');
            Route::get('donor-cars/{donorCar}/products/table', [DonorCarController::class, 'productsTable'])->name('donor-cars.products.table');
            Route::get('donor-cars/{donorCar}/sales/table', [DonorCarController::class, 'partSalesTable'])->name('donor-cars.sales.table');
            Route::post('donor-cars/{donorCar}/products', [DonorCarController::class, 'storeProduct'])->name('donor-cars.products.store');
            Route::get('donor-cars/{donorCar}/products/{product}/photo-preview/{index}', [DonorCarController::class, 'productPhotoPreview'])
                ->whereNumber('index')
                ->name('donor-cars.products.photo-preview');
            Route::patch('donor-cars/{donorCar}/products/{product}/photos/rotate', [DonorCarController::class, 'rotateProductPhoto'])
                ->name('donor-cars.products.photos.rotate');
            Route::get('donor-cars/{donorCar}/small-parts', [DonorCarController::class, 'smallParts'])->name('donor-cars.small-parts.index');
            Route::post('donor-cars/{donorCar}/products/{product}/small-part', [DonorCarController::class, 'markProductAsSmallPart'])->name('donor-cars.products.small-part.store');
            Route::delete('donor-cars/{donorCar}/products/{product}/small-part', [DonorCarController::class, 'unmarkProductAsSmallPart'])->name('donor-cars.products.small-part.destroy');
            Route::patch('donor-cars/{donorCar}/products/{product}/name', [DonorCarController::class, 'updateProductName'])->name('donor-cars.products.name.update');
            Route::patch('donor-cars/{donorCar}/products/{product}/official-fields', [DonorCarController::class, 'updateOfficialProductFields'])->name('donor-cars.products.official-fields.update');
            Route::delete('donor-cars/{donorCar}/products/{product}', [DonorCarController::class, 'destroyProduct'])->name('donor-cars.products.destroy');
            Route::post('donor-cars/{donorCar}/products/generate/preview', [DonorCarController::class, 'previewGeneratedProducts'])->name('donor-cars.products.generate.preview');
            Route::post('donor-cars/{donorCar}/products/generate', [DonorCarController::class, 'generateProducts'])->name('donor-cars.products.generate');
            Route::get('donor-cars/official-downloads/status', [DonorCarController::class, 'officialDownloadStatuses'])->name('donor-cars.official-downloads.status');
            Route::patch('donor-cars/{donorCar}/paint-code', [DonorCarController::class, 'updatePaintCode'])->name('donor-cars.paint-code.update');
            Route::post('donor-cars/{donorCar}/products/download-official', [DonorCarController::class, 'downloadOfficialProducts'])->name('donor-cars.products.download-official');
            Route::resource('donor-cars', DonorCarController::class)->parameters(['donor-cars' => 'donorCar']);
        });

        Route::middleware('permission:counterparties.manage')->group(function (): void {
            Route::post('counterparties/{counterparty}/vehicles', [CounterpartyController::class, 'storeVehicle'])->name('counterparties.vehicles.store');
            Route::delete('counterparties/{counterparty}/vehicles/primary', [CounterpartyController::class, 'destroyPrimaryVehicle'])->name('counterparties.vehicles.primary.destroy');
            Route::delete('counterparties/{counterparty}/vehicles/{vehicle}', [CounterpartyController::class, 'destroyVehicle'])->name('counterparties.vehicles.destroy');
            Route::resource('counterparties', CounterpartyController::class)->except(['destroy']);
        });

        Route::post('categories/sync-tcars', [CategoryController::class, 'syncTcars'])
            ->middleware('permission:categories.manage')
            ->name('categories.sync-tcars');
        Route::resource('categories', CategoryController::class)
            ->middleware('permission:categories.manage');
        Route::resource('brands', BrandController::class)
            ->middleware('permission:brands.manage');
        Route::get('competitor-refresh/{source}/status', [PartCatalogController::class, 'competitorRefreshStatus'])
            ->middleware('permission:competitor_refresh.manage')
            ->name('part-catalog.source-competitor-refresh.status');
        Route::post('competitor-refresh/{source}', [PartCatalogController::class, 'startCompetitorRefresh'])
            ->middleware('permission:competitor_refresh.manage')
            ->name('part-catalog.source-competitor-refresh.start');
        Route::get('nikolacars-sales', [NikolaCarsSaleController::class, 'index'])
            ->middleware('permission:nikolacars_sales.view')
            ->name('nikolacars-sales.index');
        Route::patch('nikolacars-sales/{partSale}/cancel-manual', [NikolaCarsSaleController::class, 'cancelManualSoldBeforeJune'])
            ->middleware('permission:nikolacars_catalog.manage')
            ->name('nikolacars-sales.cancel-manual');
        Route::middleware('permission:customer_orders.manage')->group(function (): void {
            Route::get('customer-orders/clients/search', [CustomerOrderController::class, 'clientSearch'])->name('customer-orders.clients.search');
            Route::get('customer-orders/nova-poshta/cities', [CustomerOrderController::class, 'novaPoshtaCities'])->name('customer-orders.nova-poshta.cities');
            Route::get('customer-orders/nova-poshta/warehouses', [CustomerOrderController::class, 'novaPoshtaWarehouses'])->name('customer-orders.nova-poshta.warehouses');
            Route::patch('customer-orders/{customerOrder}/delivery-method', [CustomerOrderController::class, 'updateDeliveryMethod'])->name('customer-orders.delivery-method.update');
            Route::patch('customer-orders/{customerOrder}/note', [CustomerOrderController::class, 'updateNote'])->name('customer-orders.note.update');
            Route::patch('customer-orders/{customerOrder}/status', [CustomerOrderController::class, 'updateStatus'])->name('customer-orders.status.update');
            Route::post('customer-orders/{customerOrder}/nova-poshta/tracking-number', [CustomerOrderController::class, 'storeNovaPoshtaTrackingNumber'])->name('customer-orders.nova-poshta.tracking-number.store');
            Route::patch('customer-orders/{customerOrder}/nova-poshta/tracking-number', [CustomerOrderController::class, 'updateNovaPoshtaTrackingNumber'])->name('customer-orders.nova-poshta.tracking-number.update');
            Route::get('customer-orders/{customerOrder}/nova-poshta/label', [CustomerOrderController::class, 'printNovaPoshtaLabel'])->name('customer-orders.nova-poshta.label');
            Route::post('customer-orders/{customerOrder}/nova-poshta/sync-status', [CustomerOrderController::class, 'syncNovaPoshtaStatus'])->name('customer-orders.nova-poshta.sync-status');
            Route::post('customer-orders/{customerOrder}/recreate', [CustomerOrderController::class, 'recreate'])->name('customer-orders.recreate');
            Route::post('customer-orders/{customerOrder}/prepayment', [CustomerOrderController::class, 'storePrepayment'])->name('customer-orders.prepayment.store');
            Route::delete('customer-orders/{customerOrder}/prepayment/{historyEvent}', [CustomerOrderController::class, 'destroyPrepaymentEntry'])->name('customer-orders.prepayment-entry.destroy');
            Route::delete('customer-orders/{customerOrder}/prepayment', [CustomerOrderController::class, 'destroyPrepayment'])->name('customer-orders.prepayment.destroy');
            Route::post('customer-orders/{customerOrder}/payment', [CustomerOrderController::class, 'confirmPayment'])->name('customer-orders.payment.confirm');
            Route::get('customer-orders/{customerOrder}/items/catalog-search', [CustomerOrderController::class, 'catalogItemSearch'])->name('customer-orders.items.catalog-search');
            Route::post('customer-orders/{customerOrder}/items', [CustomerOrderController::class, 'storeItem'])->name('customer-orders.items.store');
            Route::patch('customer-orders/{customerOrder}/items/{customerOrderItem}', [CustomerOrderController::class, 'updateItem'])->name('customer-orders.items.update');
            Route::delete('customer-orders/{customerOrder}/items/{customerOrderItem}', [CustomerOrderController::class, 'destroyItem'])->name('customer-orders.items.destroy');
            Route::resource('customer-orders', CustomerOrderController::class)->only(['index', 'store', 'show']);
        });
        Route::get('deleted-parts', [DeletedPartController::class, 'index'])
            ->middleware('permission:products.manage')
            ->name('deleted-parts.index');
        Route::get('deleted-parts/{deletedPart}', [DeletedPartController::class, 'show'])
            ->middleware('permission:products.manage')
            ->name('deleted-parts.show');
        Route::post('deleted-parts/{deletedPart}/restore', [DeletedPartController::class, 'restore'])
            ->middleware('permission:products.manage')
            ->name('deleted-parts.restore');

        foreach (config('catalog_sources.sources', []) as $catalog) {
            $path = (string) $catalog['path'];
            $name = (string) $catalog['route_name'];
            $permission = 'permission:'.(string) $catalog['permission'];

            Route::get($path.'/search', [PartCatalogController::class, 'search'])
                ->middleware($permission)
                ->name($name.'.search');

            if ($catalog['has_legacy_refresh_routes'] ?? false) {
                Route::get($path.'/competitor-refresh/status', [PartCatalogController::class, 'tcarserviceCompetitorRefreshStatus'])
                    ->middleware('permission:competitor_refresh.manage')
                    ->name($name.'.competitor-refresh.status');
                Route::post($path.'/competitor-refresh', [PartCatalogController::class, 'startTcarserviceCompetitorRefresh'])
                    ->middleware('permission:competitor_refresh.manage')
                    ->name($name.'.competitor-refresh.start');
            }

            Route::get($path, [PartCatalogController::class, 'index'])
                ->middleware($permission)
                ->name($name.'.index');

            Route::get($path.'/catalog-export', [PartCatalogController::class, 'competitorCatalogExport'])
                ->middleware($permission)
                ->name($name.'.catalog-export');

            if ($catalog['has_nikolacars_routes'] ?? false) {
                Route::get($path.'/prom-export', [PartCatalogController::class, 'nikolaCarsPromExport'])
                    ->middleware($permission)
                    ->name($name.'.prom-export');
                Route::get($path.'/categories/search', [PartCatalogController::class, 'searchNikolaCarsCategories'])
                    ->middleware($permission)
                    ->name($name.'.categories.search');
                Route::get($path.'/items/name-suggestions', [PartCatalogController::class, 'searchNikolaCarsItemNameSuggestions'])
                    ->middleware($permission)
                    ->name($name.'.items.name-suggestions');
                Route::post($path.'/items', [PartCatalogController::class, 'storeNikolaCarsItem'])
                    ->middleware($permission)
                    ->name($name.'.store');
            }

            Route::get($path.'/items/{partCatalogItem}', [PartCatalogController::class, 'show'])
                ->middleware($permission)
                ->name($name.'.show');

            if ($catalog['has_update_route'] ?? false) {
                Route::patch($path.'/items/{partCatalogItem}', [PartCatalogController::class, 'updateTeslaCatalogItem'])
                    ->middleware($permission)
                    ->name($name.'.update');
            }

            if ($catalog['has_nikolacars_routes'] ?? false) {
                Route::patch($path.'/items/{partCatalogItem}', [PartCatalogController::class, 'updateNikolaCarsItem'])
                    ->middleware($permission)
                    ->name($name.'.update');
                Route::patch($path.'/items/{partCatalogItem}/sold', [PartCatalogController::class, 'markNikolaCarsItemSold'])
                    ->middleware($permission)
                    ->name($name.'.sold');
                Route::patch($path.'/items/{partCatalogItem}/category', [PartCatalogController::class, 'updateNikolaCarsItemCategory'])
                    ->middleware($permission)
                    ->name($name.'.category.update');
                Route::post($path.'/items/{partCatalogItem}/photos', [PartCatalogController::class, 'storeNikolaCarsItemPhotos'])
                    ->middleware($permission)
                    ->name($name.'.photos.store');
                Route::delete($path.'/items/{partCatalogItem}/photos', [PartCatalogController::class, 'destroyNikolaCarsItemPhoto'])
                    ->middleware($permission)
                    ->name($name.'.photos.destroy');
                Route::delete($path.'/items/{partCatalogItem}', [PartCatalogController::class, 'destroyNikolaCarsItem'])
                    ->middleware($permission)
                    ->name($name.'.destroy');
            }

            if (($catalog['has_category_route'] ?? true) !== false) {
                Route::get($path.'/{catalogPath}', [PartCatalogController::class, 'index'])
                    ->middleware($permission)
                    ->where('catalogPath', '.*')
                    ->name($name.'.category');
            }
        }

        Route::get('products/search', [ProductController::class, 'search'])->middleware('permission:products.manage')->name('products.search');
        Route::post('products/{product}/photos', [ProductController::class, 'storePhotos'])->middleware('permission:products.manage')->name('products.photos.store');
        Route::delete('products/{product}/photos', [ProductController::class, 'destroyPhoto'])->middleware('permission:products.manage')->name('products.photos.destroy');
        Route::patch('products/{product}/photos/order', [ProductController::class, 'updatePhotoOrder'])->middleware('permission:products.manage')->name('products.photos.order');
        Route::patch('products/{product}/photos/rotate', [ProductController::class, 'rotatePhoto'])->middleware('permission:products.manage')->name('products.photos.rotate');
        Route::patch('products/{product}/catalog-name', [ProductController::class, 'updateCatalogName'])->middleware('permission:products.manage')->name('products.catalog-name.update');
        Route::resource('products', ProductController::class)
            ->middleware('permission:products.manage');

        Route::get('reports/monthly', [MonthlyReportController::class, 'index'])
            ->middleware('permission:reports.view')
            ->name('reports.monthly');
        Route::resource('cashbook-labels', CashbookLabelController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->middleware('permission:cashbook.manage')
            ->parameters(['cashbook-labels' => 'cashbookLabel']);
        Route::get('exchange-rates', [ExchangeRateController::class, 'index'])
            ->middleware('permission:exchange_rates.view')
            ->name('exchange-rates.index');
        Route::resource('cashbook', CashTransactionController::class)
            ->middleware('permission:cashbook.manage')
            ->parameters(['cashbook' => 'cashbook']);

        Route::middleware('permission:valera_cashbook.manage')->group(function (): void {
            Route::get('valera-cashbook', [ValeraCashTransactionController::class, 'index'])->name('valera-cashbook.index');
            Route::post('valera-cashbook', [ValeraCashTransactionController::class, 'store'])->name('valera-cashbook.store');
            Route::post('valera-cashbook/transfers/{transfer}/confirm', [ValeraCashTransactionController::class, 'confirmTransfer'])->name('valera-cashbook.transfers.confirm');
            Route::post('valera-cashbook/transfers/{transfer}/cancel', [ValeraCashTransactionController::class, 'cancelTransfer'])->name('valera-cashbook.transfers.cancel');
            Route::delete('valera-cashbook/{valeraCashbook}', [ValeraCashTransactionController::class, 'destroy'])->name('valera-cashbook.destroy');
        });

        Route::middleware('permission:sto_work_orders.manage')->group(function (): void {
            Route::get('sto-work-orders/clients/search', [StoWorkOrderController::class, 'clientSearch'])->name('sto-work-orders.clients.search');
            Route::get('sto-work-orders/parts/search', [StoWorkOrderController::class, 'partSearch'])->name('sto-work-orders.parts.search');
            Route::get('sto-work-orders/works/search', [StoWorkOrderController::class, 'workSearch'])->name('sto-work-orders.works.search');
            Route::post('sto-work-orders/{stoWorkOrder}/parts', [StoWorkOrderController::class, 'storePart'])->name('sto-work-orders.parts.store');
            Route::delete('sto-work-orders/{stoWorkOrder}/parts/{part}', [StoWorkOrderController::class, 'destroyPart'])->name('sto-work-orders.parts.destroy');
            Route::post('sto-work-orders/{stoWorkOrder}/works', [StoWorkOrderController::class, 'storeWork'])->name('sto-work-orders.works.store');
            Route::delete('sto-work-orders/{stoWorkOrder}/works/{work}', [StoWorkOrderController::class, 'destroyWork'])->name('sto-work-orders.works.destroy');
            Route::post('sto-work-orders/{stoWorkOrder}/payment', [StoWorkOrderController::class, 'confirmPayment'])->name('sto-work-orders.payment.confirm');
            Route::post('sto-work-orders/{stoWorkOrder}/archive', [StoWorkOrderController::class, 'archive'])->name('sto-work-orders.archive');
            Route::post('sto-work-orders/{stoWorkOrder}/status', [StoWorkOrderController::class, 'updateStatus'])->name('sto-work-orders.status.update');
            Route::post('sto-work-orders/{stoWorkOrder}/sto-comment', [StoWorkOrderController::class, 'updateStoComment'])->name('sto-work-orders.sto-comment.update');
            Route::get('sto-work-orders/{stoWorkOrder}/print', [StoWorkOrderController::class, 'printOrder'])->name('sto-work-orders.print');
            Route::resource('sto-work-orders', StoWorkOrderController::class)->only(['index', 'create', 'store', 'show'])->parameters(['sto-work-orders' => 'stoWorkOrder']);
        });

        Route::patch('sto-employees/{stoEmployee}/access-password', [StoEmployeeController::class, 'updateAccessPassword'])
            ->middleware('permission:sto_employees.manage')
            ->name('sto-employees.access-password.update');
        Route::patch('sto-employees/{stoEmployee}/access-login', [StoEmployeeController::class, 'updateAccessLogin'])
            ->middleware('permission:sto_employees.manage')
            ->name('sto-employees.access-login.update');
        Route::resource('sto-employees', StoEmployeeController::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
            ->middleware('permission:sto_employees.manage')
            ->parameters(['sto-employees' => 'stoEmployee']);
    });
});
