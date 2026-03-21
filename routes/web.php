<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LeadController;
/*
|--------------------------------------------------------------------------
| HOME — UA (default)
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    $locale = 'uk';
    $pathNoLocale = '/';

    $services = [
        [
            'slug'  => 'services/tesla-service',
            'title' => 'Сервіс Tesla',
            'desc'  => 'Діагностика, ремонт та технічне обслуговування'
        ],
        [
            'slug'  => 'services/restore-certificates',
            'title' => 'Відновлення сертифікатів',
            'desc'  => 'Офіційні рішення для функцій Tesla'
        ],
        [
            'slug'  => 'services/import-usa',
            'title' => 'Пригін із США',
            'desc'  => 'Підбір, доставка, розмитнення'
        ],
    ];

    return view('home', compact('locale','pathNoLocale','services'));
});

/*
|--------------------------------------------------------------------------
| HOME — RU
|--------------------------------------------------------------------------
*/
Route::get('/ru/', function () {
    $locale = 'ru';
    $pathNoLocale = '/';

    $services = [
        [
            'slug'  => 'services/tesla-service',
            'title' => 'Сервис Tesla',
            'desc'  => 'Диагностика, ремонт и техническое обслуживание'
        ],
        [
            'slug'  => 'services/restore-certificates',
            'title' => 'Восстановление сертификатов',
            'desc'  => 'Официальные решения для функций Tesla'
        ],
        [
            'slug'  => 'services/import-usa',
            'title' => 'Пригон из США',
            'desc'  => 'Подбор, доставка, растаможка'
        ],
    ];

    return view('home', compact('locale','pathNoLocale','services'));
});


Route::post('/lead/callback', [LeadController::class, 'callback'])->name('lead.callback');

$targetedServices = collect(config('targeted_services', []))->keyBy('slug');
$targetedServiceSlugPattern = $targetedServices
    ->keys()
    ->map(fn (string $slug) => preg_quote($slug, '/'))
    ->implode('|');

Route::get('/services/{slug}/', function (string $slug) use ($targetedServices) {
    $service = $targetedServices->get($slug);
    abort_unless($service, 404);
    $viewPath = 'services.targeted.' . $slug;
    abort_unless(view()->exists($viewPath), 404);

    return view($viewPath, [
        'pageTitle' => $service['name_uk'] . ' – NikolaCars',
        'metaDescription' => $service['name_uk'] . ' у Києві для Tesla Model 3, Tesla Model Y, Tesla Model X, Tesla Model S.',
    ]);
})->where('slug', $targetedServiceSlugPattern);

Route::get('/ru/services/{slug}/', function (string $slug) use ($targetedServices) {
    $service = $targetedServices->get($slug);
    abort_unless($service, 404);
    $viewPath = 'services.targeted.' . $slug;
    abort_unless(view()->exists($viewPath), 404);

    return view($viewPath, [
        'locale' => 'ru',
        'pageTitle' => $service['name_ru'] . ' – NikolaCars',
        'metaDescription' => $service['name_ru'] . ' в Киеве для Tesla Model 3, Tesla Model Y, Tesla Model X, Tesla Model S.',
    ]);
})->where('slug', $targetedServiceSlugPattern);
/*
|--------------------------------------------------------------------------
| TEMP 404 (пока нет других страниц)
|--------------------------------------------------------------------------
*/
use Illuminate\Support\Facades\Http;


Route::fallback(function () {
    abort(404);
});

Route::get('/services/tesla-service/', function () {
    return view('services.tesla-service', [
        'pageTitle' => 'Сервіс Tesla – NikolaCars',
        'metaDescription' => 'Діагностика, ремонт та технічне обслуговування Tesla у Києві. Запис на сервіс онлайн.'
    ]);
});

Route::get('/ru/services/tesla-service/', function () {
    return view('services.tesla-service', [
        'locale' => 'ru',
        'pageTitle' => 'Сервис Tesla – NikolaCars',
        'metaDescription' => 'Диагностика, ремонт и техническое обслуживание Tesla в Киеве. Онлайн-заявка.'
    ]);
});


Route::get('/services/tesla-electricmotor-repair', function () {
    return view('services.tesla-electricmotor-repair', [
        'pageTitle' => 'Ремонт електромотора Tesla Model S – NikolaCars',
        'metaDescription' => 'Ремонт електромотора Tesla Model S у Києві: діагностика, відновлення drive unit, ремонт інвертора та тестування під навантаженням.'
    ]);
});

Route::get('/ru/services/tesla-electricmotor-repair', function () {
    return view('services.tesla-electricmotor-repair', [
        'locale' => 'ru',
        'pageTitle' => 'Ремонт электромотора Tesla Model S – NikolaCars',
        'metaDescription' => 'Ремонт электромотора Tesla Model S в Киеве: диагностика, восстановление drive unit, ремонт инвертора и тестирование под нагрузкой.'
    ]);
});

Route::get('/services/tesla-battery-repair', function () {
    return view('services.tesla-battery-repair', [
        'pageTitle' => 'Ремонт батарей Tesla – NikolaCars',
        'metaDescription' => 'Ремонт батарей Tesla в Киеве: диагностика, восстановление модулей, балансировка и обслуживание высоковольтной батареи.'
    ]);
});

Route::get('/ru/services/tesla-battery-repair', function () {
    return view('services.tesla-battery-repair', [
        'locale' => 'ru',
        'pageTitle' => 'Ремонт батарей Tesla – NikolaCars',
        'metaDescription' => 'Ремонт батарей Tesla в Киеве: диагностика, восстановление модулей, балансировка и обслуживание высоковольтной батареи.'
    ]);
});

Route::get('/services/repair-tesla-door-handle/', function () {
    return view('services.repair-tesla-door-handle', [
        'pageTitle' => 'Заміна та ремонт ручки Tesla – NikolaCars',
        'metaDescription' => 'Заміна та ремонт ручки дверей Tesla у Києві: діагностика, відновлення механізму, калібрування та встановлення.'
    ]);
});

Route::get('/ru/services/repair-tesla-door-handle/', function () {
    return view('services.repair-tesla-door-handle', [
        'locale' => 'ru',
        'pageTitle' => 'Замена и ремонт ручки Tesla – NikolaCars',
        'metaDescription' => 'Замена и ремонт ручки дверей Tesla в Киеве: диагностика, восстановление механизма и калибровка.'
    ]);
});

Route::get('/services/tesla-subframe-repair/', function () {
    return view('services.tesla-subframe-repair', [
        'pageTitle' => 'Заміна та ремонт підрамника Tesla – NikolaCars',
        'metaDescription' => 'Заміна та ремонт підрамника Tesla у Києві: перевірка геометрії, відновлення кріплень, встановлення і контроль ходової.'
    ]);
});

Route::get('/ru/services/tesla-subframe-repair/', function () {
    return view('services.tesla-subframe-repair', [
        'locale' => 'ru',
        'pageTitle' => 'Замена и ремонт подрамника Tesla – NikolaCars',
        'metaDescription' => 'Замена и ремонт подрамника Tesla в Киеве: диагностика геометрии, восстановление креплений и безопасная установка.'
    ]);
});

Route::get('/services/vidnovlennya-sertyfikativ-tesla/', function () {
    return view('services.vidnovlennya-sertyfikativ-tesla', [
        'pageTitle' => 'Відновлення сертифікатів Tesla – NikolaCars',
        'metaDescription' => 'Офіційне відновлення сертифікатів Tesla. Повернення Tesla App, оновлень та повного функціоналу автомобіля.'
    ]);
});

Route::get('/ru/services/vidnovlennya-sertyfikativ-tesla/', function () {
    return view('services.vidnovlennya-sertyfikativ-tesla', [
        'locale' => 'ru',
        'pageTitle' => 'Восстановление сертификатов Tesla – NikolaCars',
        'metaDescription' => 'Официальное восстановление сертификатов Tesla. Возврат Tesla App, обновлений и полного функционала автомобиля.'
    ]);
});
// UA
Route::get('/services/firmware-auto/', function () {
    return view('services.firmware-auto', [
        'pageTitle' => 'Прошивка авто – NikolaCars',
        'metaDescription' => 'Прошивка та налаштування авто. Активація функцій, адаптація систем і підготовка до експлуатації в Україні.'
    ]);
});

// RU
Route::get('/ru/services/firmware-auto/', function () {
    return view('services.firmware-auto', [
        'locale' => 'ru',
        'pageTitle' => 'Прошивка авто – NikolaCars',
        'metaDescription' => 'Прошивка и настройка авто. Активация функций, адаптация систем и подготовка к эксплуатации.'
    ]);
});

// SERVICES
Route::get('/services/', fn() => view('services_index', [
  'pageTitle' => 'Послуги – NikolaCars',
  'metaDescription' => 'Послуги NikolaCars у Києві.'
]));
Route::get('/ru/services/', fn() => view('services_index', [
  'locale' => 'ru',
  'pageTitle' => 'Услуги – NikolaCars',
  'metaDescription' => 'Услуги NikolaCars в Киеве.'
]));

// TESTIMONIAL
Route::get('/testimonial/', fn() => view('testimonial', [
  'pageTitle' => 'Відгуки – NikolaCars',
  'metaDescription' => 'Відгуки клієнтів NikolaCars.'
]));
Route::get('/ru/testimonial/', fn() => view('testimonial', [
  'locale' => 'ru',
  'pageTitle' => 'Отзывы – NikolaCars',
  'metaDescription' => 'Отзывы клиентов NikolaCars.'
]));

// NEWS
Route::get('/news/', fn() => view('news_index', [
  'pageTitle' => 'Новини – NikolaCars',
  'metaDescription' => 'Новини NikolaCars.'
]));
Route::get('/ru/news/', fn() => view('news_index', [
  'locale' => 'ru',
  'pageTitle' => 'Новости – NikolaCars',
  'metaDescription' => 'Новости NikolaCars.'
]));

// CONTACTS
Route::get('/contacts/', fn() => view('contacts', [
  'pageTitle' => 'Контакти – NikolaCars',
  'metaDescription' => 'Контакти NikolaCars у Києві.'
]));
Route::get('/ru/contacts/', fn() => view('contacts', [
  'locale' => 'ru',
  'pageTitle' => 'Контакты – NikolaCars',
  'metaDescription' => 'Контакты NikolaCars в Киеве.'
]));

Route::view('/privacy-policy', 'privacy');
Route::view('/ru/privacy-policy', 'privacy');


Route::get('/services/prigon-tesla-usa/', function () {
    return view('services.prigon-usa', [
        'pageTitle' => 'Пригін Tesla із США – NikolaCars',
        'metaDescription' => 'Професійний пригін Tesla із США під ключ. Пошук, перевірка, доставка, розмитнення та реєстрація в Україні.'
    ]);
});

Route::get('/ru/services/prigon-tesla-usa/', function () {
    return view('services.prigon-usa', [
        'locale' => 'ru',
        'pageTitle' => 'Пригон Tesla из США – NikolaCars',
        'metaDescription' => 'Профессиональный пригон Tesla из США под ключ. Поиск, проверка, доставка, растаможка и регистрация.'
    ]);
});
