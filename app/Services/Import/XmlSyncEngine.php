<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Repositories\CategoryRepository;
use App\Repositories\FeedRepository;
use App\Repositories\ImportLogRepository;
use App\Repositories\ProductSupplierDataRepository;
use RuntimeException;
use Throwable;

/**
 * XML senkronizasyon motoru (Aşama 2 çekirdeği).
 *
 * Akış: kilit → log aç → stream parse → parçalı (batch) idempotent upsert →
 *       tam aktarım doğrulanırsa eksik ürünleri pasife al → log kapat → kilit bırak.
 *
 * Dayanıklılık sözleşmesi:
 *  - Başarısız aktarım mevcut katalog verisini KORUR (batch transaction geri alınır,
 *    pasife alma yalnız tam ve doğrulanmış aktarım sonrası yapılır)
 *  - Aynı feed için eşzamanlı aktarım FeedLock ile engellenir
 *  - Satır hataları xml_import_logs'a yazılır; aktarım kaldığı yerden tekrar çalıştırılabilir
 *
 * Tam yol: app/Services/Import/XmlSyncEngine.php
 */
final class XmlSyncEngine
{
    public function __construct(
        private readonly FieldMapperFactory $mapperFactory = new FieldMapperFactory(),
        private readonly ProductSyncService $sync = new ProductSyncService(),
        private readonly FeedLock $lock = new FeedLock(),
        private readonly ImportLogRepository $logs = new ImportLogRepository(),
        private readonly FeedRepository $feeds = new FeedRepository(),
        private readonly ProductSupplierDataRepository $psd = new ProductSupplierDataRepository(),
        private readonly CategoryRepository $categories = new CategoryRepository()
    ) {
    }

    /**
     * Feed'i yerel XML dosyası üzerinden uçtan uca senkronize eder.
     *
     * @param array<string,mixed> $feed xml_feeds satırı
     * @return array{status:'success'|'partial'|'failed'|'locked'|'no_mapping', rows:int,
     *              created:int, updated:int, skipped:int, errors:int, deactivated:int,
     *              message:string, log_id:?int}
     */
    public function run(array $feed, string $filePath, string $trigger = 'manual'): array
    {
        $feedId = (int) $feed['id'];

        // --- Kaynak başına eşzamanlılık kilidi ---
        $lockHandle = null;
        try {
            $lockHandle = $this->lock->acquire($feedId);
        } catch (Throwable $e) {
            return $this->failure(null, 'Kilit alınamadı: ' . $e->getMessage());
        }
        if ($lockHandle === null) {
            Logger::warning('app', 'xmlsync.locked_skip', ['feed_id' => $feedId]);
            return ['status' => 'locked', 'rows' => 0, 'created' => 0, 'updated' => 0,
                    'skipped' => 0, 'errors' => 0, 'deactivated' => 0,
                    'message' => 'Bu kaynak için zaten bir aktarım çalışıyor.', 'log_id' => null];
        }

        $startedAt = microtime(true);
        $startedAtSql = date('Y-m-d H:i:s');

        $logId = $this->logs->start([
            'xml_feed_id' => $feedId,
            'supplier_id' => isset($feed['supplier_id']) ? (int) $feed['supplier_id'] : null,
            'file_name'   => (string) ($feed['name'] ?? ('feed-' . $feedId)),
            'source'      => $trigger === 'cli' ? 'cron' : $trigger,
        ]);
        $this->logs->setFilePath($logId, $filePath);

        $batchSize = max(1, (int) Config::get('xml.sync.batch_size', 50));

        $counters = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $rowErrors = [];   // ilk N satır hatası (log'a yazılır)
        $warnings = [];    // kârlılık eşiği vb.
        $lowMarginCount = 0;
        $seenProductIds = []; // bu aktarımda görülen ürünler (pasife alma koruması)

        try {
            // --- Eşleme profili ---
            $mappings = $this->mapperFactory->mappingsForFeed($feedId);
            if ($mappings === []) {
                throw new RuntimeException(
                    'Eşleme profili tanımlı değil. Önce XML alanlarını sistem alanlarıyla eşleştirin.'
                );
            }

            $mapper = $this->mapperFactory->make($mappings);
            $categoryParentMap = $this->categories->parentMap();

            $pdo = Database::pdo();
            $buffer = [];
            $inTransaction = false;

            $flush = static function () use (&$buffer, &$inTransaction, $pdo): void {
                if ($inTransaction) {
                    $pdo->commit();
                    $inTransaction = false;
                }
                $buffer = [];
            };

            $productNode = (string) ($feed['product_node'] ?? 'product');
            $encoding = (string) ($feed['encoding'] ?? 'UTF-8');
            $reader = new XmlStreamReader();

            $supplierId = (int) $feed['supplier_id'];

            $result = $reader->parse(
                $filePath,
                $productNode,
                function (array $rawFields) use (
                    &$buffer, &$inTransaction, &$counters, &$rowErrors, &$warnings, &$lowMarginCount,
                    $pdo, $mapper, $feed, $supplierId, $logId, $batchSize, $categoryParentMap, &$flush
                ): void {
                    $mapped = $mapper->map($rawFields);

                    // Eşleme dönüşüm hataları satır hatasıdır
                    if ($mapped['problems'] !== []) {
                        $counters['total']++;
                        $counters['errors']++;
                        if (count($rowErrors) < 25) {
                            $rowErrors[] = 'Satır ' . $counters['total'] . ': ' . implode('; ', $mapped['problems']);
                        }
                        return;
                    }

                    $buffer[] = ['raw' => $rawFields, 'data' => $mapped['data']];

                    if (count($buffer) >= $batchSize) {
                        $this->flushBatch(
                            $buffer, $inTransaction, $feed, $supplierId, $logId,
                            $categoryParentMap, $counters, $rowErrors, $warnings, $lowMarginCount, $seenProductIds
                        );
                        $this->logs->progress($logId, [
                            'total_rows'   => $counters['total'],
                            'created_rows' => $counters['created'],
                            'updated_rows' => $counters['updated'],
                            'skipped_rows' => $counters['skipped'],
                            'error_rows'   => $counters['errors'],
                        ]);
                        $flush();
                    }
                }
            );

            // Kalan tampon
            if ($buffer !== [] || $inTransaction) {
                $this->flushBatch(
                    $buffer, $inTransaction, $feed, $supplierId, $logId,
                    $categoryParentMap, $counters, $rowErrors, $warnings, $lowMarginCount, $seenProductIds
                );
                $flush();
            }

            // Parser düzeyi hata (bozuk/yarım XML) → aktarım başarısız
            if ($result['errors'] !== []) {
                throw new RuntimeException('XML ayrıştırma sorunları: ' . implode(' | ', array_slice($result['errors'], 0, 5)));
            }

            // --- Pasife alma: yalnız tam ve doğrulanmış aktarım sonrası ---
            $deactivated = 0;
            if (((int) ($feed['deactivate_missing'] ?? 0)) === 1) {
                $deactivated = $this->psd->deactivateStaleForFeed(
                    $feedId, $startedAtSql, $supplierId, array_keys($seenProductIds)
                );
            }

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $status = $counters['errors'] > 0 ? 'partial' : 'success';

            $this->logs->finish($logId, [
                'status'           => $status,
                'total_rows'       => $counters['total'],
                'created_rows'     => $counters['created'],
                'updated_rows'     => $counters['updated'],
                'skipped_rows'     => $counters['skipped'],
                'error_rows'       => $counters['errors'],
                'deactivated_rows' => $deactivated,
                'error_message'    => $rowErrors === [] ? null : implode("\n", $rowErrors),
                'warnings'         => $this->buildWarnings($warnings, $lowMarginCount),
                'duration_ms'      => $durationMs,
                'finished_at'      => date('Y-m-d H:i:s'),
            ]);

            if ($status === 'success') {
                $this->feeds->touchSuccessSync($feedId);
            } else {
                $this->feeds->touchImported($feedId);
            }

            Logger::info('app', 'xmlsync.done', [
                'feed_id' => $feedId, 'status' => $status,
                'created' => $counters['created'], 'updated' => $counters['updated'],
                'errors' => $counters['errors'], 'deactivated' => $deactivated,
            ]);

            return [
                'status'      => $status,
                'rows'        => $counters['total'],
                'created'     => $counters['created'],
                'updated'     => $counters['updated'],
                'skipped'     => $counters['skipped'],
                'errors'      => $counters['errors'],
                'deactivated' => $deactivated,
                'message'     => $this->humanSummary($status, $counters, $deactivated),
                'log_id'      => $logId,
            ];
        } catch (Throwable $e) {
            // Başarısız aktarım: mevcut katalog KORUNUR (pasife alma yapılmaz)
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            try {
                if (Database::pdo()->inTransaction()) {
                    Database::pdo()->rollBack();
                }
            } catch (Throwable) {
                // bağlantı yoksa loglama denemesi de anlamlı değil
            }
            $this->logs->finish($logId, [
                'status'       => 'failed',
                'total_rows'   => $counters['total'],
                'created_rows' => $counters['created'],
                'updated_rows' => $counters['updated'],
                'error_rows'   => $counters['errors'],
                'error_message'=> mb_substr($e->getMessage(), 0, 2000),
                'duration_ms'  => $durationMs,
                'finished_at'  => date('Y-m-d H:i:s'),
            ]);
            Logger::error('app', 'xmlsync.failed', ['feed_id' => $feedId, 'reason' => $e->getMessage()]);

            return [
                'status' => 'failed', 'rows' => $counters['total'],
                'created' => $counters['created'], 'updated' => $counters['updated'],
                'skipped' => $counters['skipped'], 'errors' => $counters['errors'],
                'deactivated' => 0,
                'message' => 'Aktarım başarısız — katalog verileri korundu: ' . $e->getMessage(),
                'log_id' => $logId,
            ];
        } finally {
            $this->lock->release($feedId);
        }
    }

    /**
     * Bir batch'i transaction içinde işler (parçalı + idempotent).
     *
     * @param list<array{raw:array<string,string>, data:array<string,mixed>}> $buffer referansla
     * @param bool $inTransaction referansla
     * @param array<string,mixed> $feed
     * @param array<int,int|null> $categoryParentMap
     * @param array{total:int, created:int, updated:int, skipped:int, errors:int} $counters referansla
     * @param list<string> $rowErrors referansla
     * @param list<string> $warnings referansla
     * @param array<int,true> $seenProductIds referansla
     */
    private function flushBatch(
        array &$buffer,
        bool &$inTransaction,
        array $feed,
        int $supplierId,
        int $logId,
        array $categoryParentMap,
        array &$counters,
        array &$rowErrors,
        array &$warnings,
        int &$lowMarginCount,
        array &$seenProductIds
    ): void {
        if ($buffer === []) {
            return;
        }
        $pdo = Database::pdo();
        if (!$inTransaction) {
            $pdo->beginTransaction();
            $inTransaction = true;
        }

        $ctx = [
            'supplier_id'         => $supplierId,
            'import_log_id'       => $logId,
            'started_at'          => date('Y-m-d H:i:s'),
            'category_parent_map' => $categoryParentMap,
        ];

        foreach ($buffer as $item) {
            $counters['total']++;
            try {
                $existing = $this->psd->findBySupplierSku($supplierId, (string) ($item['data']['supplier_sku'] ?? ''));
                $res = $this->sync->syncRow($feed, $item['data'], $ctx, $existing);

                if ($res['result'] === 'created') {
                    $counters['created']++;
                } elseif ($res['result'] === 'updated') {
                    $counters['updated']++;
                } else {
                    $counters['skipped']++;
                }

                if (($res['product_id'] ?? null) !== null) {
                    $seenProductIds[(int) $res['product_id']] = true;
                }

                if ($res['low_margin']) {
                    $lowMarginCount++;
                    if ($lowMarginCount <= 10) {
                        $warnings[] = sprintf(
                            'Düşük kâr marjı (sku: %s)',
                            (string) ($item['data']['supplier_sku'] ?? '?')
                        );
                    }
                }
            } catch (RowException $e) {
                // Geçersiz satır: sessizce kabul edilmez, kayda yazılır, aktarım sürer
                $counters['errors']++;
                if (count($rowErrors) < 25) {
                    $rowErrors[] = 'Satır ' . $counters['total'] . ': ' . $e->getMessage();
                }
            } catch (\PDOException $e) {
                // DB düzeyi satır hatası (constraint vb.) — batch'i bozmadan satırı düşür
                $counters['errors']++;
                if (count($rowErrors) < 25) {
                    $rowErrors[] = 'Satır ' . $counters['total'] . ': DB hatası — ' . $e->getMessage();
                }
            }
        }
    }

    /** @param list<string> $warnings */
    private function buildWarnings(array $warnings, int $lowMarginCount): ?string
    {
        if ($lowMarginCount === 0 && $warnings === []) {
            return null;
        }
        $head = $lowMarginCount > 0
            ? sprintf('Kârlılık eşiği uyarısı: %d ürün eşiğin altında.', $lowMarginCount)
            : '';
        return trim($head . ($warnings !== [] ? "\n" . implode("\n", $warnings) : ''));
    }

    /** @param array{total:int, created:int, updated:int, skipped:int, errors:int} $c */
    private function humanSummary(string $status, array $c, int $deactivated): string
    {
        return sprintf(
            '%s — %d satır: %d yeni, %d güncellendi, %d atlandı, %d hata, %d pasife alındı.',
            $status === 'success' ? 'Aktarım tamamlandı' : ($status === 'partial' ? 'Kısmi aktarım' : 'Aktarım'),
            $c['total'],
            $c['created'],
            $c['updated'],
            $c['skipped'],
            $c['errors'],
            $deactivated
        );
    }

    /** @return array{status:'failed', rows:int, created:int, updated:int, skipped:int, errors:int, deactivated:int, message:string, log_id:null} */
    private function failure(?int $logId, string $message): array
    {
        return ['status' => 'failed', 'rows' => 0, 'created' => 0, 'updated' => 0,
                'skipped' => 0, 'errors' => 0, 'deactivated' => 0, 'message' => $message, 'log_id' => $logId];
    }
}
