<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\DonorCar;
use App\Models\Location;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@sklad.test'],
            [
                'name' => 'NikolaCars',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $warehouse = Warehouse::query()->firstOrCreate(
            ['name' => 'Main Warehouse'],
            ['is_active' => true]
        );

        $location = Location::query()->firstOrCreate(
            ['full_code' => 'MAIN-A-01-01-01'],
            [
                'warehouse_id' => $warehouse->id,
                'floor' => 'floor_1',
                'zone' => 'A',
                'row' => '01',
                'shelf' => '01',
                'cell' => '01',
                'is_active' => true,
            ]
        );

        $categories = [
            ['name' => 'Кузовные детали', 'slug' => 'body-parts', 'description' => 'Бамперы, крылья, двери, капот, крышка багажника и наружные панели Tesla.', 'sort_order' => 10],
            ['name' => 'Оптика и освещение', 'slug' => 'lighting-optics', 'description' => 'Фары, фонари, противотуманные фары, повторители и блоки освещения Tesla.', 'sort_order' => 20],
            ['name' => 'Салон и интерьер', 'slug' => 'interior-parts', 'description' => 'Сиденья, панели салона, обшивки, ремни безопасности, консоли и элементы отделки Tesla.', 'sort_order' => 30],
            ['name' => 'Электроника и блоки управления', 'slug' => 'electronics-control-modules', 'description' => 'ЭБУ, MCU, дисплеи, датчики, камеры, проводка и электронные модули Tesla.', 'sort_order' => 40],
            ['name' => 'Батарея и высоковольтная система', 'slug' => 'battery-high-voltage', 'description' => 'Высоковольтная батарея, модули батареи, контакторы, кабели и компоненты HV-системы Tesla.', 'sort_order' => 50],
            ['name' => 'Зарядная система', 'slug' => 'charging-system', 'description' => 'Зарядные порты, onboard charger, разъемы, кабели и компоненты зарядки Tesla.', 'sort_order' => 60],
            ['name' => 'Электродвигатели и инверторы', 'slug' => 'drive-units-inverters', 'description' => 'Drive unit, электродвигатели, инверторы, редукторы и связанные компоненты Tesla.', 'sort_order' => 70],
            ['name' => '', 'slug' => 'suspension', 'description' => ', , , ,     Tesla.', 'sort_order' => 80],
            ['name' => 'Тормозная система', 'slug' => 'brake-system', 'description' => 'Суппорты, диски, колодки, тормозные магистрали, ABS и компоненты тормозной системы Tesla.', 'sort_order' => 90],
            ['name' => ' ', 'slug' => 'steering-system', 'description' => ' ,  ,      Tesla.', 'sort_order' => 100],
            ['name' => '   ', 'slug' => 'cooling-hvac', 'description' => ', , ,  ,    HVAC Tesla.', 'sort_order' => 110],
            ['name' => 'Колеса и шины', 'slug' => 'wheels-tires', 'description' => 'Диски, шины, датчики давления, колпаки и крепеж колес Tesla.', 'sort_order' => 120],
            ['name' => 'Стекла и зеркала', 'slug' => 'glass-mirrors', 'description' => 'Лобовые, боковые и задние стекла, зеркала, камеры зеркал и механизмы Tesla.', 'sort_order' => 130],
            ['name' => 'Двери, замки и ручки', 'slug' => 'doors-locks-handles', 'description' => 'Дверные механизмы, замки, ручки, доводчики, петли и актуаторы Tesla.', 'sort_order' => 140],
            ['name' => 'Багажник и фрунк', 'slug' => 'trunk-frunk', 'description' => 'Компоненты багажника и переднего багажника, уплотнители, замки, приводы и облицовки Tesla.', 'sort_order' => 150],
            ['name' => 'Безопасность и подушки SRS', 'slug' => 'safety-srs', 'description' => 'Подушки безопасности, ремни, пиропатроны, датчики удара и блоки SRS Tesla.', 'sort_order' => 160],
            ['name' => 'Автопилот и камеры', 'slug' => 'autopilot-cameras', 'description' => 'Камеры, радары, ультразвуковые датчики, автопилотные блоки и крепления Tesla.', 'sort_order' => 170],
            ['name' => 'Крепеж и мелкие детали', 'slug' => 'fasteners-small-parts', 'description' => 'Клипсы, болты, кронштейны, заглушки, уплотнители и мелкий крепеж Tesla.', 'sort_order' => 180],
        ];

        foreach ($categories as $categoryData) {
            Category::query()->updateOrCreate(
                ['slug' => $categoryData['slug']],
                $categoryData + ['is_active' => true]
            );
        }

        $category = Category::query()->where('slug', 'body-parts')->firstOrFail();

        $brand = Brand::query()->firstOrCreate(
            ['slug' => 'tesla'],
            ['name' => 'Tesla', 'description' => 'Primary vehicle brand', 'is_active' => true]
        );

        $donorCar = DonorCar::query()->firstOrCreate(
            ['vin' => '5YJ3E1EA0LF000001'],
            ['brand' => 'Tesla', 'model' => 'Model 3', 'year' => 2024, 'color' => 'White']
        );

        $counterparty = Counterparty::query()->firstOrCreate(
            ['name' => 'Main Supplier'],
            ['type' => 'supplier', 'phone' => '+380000000000', 'is_active' => true]
        );

        $product = Product::query()->firstOrCreate(
            ['sku' => 'TES-BUMPER-FR-001'],
            [
                'external_sku' => 'OEM-001',
                'name' => 'Front Bumper',
                'slug' => 'front-bumper',
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'donor_car_id' => $donorCar->id,
                'description' => 'Starter seeded product for Phase 1.',
                'compatibility' => 'Model 3 Highland',
                'model' => 'Model 3',
                'generation' => 'Highland',
                'side' => 'front',
                'testing_status' => 'not_tested',
                'unit' => 'pcs',
                'purchase_price' => 100,
                'selling_price' => 160,
                'currency' => 'USD',
                'barcode' => 'TES-BUMPER-FR-001',
                'qr_code' => 'TES-BUMPER-FR-001',
                'is_active' => true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        $stockItem = StockItem::query()->firstOrCreate(
            [
                'product_id' => $product->id,
                'location_id' => $location->id,
                'testing_status' => 'not_tested',
            ],
            [
                'warehouse_id' => $warehouse->id,
                'quantity' => 5,
                'reserved_quantity' => 1,
                'available_quantity' => 4,
                'received_at' => now(),
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        Reservation::query()->firstOrCreate(
            ['product_id' => $product->id, 'stock_item_id' => $stockItem->id, 'customer_order_id' => 'ORD-1001'],
            [
                'quantity' => 1,
                'status' => 'active',
                'comment' => 'Seeded active reservation.',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        $stockItem->syncAvailableQuantity();
        $stockItem->save();

        $product->movements()->firstOrCreate(
            [
                'type' => 'intake',
                'quantity' => 5,
                'to_location_id' => $location->id,
            ],
            [
                'stock_item_id' => $stockItem->id,
                'user_id' => $admin->id,
                'counterparty_id' => $counterparty->id,
                'document_number' => 'INTAKE-SEED-1',
                'comment' => 'Initial seeded intake',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
                'created_at' => now()->utc(),
            ]
        );

        $product->movements()->firstOrCreate(
            [
                'type' => 'reserve',
                'quantity' => 1,
                'to_location_id' => $location->id,
            ],
            [
                'stock_item_id' => $stockItem->id,
                'user_id' => $admin->id,
                'document_number' => 'ORD-1001',
                'comment' => 'Initial seeded reservation',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
                'created_at' => now()->utc(),
            ]
        );
    }
}
