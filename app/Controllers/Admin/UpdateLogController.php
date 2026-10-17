<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\PriceHistoryRepository;

/**
 * Son fiyat ve stok güncellemeleri + kârlılık eşiği uyarıları.
 *
 *  GET /admin/updates?low=1
 *
 * Tam yol: app/Controllers/Admin/UpdateLogController.php
 */
final class UpdateLogController
{
    public function index(Request $request): Response
    {
        $onlyLow = $request->input('low') !== null;

        return View::render('admin/updates/index', [
            'title'         => 'Fiyat & Stok Güncellemeleri',
            'updates'       => (new PriceHistoryRepository())->recent(150, $onlyLow ? true : null),
            'onlyLowMargin' => $onlyLow,
            'message'       => get_flash('feed_message'),
            'error'         => get_flash('feed_error'),
        ]);
    }
}
