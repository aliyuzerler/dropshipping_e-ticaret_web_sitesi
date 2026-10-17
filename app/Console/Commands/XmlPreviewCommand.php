<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\FeedRepository;
use App\Services\Import\ImportPreviewService;
use RuntimeException;

/**
 * xml:preview — kaynak XML'ini eşleme profiliyle önizler (veritabanına YAZMAZ).
 *
 *   php bin/console xml:preview --feed=1
 *   php bin/console xml:preview --feed=1 --file=tests/fixtures/sample_feed.xml --limit=5
 *
 * Tam yol: app/Console/Commands/XmlPreviewCommand.php
 */
final class XmlPreviewCommand
{
    /** @param array<string,string|true> $options */
    public function run(array $options): int
    {
        $feedId = isset($options['feed']) ? (int) (is_string($options['feed']) ? $options['feed'] : 0) : 0;
        if ($feedId <= 0) {
            fwrite(STDERR, "--feed=ID zorunludur.\n");
            return 1;
        }

        $feed = (new FeedRepository())->find($feedId);
        if ($feed === null) {
            fwrite(STDERR, "Feed bulunamadı: #{$feedId}\n");
            return 1;
        }

        // Dosya: --file > feed'in son dosyası
        $fileOpt = $options['file'] ?? null;
        if (is_string($fileOpt) && $fileOpt !== '') {
            $path = $fileOpt;
        } else {
            $relative = (string) ($feed['last_file_path'] ?? '');
            if ($relative === '') {
                fwrite(STDERR, "Feed'in yüklenmiş dosyası yok; --file=YOL belirtin.\n");
                return 1;
            }
            $path = \App\Core\App::basePath('storage/' . ltrim($relative, '/'));
        }

        if (!is_file($path)) {
            fwrite(STDERR, "XML dosyası bulunamadı: {$path}\n");
            return 1;
        }

        $limit = isset($options['limit']) && is_string($options['limit']) ? max(1, (int) $options['limit']) : 5;

        try {
            $preview = (new ImportPreviewService())->preview($feed, $path, $limit);
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'Önizleme hatası: ' . $e->getMessage() . "\n");
            return 1;
        }

        echo "Feed #{$feedId} ({$feed['name']}) — önizleme (ilk {$limit} satır / toplam {$preview['total_seen']}):\n";
        echo str_repeat('─', 100) . "\n";

        if ($preview['rows'] === []) {
            echo "Ürün düğümü bulunamadı (product_node: {$feed['product_node']}).\n";
        }

        foreach ($preview['rows'] as $row) {
            printf(
                "#%-3d %s | %s | alış: %s %s → satış: %s TRY | stok: %s | kdv: %s%% | görsel: %d | varyant: %d\n",
                (int) $row['line'],
                (string) ($row['supplier_sku'] ?? '-'),
                mb_substr((string) ($row['name'] ?? '-'), 0, 34),
                $row['cost_original'] !== null ? number_format((float) $row['cost_original'], 2, '.', '') : '-',
                (string) ($row['source_currency'] ?? 'TRY'),
                $row['sale_price'] !== null ? number_format((float) $row['sale_price'], 2, '.', '') : '-',
                $row['stock'] !== null ? (string) $row['stock'] : '-',
                $row['vat'] !== null ? (string) $row['vat'] : '-',
                count((array) $row['images']),
                count((array) $row['variants'])
            );
            if (($row['problems'] ?? []) !== []) {
                foreach ((array) $row['problems'] as $problem) {
                    echo '      ! ' . $problem . "\n";
                }
            }
        }

        echo "\nTespit edilen alanlar: " . ($preview['detected_fields'] === [] ? '-' : implode(', ', $preview['detected_fields'])) . "\n";
        echo "Eşleme sayısı: {$preview['mapping_count']}\n";
        if ($preview['problems'] !== []) {
            echo "\nUyarılar:\n";
            foreach ($preview['problems'] as $problem) {
                echo '  ! ' . $problem . "\n";
            }
        }
        return 0;
    }
}
