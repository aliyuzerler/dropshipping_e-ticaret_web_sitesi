<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\FeedRepository;
use App\Services\Import\ImportPreviewService;

/**
 * Aktarım önizlemesi (veritabanına yazmadan eşleme sonucunu gösterir).
 *
 *  GET /admin/feeds/{id}/preview?limit=10 → son yüklenen dosyayı önizle
 *
 * Tam yol: app/Controllers/Admin/FeedPreviewController.php
 */
final class FeedPreviewController
{
    public function show(Request $request, string $id): Response
    {
        $feedId = (int) $id;
        $feed = (new FeedRepository())->find($feedId);
        if ($feed === null) {
            flash('feed_error', 'Kaynak bulunamadı.');
            return redirect('/admin/feeds');
        }

        $relative = (string) ($feed['last_file_path'] ?? '');
        $path = $relative !== '' ? base_path('storage/' . ltrim($relative, '/')) : '';

        $hasFile = $path !== '' && is_file($path);
        $preview = null;

        if ($hasFile) {
            $limitParam = $request->input('limit');
            $limit = is_numeric($limitParam) ? max(1, min(100, (int) $limitParam)) : null;
            try {
                $preview = (new ImportPreviewService())->preview($feed, $path, $limit);
            } catch (\Throwable $e) {
                flash('feed_error', 'Önizleme başarısız: ' . $e->getMessage());
            }
        } else {
            flash('feed_error', 'Bu kaynak için henüz XML dosyası yüklenmemiş. Eşleme ekranından dosya yükleyin.');
        }

        return View::render('admin/feeds/preview', [
            'title'    => 'Aktarım Önizlemesi: ' . $feed['name'],
            'feed'     => $feed,
            'preview'  => $preview,
            'hasFile'  => $hasFile,
            'message'  => get_flash('feed_message'),
            'error'    => get_flash('feed_error'),
        ]);
    }
}
