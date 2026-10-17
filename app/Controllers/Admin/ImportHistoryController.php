<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\FeedRepository;
use App\Repositories\ImportLogRepository;
use App\Repositories\PriceHistoryRepository;

/**
 * Aktarım geçmişi + hata raporları.
 *
 *  GET /admin/imports        → liste (feed/durum filtreli)
 *  GET /admin/imports/{id}   → detay (satır hataları + fiyat/stok değişimleri)
 *
 * Tam yol: app/Controllers/Admin/ImportHistoryController.php
 */
final class ImportHistoryController
{
    public function index(Request $request): Response
    {
        $feedFilter = $request->input('feed');
        $statusFilter = $request->input('status');
        $page = max(1, is_numeric($request->input('page')) ? (int) $request->input('page') : 1);
        $perPage = 25;

        $repo = new ImportLogRepository();
        $logs = $repo->history(
            is_numeric($feedFilter) && (string) $feedFilter !== '' ? (int) $feedFilter : null,
            is_string($statusFilter) && $statusFilter !== '' ? $statusFilter : null,
            $perPage,
            ($page - 1) * $perPage
        );

        return View::render('admin/imports/index', [
            'title'        => 'Aktarım Geçmişi',
            'logs'         => $logs,
            'feeds'        => (new FeedRepository())->listWithSupplier(100),
            'filterFeed'   => is_numeric($feedFilter) && (string) $feedFilter !== '' ? (int) $feedFilter : '',
            'filterStatus' => is_string($statusFilter) ? $statusFilter : '',
            'page'         => $page,
            'perPage'      => $perPage,
            'message'      => get_flash('feed_message'),
            'error'        => get_flash('feed_error'),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $logId = (int) $id;
        $log = (new ImportLogRepository())->findWithNames($logId);
        if ($log === null) {
            flash('feed_error', 'Aktarım kaydı bulunamadı.');
            return redirect('/admin/imports');
        }

        return View::render('admin/imports/show', [
            'title'        => 'Aktarım Detayı #' . $logId,
            'log'          => $log,
            'changes'      => (new PriceHistoryRepository())->forImportLog($logId),
            'message'      => get_flash('feed_message'),
            'error'        => get_flash('feed_error'),
        ]);
    }
}
