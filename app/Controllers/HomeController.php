<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;

/**
 * Açılış sayfası (proje durumunu gösteren vitrin).
 *
 * Tam yol: app/Controllers/HomeController.php
 */
final class HomeController
{
    public function index(Request $request): \App\Core\Response
    {
        $stats = [
            'suppliers'   => $this->safeCount('suppliers'),
            'products'    => $this->safeCount('products'),
            'import_logs' => $this->safeCount('xml_import_logs'),
        ];

        return View::render('home/index', [
            'title' => 'XML E-Ticaret — Aşama 1',
            'stats' => $stats,
            'env'   => \App\Core\App::environment(),
        ]);
    }

    private function safeCount(string $table): int
    {
        try {
            $allowed = ['suppliers', 'products', 'xml_import_logs'];
            if (!in_array($table, $allowed, true)) {
                return 0;
            }
            $stmt = \App\Core\Database::pdo()->query('SELECT COUNT(*) FROM ' . $table);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
