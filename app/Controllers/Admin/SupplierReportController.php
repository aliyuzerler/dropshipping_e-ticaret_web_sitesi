<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\NotificationRepository;
use App\Repositories\SupplierOrderRepository;
use Throwable;

/**
 * AŞAMA 5 — Tedarikçi bazında sipariş raporları.
 *
 * Tam yol: app/Controllers/Admin/SupplierReportController.php
 */
final class SupplierReportController
{
    private SupplierOrderRepository $supplierOrders;

    private NotificationRepository $notifications;

    public function __construct(
        ?SupplierOrderRepository $supplierOrders = null,
        ?NotificationRepository $notifications = null
    ) {
        $this->supplierOrders = $supplierOrders ?? new SupplierOrderRepository();
        $this->notifications  = $notifications ?? new NotificationRepository();
    }

    public function index(Request $request): Response
    {
        $rows = [];
        try {
            $rows = $this->supplierOrders->supplierReport();
        } catch (Throwable) {
            $rows = [];
        }

        $notificationCounts = $this->notifications->countsByStatus();
        $recent = $this->notifications->recent(20);

        return View::render('admin/reports/suppliers', [
            'title'      => 'Tedarikçi Raporları',
            'rows'       => $rows,
            'notifCounts' => $notificationCounts,
            'recentNotifications' => $recent,
            'notifiedCount' => count($recent),
        ], 'layouts/base');
    }
}
