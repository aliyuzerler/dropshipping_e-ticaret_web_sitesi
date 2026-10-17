<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Logger;
use App\Repositories\FeedRepository;
use App\Repositories\ImportLogRepository;
use App\Services\Security\Cipher;
use RuntimeException;

/**
 * Feed içe aktarma orkestratörü.
 *
 * Aşama 2'de görevi:
 *  - URL feed'lerini SSRF-güvenli indirmek (UrlFetcher) → storage/imports/feeds/{id}/
 *  - Upload feed'lerinde kayıtlı son dosyayı (last_file_path) kullanmak
 *  - İndirilen/bulunan dosyayı XmlSyncEngine'e vermek (eşleme + ürün senkronizasyonu)
 *  - Zamanı gelen feed'leri cron'dan çalıştırmak (runDueFeeds)
 *
 * Tam yol: app/Services/Import/FeedImportService.php
 */
final class FeedImportService
{
    public function __construct(
        private readonly UrlFetcherWrapper $fetcher = new UrlFetcherWrapper(),
        private readonly XmlSyncEngine $engine = new XmlSyncEngine(),
        private readonly FeedRepository $feeds = new FeedRepository(),
        private readonly ImportLogRepository $logs = new ImportLogRepository()
    ) {
    }

    /** Zamanı gelen aktif URL feed'leri. @return list<array<string,mixed>> */
    public function dueUrlFeeds(): array
    {
        return $this->feeds->dueUrlFeeds();
    }

    /**
     * Feed ID ile tek seferlik senkronizasyon (admin paneli + CLI girişi).
     * @return array{status:string, rows:int, created:int, updated:int, skipped:int,
     *              errors:int, deactivated:int, message:string, log_id:?int}
     */
    public function syncFeedById(int $feedId, string $trigger = 'manual'): array
    {
        $feed = $this->feeds->find($feedId);
        if ($feed === null) {
            return [
                'status' => 'failed', 'rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => 0, 'deactivated' => 0, 'message' => 'Feed bulunamadı: #' . $feedId, 'log_id' => null,
            ];
        }
        return $this->importFeed($feed, $trigger);
    }

    /**
     * Tek feed'i çözer (indirir/bulur) ve senkron motoruna verir.
     * @param array<string,mixed> $feed
     * @return array{status:string, rows:int, created:int, updated:int, skipped:int,
     *              errors:int, deactivated:int, message:string, log_id:?int}
     */
    public function importFeed(array $feed, string $trigger = 'cron'): array
    {
        $feedId = (int) $feed['id'];

        try {
            $filePath = match ((string) ($feed['source_type'] ?? 'upload')) {
                'url'    => $this->downloadToStorage($feed),
                'upload' => $this->resolveUploadedFile($feed),
                default  => throw new RuntimeException('Bilinmeyen kaynak tipi: ' . (string) ($feed['source_type'] ?? '')),
            };
        } catch (\Throwable $e) {
            // Dosya aşamasında başarısızlık → log'a 'failed' yaz, katalogdokunma
            $logId = $this->logs->start([
                'xml_feed_id' => $feedId,
                'supplier_id' => isset($feed['supplier_id']) ? (int) $feed['supplier_id'] : null,
                'file_name'   => (string) ($feed['name'] ?? ('feed-' . $feedId)),
                'source'      => $trigger,
            ]);
            $this->logs->finish($logId, [
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'duration_ms'   => 0,
                'finished_at'   => date('Y-m-d H:i:s'),
            ]);
            Logger::error('app', 'feed.import.file_failed', ['feed_id' => $feedId, 'reason' => $e->getMessage()]);
            $this->feeds->touchImported($feedId);

            return [
                'status' => 'failed', 'rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => 0, 'deactivated' => 0,
                'message' => 'Kaynak dosya alınamadı — katalog korundu: ' . $e->getMessage(),
                'log_id' => $logId,
            ];
        }

        return $this->engine->run($feed, $filePath, $trigger);
    }

    /** Tüm zamanı gelen feed'leri işler (cron girişi). @return array{processed:int, success:int, failed:int, locked:int} */
    public function runDueFeeds(): array
    {
        $summary = ['processed' => 0, 'success' => 0, 'failed' => 0, 'locked' => 0];
        foreach ($this->dueUrlFeeds() as $feed) {
            $result = $this->importFeed($feed, 'cron');
            $summary['processed']++;
            if ($result['status'] === 'success') {
                $summary['success']++;
            } elseif ($result['status'] === 'locked') {
                $summary['locked']++;
            } else {
                $summary['failed']++;
            }
        }
        return $summary;
    }

    /**
     * URL feed'ini SSRF korumalı indirir ve kalıcı saklar.
     * @return string mutlak dosya yolu
     */
    private function downloadToStorage(array $feed): string
    {
        $feedId = (int) $feed['id'];
        $url = (string) ($feed['source_url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('Feed kaynak URL boş.');
        }

        $maxBytes = (int) ($feed['max_file_size_mb'] ?? 100) * 1024 * 1024;
        $basicAuth = $this->resolveBasicAuth($feed);

        $fetched = $this->fetcher->fetch($url, $maxBytes, $basicAuth);

        $dir = storage_path('imports/feeds/' . $feedId);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Feed dizini oluşturulamadı.');
        }
        $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.xml';
        $path = $dir . '/' . $fileName;
        if (@file_put_contents($path, $fetched['body']) === false) {
            throw new RuntimeException('Feed dosyası kaydedilemedi.');
        }
        @chmod($path, 0640);

        $relative = 'imports/feeds/' . $feedId . '/' . $fileName;
        $this->feeds->setLastFilePath($feedId, $relative);

        return $path;
    }

    /**
     * Upload feed'i: yönetim panelinden yüklenmiş son dosyayı bulur.
     * @return string mutlak dosya yolu
     */
    private function resolveUploadedFile(array $feed): string
    {
        $relative = (string) ($feed['last_file_path'] ?? '');
        if ($relative === '') {
            throw new RuntimeException(
                'Bu kaynak için henüz XML dosyası yüklenmemiş. Kaynak düzenleme ekranından dosya yükleyin.'
            );
        }
        $path = base_path('storage/' . ltrim($relative, '/'));
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Kayıtlı XML dosyası bulunamadı: ' . $relative);
        }
        return $path;
    }

    private function resolveBasicAuth(array $feed): ?string
    {
        $authType = (string) ($feed['auth_type'] ?? 'none');
        if ($authType !== 'basic') {
            return null;
        }
        $user = (string) ($feed['auth_username'] ?? '');
        $passEnc = (string) ($feed['auth_password'] ?? '');
        if ($user === '' || $passEnc === '') {
            return null;
        }
        try {
            $pass = Cipher::decrypt($passEnc);
        } catch (\Throwable) {
            throw new RuntimeException('Feed şifresi çözülemedi (APP_KEY değişmiş olabilir).');
        }
        return $user . ':' . $pass;
    }
}
