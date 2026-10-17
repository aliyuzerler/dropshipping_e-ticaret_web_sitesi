<?php

declare(strict_types=1);

/**
 * Test bootstrap: autoloader + config (hata işleyicileri KAYITLI DEĞİL —
 * istisnalar TestRunner tarafından yakalanır).
 * Tam yol: tests/bootstrap.php
 */

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Core/Autoloader.php';
\Autoloader::register();
require_once BASE_PATH . '/app/Core/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');
date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'UTC'));

// HTTP front controller her istekte Session::start() yapar; test süreci de aynı
// davranışı taklit etsin (session_regenerate_id gibi oturum korumaları ancak
// AKTİF oturumda doğrulanabilir). bootstrap hiçbir çıktı üretmeden çalışır.
if (session_status() === PHP_SESSION_NONE) {
    \App\Core\Session::start();
}
