# Склад запчастей — ТЗ для Codex

## Цель проекта

Создать веб‑приложение **«Склад запчастей»** для будущего размещения на домене `paperhelp.discount`.

Локальная разработка: **Laragon (Windows)**.

Будущее назначение проекта:

* учет запчастей на складе;
* приход и расход;
* поиск по артикулу, названию и совместимости;
* учет остатков;
* категории и бренды;
* история движений;
* минимальные остатки;
* загрузка изображений;
* базовая аналитика.

---

## Рекомендуемый стек

### Вариант 1 — практичный для вас сейчас

* **Laravel 12**
* **Blade**
* **MySQL** для локальной разработки в Laragon
* **Bootstrap 5** или простой кастомный CSS
* Авторизация через встроенные Laravel механизмы
* Хранение изображений в `storage/app/public`
* Production `storage/app/public` is append-only from application/deploy
  tooling: live images must never be deleted, reset, purged, replaced, or
  mirrored from local storage. Recovery may only add missing files or restore
  from a verified hosting backup/snapshot.

### Вариант 2 — если строить как WMS-систему глубже

* **Laravel / FastAPI / NestJS**
* **PostgreSQL** как основное ядро данных
* Web-интерфейс для офиса
* **Мобильный web/PWA** для склада: приемка, размещение, перемещение, подбор
* ETL-импорт из BAS на этапе миграции

### Рекомендация для этого проекта

Так как у вас локальная разработка будет в **Laragon**, для старта разумно делать **Laravel + MySQL**, но проектировать структуру так, чтобы потом без боли перейти на более «складскую» модель с ячейками, резервами, перемещениями и мобильным интерфейсом. В обсуждении прямо закладывались PWA, ячеечный учет, ETL из BAS и архитектура уровня складского ядра. fileciteturn1file1 fileciteturn1file2

---

## Основные сущности БД

### 1. users

* id
* name
* email
* password
* role (`admin`, `manager`, `storekeeper`, `picker`, `viewer`)
* is_active
* created_by
* updated_by
* timestamps

### 2. warehouses

* id
* name
* code
* type
* is_active
* timestamps

### 3. locations

* id
* warehouse_id
* zone
* row
* shelf
* cell
* full_code — например `WH-ZN-RK-SH-CELL`
* is_active
* timestamps

### 4. categories

* id
* name
* slug
* description
* is_active
* sort_order
* timestamps

### 5. brands

* id
* name
* slug
* description
* is_active
* timestamps

### 6. donor_cars

* id
* vin
* model
* generation
* year
* color
* notes
* timestamps

### 7. counterparties

* id
* type (`supplier`, `customer`, `both`)
* name
* phone
* email
* address
* notes
* is_active
* timestamps

### 8. products

* id
* sku — внутренний артикул
* external_sku — OEM / артикул поставщика
* name
* slug
* category_id
* brand_id
* donor_car_id
* description
* compatibility
* model
* generation
* side (`left`, `right`, `front`, `rear`)
* condition_grade (`A`, `B`, `C`)
* testing_status (`tested`, `not_tested`)
* unit (`pcs`, `set`, `pair`)
* purchase_price
* selling_price
* currency
* barcode
* qr_code
* main_image
* images_json
* weight
* notes
* is_active
* created_by
* updated_by
* timestamps

### 9. stock_items

* id
* product_id
* warehouse_id
* location_id
* quantity
* reserved_quantity
* available_quantity
* condition_grade
* testing_status
* received_at
* created_by
* updated_by
* timestamps

### 10. movements

* id
* product_id
* stock_item_id
* from_location_id
* to_location_id
* user_id
* counterparty_id
* type (`intake`, `move`, `reserve`, `unreserve`, `sale`, `writeoff`, `adjustment`)
* quantity
* reason
* document_number
* comment
* created_by
* updated_by
* created_at

### 11. reservations

* id
* product_id
* stock_item_id
* customer_order_id
* quantity
* status (`active`, `released`, `fulfilled`, `cancelled`)
* expires_at
* comment
* created_by
* updated_by
* timestamps

### 12. purchase_orders

* id
* counterparty_id
* number
* status (`draft`, `ordered`, `received`, `cancelled`)
* total_amount
* currency
* expected_date
* comment
* created_by
* updated_by
* timestamps

### 13. purchase_order_items

* id
* purchase_order_id
* product_id
* qty
* price
* received_qty
* timestamps

### 14. customer_orders

* id
* counterparty_id
* number
* status (`new`, `processing`, `reserved`, `completed`, `cancelled`)
* source
* comment
* total_amount
* created_by
* updated_by
* timestamps

### 15. customer_order_items

* id
* customer_order_id
* product_id
* qty
* price
* timestamps

### 16. audit_logs

* id
* user_id
* entity_type
* entity_id
* action
* old_values_json
* new_values_json
* created_at

---

## Основной функционал MVP

### Публичная часть

* главная страница проекта;
* каталог запчастей;
* страница категории;
* страница отдельной запчасти;
* поиск по названию, артикулу, OEM и совместимости;
* фильтр по категории, бренду, состоянию и наличию;
* форма заявки на запчасть;
* отображение нескольких фото;
* блок `в наличии / резерв / нет / под заказ`.

### Офисный web-интерфейс

* дашборд;
* справочники: склады, ячейки, бренды, категории, доноры, контрагенты;
* карточка товара;
* остатки по складам и ячейкам;
* поиск и фильтрация;
* заказы клиентов;
* закупки;
* отчеты по остаткам, резервам, расхождениям.

### Складской мобильный web/PWA

* приемка;
* сканирование QR/штрихкода;
* размещение в ячейку;
* перемещение между ячейками;
* резерв;
* подбор и отгрузка;
* cycle count по зонам.

### Обязательные бизнес-правила

1. Остаток считается из движений и/или складских записей по ячейкам, а не только одним полем на товаре. fileciteturn1file3
2. Любая единица товара должна иметь локацию. fileciteturn1file3
3.    ,   . fileciteturn1file3
4. Продажа возможна только из доступного остатка. fileciteturn1file3
5. Списание — только с причиной и пользователем. fileciteturn1file3
6. У детали должны быть состояние, совместимость и фото. fileciteturn1file3
7. Движения не удаляются; только корректирующие операции. fileciteturn1file3
8. Все даты хранить в UTC, отображать локально. fileciteturn1file3
9. У записей должны быть `created_by/updated_by`. fileciteturn1file3
10. История изменений обязательна. fileciteturn1file3
11. Перемещения должны поддерживать сканер или быстрый мобильный режим. fileciteturn1file1
12.      . fileciteturn1file1

---

## Логика склада

### Приемка

* создается операция `intake`;
* товар получает QR/штрихкод;
* товар размещается в конкретную ячейку;
* при необходимости привязывается к VIN донора. fileciteturn1file1 fileciteturn1file3

### Перемещение

* создается операция `move`;
* обязательно указывается откуда и куда перемещен товар;
* желательно делать через сканер или мобильный интерфейс. fileciteturn1file1

### Резерв

* создается запись в `reservations` и движение `reserve`;
* резерв уменьшает доступный остаток;
* товар нельзя продать дважды. fileciteturn1file1 fileciteturn1file3

### Продажа / отгрузка

* создается операция `sale`;
* продажа доступна только из незарезервированного остатка. fileciteturn1file3

### Списание

* создается операция `writeoff`;
* причина обязательна. fileciteturn1file3

### Корректировка

* создается `adjustment`;
* старые движения не удаляются, только добавляются корректирующие. fileciteturn1file3

### Инвентаризация

* поддержать инвентаризацию по зонам (`cycle count`), а не только полную ревизию раз в год. fileciteturn1file1

### Нормализация данных перед миграцией

Нужно предусмотреть чистку BAS-данных до импорта:

* дубли похожих позиций;
* разные написания одной и той же модели;
* одинаковый артикул при разном состоянии;
* неполные данные по складам/ячейкам.

Нужны словари:

* модели Tesla и поколения;
* стороны;
* состояние;
* типы складов и зон. fileciteturn1file1

---

## Страницы проекта

### Публичные

* `/`
* `/catalog`
* `/catalog/{category}`
* `/part/{slug}`
* `/search`
* `/request-part`

### Офисная часть

* `/admin`
* `/admin/products`
* `/admin/categories`
* `/admin/brands`
* `/admin/warehouses`
* `/admin/locations`
* `/admin/donor-cars`
* `/admin/counterparties`
* `/admin/stock`
* `/admin/movements`
* `/admin/reservations`
* `/admin/purchase-orders`
* `/admin/customer-orders`
* `/admin/import`
* `/admin/audit-logs`
* `/admin/reconciliation`

### Складской мобильный интерфейс

* `/m/intake`
* `/m/move`
* `/m/reserve`
* `/m/pick`
* `/m/count`

---

## Что должен сделать Codex

1. Создать новый Laravel-проект под Laragon.
2. Настроить `.env` под локальную БД.
3. Создать миграции, модели, сидеры и фабрики.
4. Сделать авторизацию и роли.
5. Реализовать справочники складов, ячеек, категорий, брендов, доноров и контрагентов.
6. Реализовать карточки товаров и складские остатки по ячейкам.
7. Реализовать операции: приемка, перемещение, резерв, снятие резерва, продажа, списание, корректировка.
8. Реализовать журнал движений и аудит.
9. Добавить QR/штрихкод для товара.
10. Сделать web-интерфейс для офиса и упрощенный mobile/PWA-интерфейс для склада.
11. Добавить импорт из BAS через CSV/Excel.
12. Сделать сверку остатков и резервов на этапе пилота.
13. Подготовить проект к поэтапной миграции и двойному контуру на 2–4 недели. fileciteturn1file1

---

## Что важно сразу предусмотреть

* ячеечный учет вместо одной общей цифры на товар;
* возможность вести один и тот же товар в разных ячейках и состояниях;
* отдельный резерв и доступный остаток;
* обязательную историю движений;
* UTC для хранения времени;
* `created_by/updated_by`;
* QR/штрихкоды;
* мобильный интерфейс для кладовщика;
* импорт из BAS без остановки работы;
* пилотную зону и ежедневную сверку;
* нормализацию старых данных и словари значений. fileciteturn1file1 fileciteturn1file3

---

## Миграция из BAS

### Что выгружать первым этапом

1. номенклатура
2. остатки по складам / ячейкам
3. резервы

### Порядок миграции

1. BAS остается рабочей системой
2. новая система запускается на ограниченной товарной группе
3. операции в пилотной зоне ведутся в обеих системах
4. ежедневно сверяются остаток, резерв, ячейка и расхождения
5. после стабильной точности можно расширять на весь склад

### Первые справочники для нормализации

* модели Tesla и поколения
* стороны
* состояния
* типы складов и зон

---

## Стартовый prompt для Codex

Создай Laravel-проект для склада запчастей Tesla с локальной разработкой в Laragon и будущим размещением на paperhelp.discount. Проект должен поддерживать ячеечный учет запчастей после разборки донорских автомобилей, хранить данные по складам, зонам, рядам, полкам и ячейкам, а также вести историю движений без удаления старых операций. Используй Laravel, Blade и MySQL для старта, но структуру сделай как WMS-light.

Нужны сущности: users, warehouses, locations, categories, brands, donor_cars, counterparties, products, stock_items, movements, reservations, purchase_orders, purchase_order_items, customer_orders, customer_order_items, audit_logs.

У товара должны быть: sku, external_sku, name, slug, category_id, brand_id, donor_car_id, description, compatibility, model, generation, side, condition_grade, testing_status, purchase_price, selling_price, currency, barcode, qr_code, main_image, images_json, is_active, created_by, updated_by.

 : intake, move, reserve, unreserve, sale, writeoff, adjustment.    ,   .      .      .  QR/,  , audit log, created_by/updated_by,    UTC.

Сделай два интерфейса: офисный web и упрощенный mobile/PWA для склада. Добавь импорт данных из BAS через CSV/Excel, чтобы можно было загрузить номенклатуру, остатки по ячейкам и резервы. Подготовь проект к пилотному запуску на одной товарной группе и ежедневной сверке остатков, резервов и местоположений.

Подготовь миграции, модели, контроллеры, form request валидацию, роуты, сидеры, базовые Blade-шаблоны и инструкцию по запуску в Laragon.

## Предлагаемая структура MVP по этапам

### Этап 1

* авторизация
* категории
* бренды
* товары
* остатки
* движения склада

### Этап 2

* поставщики
* закупки
* резервы
* заявки клиентов
* импорт / экспорт

### Этап 3

* роли и права
* аудит
* SEO-каталог
* мультиязычность
* API

---

## Локальный запуск в Laragon

* проект в папке: `C:\laragon\www\sklad-zapchastey`
* создать БД: `sklad_zapchastey`
* настроить `.env`
* выполнить:

  * `composer install`
  * `php artisan key:generate`
  * `php artisan migrate --seed`
  * `php artisan storage:link`

---

## Вторая очередь

* импорт CSV / Excel;
* поставщики и закупки;
* заказы клиентов;
* резервирование товара;
* API;
* мультиязычность;
* роли и права доступа;
* отчеты по продажам и остаткам.
