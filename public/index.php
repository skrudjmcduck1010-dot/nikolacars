<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// HOSTiQ keeps the application inside the public directory; local Laravel
// checkouts keep it one level above. Use one root for every bootstrap path.
$applicationPath = is_file(__DIR__.'/laravel_app/bootstrap/app.php')
    ? __DIR__.'/laravel_app'
    : dirname(__DIR__);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $applicationPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $applicationPath.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $applicationPath.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
