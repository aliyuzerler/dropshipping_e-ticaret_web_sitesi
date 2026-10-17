<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\FeedRepository;
use App\Repositories\ImportLogRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Import\FeedImportService;

/**
 * Aktarım başlatma + ilerleme.
 *
 *  POST /admin/feeds/{id}/sync           → senkronizasyonu şimdi başlat
 *  GET  /admin/feeds/{id}/sync-status    → JSON ilerleme (polling)
 *
 * Tam yol: app/Controllers/Admin/FeedSyncController.php
 */
final class FeedSyncController
{
    public function sync(Request $request, string $id): Response
    {
        $feedId = (int) $id;
        $feed = (new FeedRepository())->find($feedId);
        if ($feed === null) {
            flash('feed_error', 'Kaynak bulunamadı.');
            return redirect('/admin/feeds');
        }

        AuditLogger::log(
            action: 'feed.sync_started',
            adminUserId: Session::get('admin_id') !== null ? (int) Session::get('admin_id') : null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            entityType: 'xml_feed',
            entityId: $feedId,
            context: ['trigger' => 'manual']
        );

        $result = (new FeedImportService())->syncFeedById($feedId, 'manual');

        if (in_array($result['status'], ['success', 'partial'], true)) {
            flash('feed_message', $result['message']);
        } elseif ($result['status'] === 'locked') {
            flash('feed_error', $result['message']);
        } else {
            flash('feed_error', $result['message']);
        }

        // Detay ekranına yönlendir (log açıldıysa)
        if (($result['log_id'] ?? null) !== null) {
            return redirect('/admin/imports/' . (int) $result['log_id']);
        }
        return redirect('/admin/imports?feed=' . $feedId);
    }

    /** JSON ilerleme: aktarım sürerken sayaçlar polling ile okunur. */
    public function status(Request $request, string $id): Response
    {
        $feedId = (int) $id;
        $log = (new ImportLogRepository())->lastForFeed($feedId);

        if ($log === null) {
            return Response::json(['status' => 'idle']);
        }

        return Response::json([
            'log_id'        => (int) $log['id'],
            'status'        => (string) $log['status'],
            'total_rows'    => (int) $log['total_rows'],
            'created_rows'  => (int) $log['created_rows'],
            'updated_rows'  => (int) $log['updated_rows'],
            'skipped_rows'  => (int) $log['skipped_rows'],
            'error_rows'    => (int) $log['error_rows'],
            'duration_ms'   => $log['duration_ms'] !== null ? (int) $log['duration_ms'] : null,
            'error_message' => $log['error_message'],
        ]);
    }
}
