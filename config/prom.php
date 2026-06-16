<?php

return [
    'feed_token' => env('PROM_FEED_TOKEN'),
    'shop_name' => env('PROM_SHOP_NAME', config('app.name', 'Sklad zapchastey')),
    'company_name' => env('PROM_COMPANY_NAME', config('app.name', 'Sklad zapchastey')),
];
