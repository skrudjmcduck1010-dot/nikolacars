<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['uk','ru'] as $locale) {
    $html = view('services.tesla-service',['locale'=>$locale])->render();
    $prefix = $locale==='ru' ? '/ru' : '';
    foreach ([substr_count($html,'<h1 ')===1,
        str_contains($html,$locale==='ru' ? 'Диагностика, ремонт и обслуживание Tesla в Киеве' : 'Діагностика, ремонт та обслуговування Tesla у Києві'),
        str_contains($html,'href="'.$prefix.'/services/tesla-computer-diagnostics/"'),
        str_contains($html,'href="'.$prefix.'/services/tesla-battery-repair/"'),
        str_contains($html,'href="'.$prefix.'/contacts/"'),
        str_contains($html,'action="'.route('lead.callback').'"'),
        str_contains($html,'https://www.tesla.com/support/vehicle-maintenance'),
        !str_contains($html,'80 тыс. км'), !str_contains($html,'80 тисяч км'),
        !str_contains($html,'высоковольтная батарея потребует')
    ] as $assertion) { if(!$assertion) throw new RuntimeException('Service regression '.$locale); }
    $targeted=view('services.targeted.tesla-computer-diagnostics',['locale'=>$locale])->render();
    if(str_contains($targeted,$locale==='ru'?'Выберите услугу для вашей Tesla':'Оберіть послугу для вашої Tesla')) throw new RuntimeException('Main-only block leaked into targeted page');
    echo "PASS $locale: service content, localized links, callback form, maintenance corrections and targeted isolation\n";
}
