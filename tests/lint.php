<?php

declare(strict_types=1);

/**
 * Lint CLI: tüm PHP dosyalarında php -l.
 * Tam yol: tests/lint.php
 */

$dirs = ['app', 'config', 'database', 'routes', 'cron', 'tests', 'public'];
$files = [];
foreach ($dirs as $dir) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $dir));
    foreach ($rii as $file) {
        if (!$file->isDir() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$fail = 0;
$phpBin = PHP_BINARY ?: 'php';
foreach ($files as $file) {
    exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $fail++;
        echo implode("\n", $out) . PHP_EOL;
    }
    $out = [];
}

printf("Lint: %d dosya kontrol edildi, %d hata.\n", count($files), $fail);
exit($fail > 0 ? 1 : 0);
