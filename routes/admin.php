<?php

declare(strict_types=1);

/**
 * Admin rotaları — AdminAuth + CSRF + SecurityHeaders pipeline.
 * Tam yol: routes/admin.php
 */
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\FeedController;
use App\Controllers\Admin\FeedMappingController;
use App\Controllers\Admin\FeedPreviewController;
use App\Controllers\Admin\FeedSyncController;
use App\Controllers\Admin\ImportHistoryController;
use App\Controllers\Admin\OrderAdminController;
use App\Controllers\Admin\PriceRuleController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\ShipmentAdminController;
use App\Controllers\Admin\SupplierController;
use App\Controllers\Admin\SupplierOrderAdminController;
use App\Controllers\Admin\SupplierReportController;
use App\Controllers\Admin\UpdateLogController;
use App\Controllers\Admin\XmlImportController;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;

return static function (\App\Core\Router $r): void {
    // Giriş/çıkış (auth'suz; POST'lar CSRF'li)
    $r->get('/admin/login', [AuthController::class, 'showLogin'], [SecurityHeadersMiddleware::class]);
    $r->post('/admin/login', [AuthController::class, 'login'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->post('/admin/logout', [AuthController::class, 'logout'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);

    // Korumalı admin alanı
    $r->group('/admin', static function (\App\Core\Router $r): void {
        $r->get('/', [DashboardController::class, 'index']);

        // XML yükleme altyapısı (Aşama 1 uç — hızlı doğrulama için korunur)
        $r->get('/xml', [XmlImportController::class, 'index']);
        $r->post('/xml/upload', [XmlImportController::class, 'upload']);

        // Tedarikçiler (dropshipping kaynakları)
        $r->get('/suppliers', [SupplierController::class, 'index']);
        $r->post('/suppliers', [SupplierController::class, 'store']);
        $r->get('/suppliers/{id}/edit', [SupplierController::class, 'edit']);
        $r->post('/suppliers/{id}', [SupplierController::class, 'update']);
        $r->post('/suppliers/{id}/toggle', [SupplierController::class, 'toggle']);
        $r->post('/suppliers/{id}/delete', [SupplierController::class, 'destroy']);

        // XML kaynakları (feed) yönetimi
        $r->get('/feeds', [FeedController::class, 'index']);
        $r->get('/feeds/new', [FeedController::class, 'create']);
        $r->post('/feeds', [FeedController::class, 'store']);
        $r->get('/feeds/{id}/edit', [FeedController::class, 'edit']);
        $r->post('/feeds/{id}', [FeedController::class, 'update']);
        $r->post('/feeds/{id}/delete', [FeedController::class, 'destroy']);
        $r->post('/feeds/{id}/toggle', [FeedController::class, 'toggle']);
        $r->post('/feeds/{id}/file', [FeedController::class, 'uploadFile']);

        // Alan eşleştirme
        $r->get('/feeds/{id}/mapping', [FeedMappingController::class, 'show']);
        $r->post('/feeds/{id}/mapping', [FeedMappingController::class, 'save']);

        // Önizleme
        $r->get('/feeds/{id}/preview', [FeedPreviewController::class, 'show']);

        // Aktarım başlatma + ilerleme
        $r->post('/feeds/{id}/sync', [FeedSyncController::class, 'sync']);
        $r->get('/feeds/{id}/sync-status', [FeedSyncController::class, 'status']);

        // Aktarım geçmişi + hata raporları
        $r->get('/imports', [ImportHistoryController::class, 'index']);
        $r->get('/imports/{id}', [ImportHistoryController::class, 'show']);

        // Fiyat kuralları
        $r->get('/price-rules', [PriceRuleController::class, 'index']);
        $r->post('/price-rules', [PriceRuleController::class, 'store']);
        $r->post('/price-rules/{id}/toggle', [PriceRuleController::class, 'toggle']);
        $r->post('/price-rules/{id}/delete', [PriceRuleController::class, 'destroy']);

        // Son fiyat/stok güncellemeleri
        $r->get('/updates', [UpdateLogController::class, 'index']);

        // Aşama 3: mağaza ayarları (işletme bilgisi, kargo, eski stok kuralı, hukuki TASLAK metinler)
        $r->get('/settings', [SettingsController::class, 'index']);
        $r->post('/settings', [SettingsController::class, 'save']);

        // AŞAMA 4: sipariş yönetimi + iptal/iade
        $r->get('/orders', [OrderAdminController::class, 'index']);
        $r->get('/orders/{number}', [OrderAdminController::class, 'show']);
        $r->post('/orders/{id}/status', [OrderAdminController::class, 'updateStatus']);
        $r->post('/orders/{id}/cancel', [OrderAdminController::class, 'cancelOrder']);
        $r->post('/orders/refunds/{id}/approve', [OrderAdminController::class, 'approveRefund']);
        $r->post('/orders/refunds/{id}/reject', [OrderAdminController::class, 'rejectRefund']);

        // AŞAMA 5: tedarikçi siparişleri + kargo + raporlar
        $r->get('/supplier-orders', [SupplierOrderAdminController::class, 'index']);
        $r->get('/supplier-orders/{id}', [SupplierOrderAdminController::class, 'show']);
        $r->post('/supplier-orders/{id}/send', [SupplierOrderAdminController::class, 'send']);
        $r->post('/supplier-orders/{id}/mark-sent', [SupplierOrderAdminController::class, 'markSent']);
        $r->post('/supplier-orders/{id}/query', [SupplierOrderAdminController::class, 'queryStatus']);
        $r->post('/supplier-orders/{id}/retry', [SupplierOrderAdminController::class, 'retry']);

        $r->get('/shipments', [ShipmentAdminController::class, 'index']);
        $r->get('/shipments/new', [ShipmentAdminController::class, 'create']);
        $r->get('/shipments/{id}', [ShipmentAdminController::class, 'edit']);
        $r->post('/shipments/{id}', [ShipmentAdminController::class, 'update']);

        $r->get('/reports/suppliers', [SupplierReportController::class, 'index']);
    }, [SecurityHeadersMiddleware::class, AdminAuthMiddleware::class, CsrfMiddleware::class]);
};
