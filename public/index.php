<?php

declare(strict_types=1);

/**
 * FRONT CONTROLLER — tek giriş noktası.
 * Nginx/Apache tüm istekleri bu dosyaya yönlendirir.
 *
 * Tam yol: public/index.php
 */

define('BASE_PATH', dirname(__DIR__));

// 1) Composer autoload varsa kullan, yoksa fallback PSR-4 yükleyici
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    require BASE_PATH . '/app/Core/Autoloader.php';
    \Autoloader::register();
}

// helpers.php (composer files yoksa elle)
if (!function_exists('e')) {
    require BASE_PATH . '/app/Core/helpers.php';
}

// 2) Uygulamayı başlat (env, config, hata işleyicileri)
\App\Core\App::boot(BASE_PATH);

// 3) İstek
$request = \App\Core\Request::capture();

// 3.5) AŞAMA 6 — Taşıma güvenlik muhafızları (yalnız env ile açıldığında devrede:
//      APP_ALLOWED_HOSTS beyaz liste dışı hosta 400, APP_FORCE_HTTPS http→301).
//      Rota zincirinden bağımsız; oturum başlamadan önce uygulanır.
$guardResponse = \App\Middleware\TrustedHostMiddleware::inspect($request)
    ?? \App\Middleware\HttpsEnforcementMiddleware::inspect($request);
if ($guardResponse !== null) {
    $guardResponse->send();
    exit(0);
}

// 4) Güvenli oturum
\App\Core\Session::start();

// 5) Router
$router = new \App\Core\Router();
/** @var callable $registerWeb */
$registerWeb = require BASE_PATH . '/routes/web.php';
$registerWeb($router);
/** @var callable $registerAdmin */
$registerAdmin = require BASE_PATH . '/routes/admin.php';
$registerAdmin($router);

// 6) Dispatch — HttpException temiz yanıta dönüşür; diğerleri ErrorHandler'a düşer
try {
    $response = $router->dispatch($request);
    $response->send();
} catch (\App\Exceptions\HttpException $e) {
    \App\Core\ErrorHandler::handleThrowable($e);
}
