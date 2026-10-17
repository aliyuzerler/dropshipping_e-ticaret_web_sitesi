<?php

declare(strict_types=1);

/**
 * Test CLI: php tests/run.php
 * Tüm *Test.php dosyalarını toplar ve çalıştırır.
 * Tam yol: tests/run.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Support/Assert.php';
require __DIR__ . '/Support/TestRunner.php';

echo "XML E-Ticaret — Test Suite\n";
echo str_repeat('─', 58) . PHP_EOL;

$runner = new TestRunner();

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
// AŞAMA 6: güvenlik alt kümesi ayrı klasörde; kök testlerden SONRA yüklenir
// (config/session değişiklikleri başka testleri etkilemesin).
foreach ((glob(__DIR__ . '/Security/*Test.php') ?: []) as $file) {
    $files[] = $file;
}
foreach ($files as $file) {
    $register = require $file;
    if (is_callable($register)) {
        $register($runner);
    }
}

echo count($files) . " test dosyası yüklendi.\n";
$result = $runner->run();

exit($result['fail'] > 0 ? 1 : 0);
