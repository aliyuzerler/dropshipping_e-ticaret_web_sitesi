<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Admin panel ana sayfa (Aşama 1: durum özeti).
 *
 * Tam yol: app/Controllers/Admin/DashboardController.php
 */
final class DashboardController
{
    public function index(Request $request): Response
    {
        return View::render('admin/dashboard', [
            'title'     => 'Yönetim Paneli',
            'adminName' => (string) Session::get('admin_email', ''),
            'counts'    => $this->counts(),
            'lastImports' => $this->lastImports(),
        ]);
    }

    /**
     * @return array<string,int>
     */
    private function counts(): array
    {
        try {
            $pdo = Database::pdo();
            $out = [];
            foreach (['suppliers', 'xml_feeds', 'products', 'categories', 'xml_import_logs'] as $table) {
                $stmt = $pdo->query('SELECT COUNT(*) FROM ' . $table);
                $out[$table] = $stmt === false ? 0 : (int) $stmt->fetchColumn();
            }
            return $out;
        } catch (\Throwable) {
            return ['suppliers' => 0, 'xml_feeds' => 0, 'products' => 0, 'categories' => 0, 'xml_import_logs' => 0];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function lastImports(): array
    {
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT id, file_name, source, status, total_rows, error_message, created_at
                 FROM xml_import_logs ORDER BY id DESC LIMIT 10'
            );
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll();
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }
}
