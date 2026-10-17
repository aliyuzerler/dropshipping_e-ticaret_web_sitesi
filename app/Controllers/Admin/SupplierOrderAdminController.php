<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\NotificationRepository;
use App\Repositories\SupplierOrderRepository;
use App\Repositories\SupplierRepository;
use App\Services\Auth\AdminAuthService;
use App\Services\Supplier\SupplierOrderService;
use App\Services\Supplier\SupplierOrderStatus;
use Throwable;

/**
 * AŞAMA 5 — Tedarikçi siparişi yönetimi:
 *  - "Tedarikçiye iletilecek siparişler" (pending), API gönderim hataları (error),
 *    "Manuel işlem bekleyen" görevler, durum belirsiz (unknown) kayıtlar
 *  - Detay: kalemler + teslimat snapshot + durum zamanları + aksiyonlar
 *    (gönder, manuel gönderildi işaretle, durum sorgula, yeniden kuyruğa al)
 *
 * Tam yol: app/Controllers/Admin/SupplierOrderAdminController.php
 */
final class SupplierOrderAdminController
{
    private const PER_PAGE = 15;

    private SupplierOrderRepository $supplierOrders;

    private SupplierRepository $suppliers;

    private SupplierOrderService $service;

    private AdminAuthService $auth;

    private NotificationRepository $notifications;

    public function __construct(
        ?SupplierOrderRepository $supplierOrders = null,
        ?SupplierRepository $suppliers = null,
        ?SupplierOrderService $service = null,
        ?AdminAuthService $auth = null,
        ?NotificationRepository $notifications = null
    ) {
        $this->supplierOrders = $supplierOrders ?? new SupplierOrderRepository();
        $this->suppliers      = $suppliers ?? new SupplierRepository();
        $this->service        = $service ?? new SupplierOrderService();
        $this->auth           = $auth ?? new AdminAuthService();
        $this->notifications  = $notifications ?? new NotificationRepository();
    }

    public function index(Request $request): Response
    {
        $filter     = (string) ($request->input('status') ?? 'all');
        $supplierId = (int) ($request->input('supplier') ?? 0);
        $q          = trim((string) ($request->input('q') ?? ''));
        $page       = max(1, (int) ($request->input('page') ?? 1));

        $result   = $this->supplierOrders->adminList($filter, $supplierId > 0 ? $supplierId : null, $q, $page, self::PER_PAGE);
        $lastPage = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        $counts = $this->supplierOrders->statusCounts();
        $manualTaskCount = 0;
        try {
            $stmt = \App\Core\Database::pdo()->prepare(
                'SELECT COUNT(*) FROM supplier_orders so
                 JOIN suppliers s ON s.id = so.supplier_id
                 JOIN orders o ON o.id = so.order_id
                 WHERE so.status = "pending" AND s.integration_type <> "api"
                   AND o.status IN ("paid", "processing", "supplier_pending")'
            );
            $stmt->execute();
            $manualTaskCount = (int) $stmt->fetchColumn();
        } catch (Throwable) {
            $manualTaskCount = 0;
        }

        return View::render('admin/supplier_orders/index', [
            'title'        => 'Tedarikçi Siparişleri',
            'rows'         => $result['rows'],
            'total'        => $result['total'],
            'counts'       => $counts,
            'statuses'     => SupplierOrderStatus::LABELS,
            'filter'       => $filter,
            'suppliers'    => $this->suppliers->allSuppliers(),
            'supplierId'   => $supplierId,
            'q'            => $q,
            'page'         => min($page, $lastPage),
            'lastPage'     => $lastPage,
            'manualTasks'  => $manualTaskCount,
            'notifications' => $this->notifications->countsByStatus(),
        ], 'layouts/base');
    }

    public function show(Request $request, string $id): Response
    {
        $so = $this->supplierOrders->find((int) $id);
        if ($so === null) {
            abort(404, 'Tedarikçi siparişi bulunamadı.');
        }
        return View::render('admin/supplier_orders/show', [
            'title'       => 'Tedarikçi Siparişi ' . (string) $so['internal_reference'],
            'so'          => $so,
            'items'       => $this->supplierOrders->items((int) $so['id']),
            'delivery'    => $this->decodeSnapshot($so['delivery_snapshot'] ?? null),
            'orderStatuses' => \App\Services\Order\OrderStatus::LABELS,
            'soStatuses'  => SupplierOrderStatus::LABELS,
        ], 'layouts/base');
    }

    /** API tedarikçisine gönder (veya pending kaydı yeniden dener). */
    public function send(Request $request, string $id): Response
    {
        try {
            $adminId = (int) $this->auth->currentAdminId();
            $outcome = $this->service->processOne((int) $id, $adminId, $request->ip());
            flash('success', match ($outcome) {
                'sent'    => 'Tedarikçi siparişi gönderildi.',
                'unknown' => 'Zaman aşımı — durum belirsiz. Yeniden gönderilmedi; önce durum sorgusu yapın.',
                'errors'  => 'Gönderim başarısız — hata mesajı kaydedildi.',
                'manual'  => 'Manuel tedarikçi — sipariş panelde bekleyen görev olarak kaldı.',
                default   => 'Kayıt işlenmedi (durumu uygun değil).',
            });
        } catch (Throwable $e) {
            Logger::error('app', 'admin.supplier_send_failed', ['id' => $id, 'reason' => $e->getMessage()]);
            flash('error', 'Gönderim sırasında hata oluştu.');
        }
        return redirect('/admin/supplier-orders/' . (int) $id);
    }

    /** Manuel tedarikçi: "panelde ilettim" işaretleme. */
    public function markSent(Request $request, string $id): Response
    {
        try {
            $adminId = (int) $this->auth->currentAdminId();
            $this->service->markSentManually(
                (int) $id,
                trim((string) $request->input('external_reference', '')) ?: null,
                trim((string) $request->input('note', '')) ?: null,
                $adminId,
                $request->ip()
            );
            flash('success', 'Tedarikçi siparişi MANUEL gönderildi olarak işaretlendi.');
        } catch (ValidationException $e) {
            flash('error', $e->firstError());
        } catch (Throwable $e) {
            Logger::error('app', 'admin.supplier_manual_failed', ['id' => $id, 'reason' => $e->getMessage()]);
            flash('error', 'İşaretleme başarısız.');
        }
        return redirect('/admin/supplier-orders/' . (int) $id);
    }

    /** Durum sorgusu (unknown/sending için — yeniden gönderim YAPMAZ). */
    public function queryStatus(Request $request, string $id): Response
    {
        try {
            $adminId = (int) $this->auth->currentAdminId();
            $result  = $this->service->queryStatuses((int) $id, 1, $adminId, $request->ip());
            flash('success', 'Durum sorgusu tamamlandı: sorgulanan ' . $result['queried'] . ', onaylanan ' . $result['confirmed'] . ', kayıt yok ' . $result['not_found'] . ', hata ' . $result['failed'] . '.');
        } catch (Throwable $e) {
            Logger::error('app', 'admin.supplier_query_failed', ['id' => $id, 'reason' => $e->getMessage()]);
            flash('error', 'Durum sorgusu başarısız.');
        }
        return redirect('/admin/supplier-orders/' . (int) $id);
    }

    /** Hatalı/belirsiz kaydı yeniden gönderim kuyruğuna alır (admin bilinçli kararı). */
    public function retry(Request $request, string $id): Response
    {
        try {
            $adminId = (int) $this->auth->currentAdminId();
            $this->service->retry((int) $id, $adminId, $request->ip());
            flash('success', 'Kayıt yeniden gönderim kuyruğuna alındı (pending).');
        } catch (Throwable $e) {
            Logger::error('app', 'admin.supplier_retry_failed', ['id' => $id, 'reason' => $e->getMessage()]);
            flash('error', 'Kuyruğa alma başarısız.');
        }
        return redirect('/admin/supplier-orders/' . (int) $id);
    }

    /** @return array<string,mixed> */
    private function decodeSnapshot(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
