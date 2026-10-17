<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\ShipmentRepository;
use App\Repositories\SupplierOrderRepository;
use App\Services\Auth\AdminAuthService;
use App\Services\Shipping\ShipmentService;
use App\Services\Shipping\ShipmentStatus;
use Throwable;

/**
 * AŞAMA 5 — Kargo yönetimi ekranları:
 *  - /admin/shipments            → tüm gönderiler (takip ekranı)
 *  - /admin/shipments/new?so=ID  → tedarikçi siparişi için gönderi formu
 *  - /admin/shipments/{id}       → düzenleme (kargo firması, takip no, durum, tarihler)
 *
 * Gerçek kargo API'si yoksa takip numarası MANUEL girilir (varsayılan davranış).
 *
 * Tam yol: app/Controllers/Admin/ShipmentAdminController.php
 */
final class ShipmentAdminController
{
    private const PER_PAGE = 15;

    private ShipmentRepository $shipments;

    private SupplierOrderRepository $supplierOrders;

    private ShipmentService $service;

    private AdminAuthService $auth;

    public function __construct(
        ?ShipmentRepository $shipments = null,
        ?SupplierOrderRepository $supplierOrders = null,
        ?ShipmentService $service = null,
        ?AdminAuthService $auth = null
    ) {
        $this->shipments      = $shipments ?? new ShipmentRepository();
        $this->supplierOrders = $supplierOrders ?? new SupplierOrderRepository();
        $this->service        = $service ?? new ShipmentService();
        $this->auth           = $auth ?? new AdminAuthService();
    }

    public function index(Request $request): Response
    {
        $status = (string) ($request->input('status') ?? 'all');
        $q      = trim((string) ($request->input('q') ?? ''));
        $page   = max(1, (int) ($request->input('page') ?? 1));

        $result   = $this->shipments->adminList($status, $q, $page, self::PER_PAGE);
        $lastPage = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        return View::render('admin/shipments/index', [
            'title'    => 'Kargo Takip',
            'rows'     => $result['rows'],
            'total'    => $result['total'],
            'statuses' => ShipmentStatus::LABELS,
            'filter'   => $status,
            'q'        => $q,
            'page'     => min($page, $lastPage),
            'lastPage' => $lastPage,
        ], 'layouts/base');
    }

    /** Yeni gönderi formu (tedarikçi siparişinden açılır). */
    public function create(Request $request): Response
    {
        $soId = (int) ($request->input('so') ?? 0);
        $so   = $this->supplierOrders->find($soId);
        if ($so === null) {
            abort(404, 'Tedarikçi siparişi bulunamadı.');
        }
        $shipment = $this->shipments->forSupplierOrderOrCreate($soId);
        return redirect('/admin/shipments/' . (int) $shipment['id']);
    }

    public function edit(Request $request, string $id): Response
    {
        $shipment = $this->shipments->find((int) $id);
        if ($shipment === null) {
            abort(404, 'Gönderi bulunamadı.');
        }
        $so = $this->supplierOrders->find((int) $shipment['supplier_order_id']);
        return View::render('admin/shipments/edit', [
            'title'     => 'Kargo Düzenle — ' . (string) ($shipment['order_number'] ?? ('#' . $shipment['id'])),
            'shipment'  => $shipment,
            'so'        => $so,
            'statuses'  => ShipmentStatus::LABELS,
            'allowed'   => ShipmentStatus::ALL,
            'adapterManual' => \App\Services\Shipping\ShippingAdapterFactory::resolve() instanceof \App\Services\Shipping\ManualShippingAdapter,
        ], 'layouts/base');
    }

    public function update(Request $request, string $id): Response
    {
        $shipment = $this->shipments->find((int) $id);
        if ($shipment === null) {
            abort(404, 'Gönderi bulunamadı.');
        }
        try {
            $adminId = (int) $this->auth->currentAdminId();
            $this->service->updateForSupplierOrder(
                (int) $shipment['supplier_order_id'],
                [
                    'status'          => (string) $request->input('status', ''),
                    'carrier'         => (string) $request->input('carrier', ''),
                    'tracking_number' => (string) $request->input('tracking_number', ''),
                    'tracking_url'    => (string) $request->input('tracking_url', ''),
                    'note'            => (string) $request->input('note', ''),
                ],
                $adminId,
                $request->ip()
            );
            flash('success', 'Kargo bilgisi güncellendi ve sipariş durumu eşitlendi.');
        } catch (ValidationException $e) {
            flash('error', $e->firstError());
        } catch (Throwable $e) {
            Logger::error('app', 'admin.shipment_update_failed', ['id' => $id, 'reason' => $e->getMessage()]);
            flash('error', 'Kargo güncellenemedi.');
        }
        return redirect('/admin/shipments/' . (int) $id);
    }
}
