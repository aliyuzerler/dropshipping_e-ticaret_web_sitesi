<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Logger;
use App\Services\Import\FeedImportService;

/**
 * xml:sync — XML kaynak senkronizasyonu.
 *
 *   php bin/console xml:sync --feed=1   → kaynağı hemen senkronize et
 *   php bin/console xml:sync --all      → zamanı gelen kaynakları işle (cron kullanımı)
 *   php bin/console xml:sync --force    → zamanlamayı yok say, tüm aktif kaynakları işle
 *
 * Tam yol: app/Console/Commands/XmlSyncCommand.php
 */
final class XmlSyncCommand
{
    /** @param array<string,string|true> $options */
    public function run(array $options): int
    {
        $service = new FeedImportService();

        // Tek feed
        if (isset($options['feed']) && $options['feed'] !== true && (string) $options['feed'] !== '') {
            $feedId = (int) $options['feed'];
            echo "[" . date('c') . "] xml:sync feed #{$feedId} başlıyor...\n";
            $result = $service->syncFeedById($feedId, 'cli');
            $this->printResult($feedId, $result);
            Logger::info('app', 'console.xmlsync.feed', ['feed_id' => $feedId, 'status' => $result['status']]);
            return $result['status'] === 'success' || $result['status'] === 'partial' ? 0 : 1;
        }

        // Tüm zamanı gelenler / tüm aktifler
        $force = isset($options['force']);
        $feeds = $force
            ? [...$service->dueUrlFeeds(), ...$this->activeUploadFeeds()]
            : $service->dueUrlFeeds();

        if ($feeds === []) {
            echo "[" . date('c') . "] Zamanı gelen aktif kaynak yok.\n";
            return 0;
        }

        $failures = 0;
        foreach ($feeds as $feed) {
            $feedId = (int) $feed['id'];
            echo "[" . date('c') . "] xml:sync feed #{$feedId} ({$feed['name']})...\n";
            $result = $service->importFeed($feed, 'cron');
            $this->printResult($feedId, $result);
            if (!in_array($result['status'], ['success', 'partial', 'locked'], true)) {
                $failures++;
            }
        }

        Logger::info('app', 'console.xmlsync.done', ['feeds' => count($feeds), 'failures' => $failures]);
        return $failures > 0 ? 1 : 0;
    }

    /** Upload tipi aktif feed'ler (force modunda dosyası olanlar işlenir). @return list<array<string,mixed>> */
    private function activeUploadFeeds(): array
    {
        $pdo = \App\Core\Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM xml_feeds WHERE is_active = 1 AND source_type = "upload" AND last_file_path IS NOT NULL ORDER BY id'
        );
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /** @param array{status:string, rows:int, created:int, updated:int, skipped:int, errors:int, deactivated:int, message:string} $result */
    private function printResult(int $feedId, array $result): void
    {
        printf(
            "  feed #%d → %s | satır: %d, yeni: %d, güncellenen: %d, atlanan: %d, hata: %d, pasife alınan: %d\n",
            $feedId,
            $result['status'],
            $result['rows'],
            $result['created'],
            $result['updated'],
            $result['skipped'],
            $result['errors'],
            $result['deactivated']
        );
        echo '  ' . $result['message'] . "\n";
    }
}
