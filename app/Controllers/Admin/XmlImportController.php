<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Audit\AuditLogger;
use App\Services\Import\XmlStreamReader;
use App\Services\Import\XmlUploadManager;
use App\Validators\XmlUploadValidator;

/**
 * XML yükleme altyapısı (Aşama 1):
 *  - GET  /admin/xml  → yükleme formu + son yüklemeler
 *  - POST /admin/xml/upload → çok katmanlı doğrulama + güvenli saklama + akışlı sayım + audit log
 *
 * NOT: Bu uç, "güvenli yükleme altyapısı"nı uçtan uca çalıştırır.
 * Feed ↔ ürün eşlemesi (xml_field_mappings uygulaması) Faz 2'de devreye girer;
 * bu nedenle yükleme ürün OLUŞTURMAZ, doğrular/saklar/sayar/loglar.
 *
 * Tam yol: app/Controllers/Admin/XmlImportController.php
 */
final class XmlImportController
{
    public function index(Request $request): Response
    {
        return View::render('admin/xml', [
            'title'   => 'XML Yükleri',
            'message' => get_flash('xml_message'),
            'error'   => get_flash('xml_error'),
            'uploads' => $this->recentUploads(),
        ]);
    }

    public function upload(Request $request): Response
    {
        $file = $request->file('xml_file');
        if ($file === null) {
            flash('xml_error', 'Dosya seçilmedi.');
            return redirect('/admin/xml');
        }

        try {
            // 1) Çok katmanlı doğrulama (boyut/uzantı/MIME/sniff/DOCTYPE reddi)
            $validated = (new XmlUploadValidator())->validate($file);

            // 2) Güvenli saklama (rastgele ad, storage/imports, 0640)
            $stored = (new XmlUploadManager())->store($validated);

            // 3) XXE-güvenli akışlı okuma + ürün düğümü sayımı
            $reader = new XmlStreamReader();
            $result = $reader->parse($stored['path'], 'product', static function (array $fields): void {
                // Faz 2: alan eşleme uygulanacak
            });

            // 4) xml_import_logs kaydı (feed bağlantısız yükleme: xml_feed_id NULL)
            $this->logUpload($stored, $result['count'], $validated['original_name']);

            AuditLogger::log(
                action: 'xml.uploaded',
                adminUserId: Session::get('admin_id') !== null ? (int) Session::get('admin_id') : null,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
                entityType: 'xml_import',
                context: ['file' => $validated['original_name'], 'rows' => $result['count']]
            );

            flash('xml_message', sprintf(
                'Dosya güvenli şekilde kaydedildi: %s — %d ürün düğümü okundu.',
                e($stored['stored_name']),
                $result['count']
            ));
        } catch (\Throwable $e) {
            \App\Core\Logger::security('xml.upload_rejected', [
                'ip' => $request->ip(),
                'reason' => $e->getMessage(),
            ]);
            flash('xml_error', 'Yükleme reddedildi: ' . $e->getMessage());
        }

        return redirect('/admin/xml');
    }

    /**
     * @param array{relative:string, stored_name:string} $stored
     * @param list<string> $errors
     */
    private function logUpload(array $stored, int $rows, string $originalName): void
    {
        try {
            $pdo = \App\Core\Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO xml_import_logs (xml_feed_id, supplier_id, file_name, source, status, total_rows, finished_at)
                 VALUES (NULL, NULL, ?, "upload", ?, ?, NOW())'
            );
            $stmt->execute([$originalName, $rows > 0 ? 'success' : 'partial', $rows]);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('app', 'xml.upload.log_failed', ['reason' => $e->getMessage()]);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recentUploads(): array
    {
        try {
            $pdo = \App\Core\Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT id, file_name, status, total_rows, error_message, created_at
                 FROM xml_import_logs WHERE source = "upload" ORDER BY id DESC LIMIT 10'
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
