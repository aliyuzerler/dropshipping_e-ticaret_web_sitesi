<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\FeedRepository;

/**
 * feed:list — XML kaynaklarını tablo olarak listeler.
 *
 * Tam yol: app/Console/Commands/FeedListCommand.php
 */
final class FeedListCommand
{
    /** @param array<string,string|true> $options */
    public function run(array $options): int
    {
        $feeds = (new FeedRepository())->listWithSupplier();

        if ($feeds === []) {
            echo "Tanımlı XML kaynağı yok. Admin panelinden veya seed ile ekleyebilirsiniz.\n";
            return 0;
        }

        printf("%-4s %-24s %-22s %-7s %-6s %-10s %-19s\n", 'ID', 'KAYNAK', 'TEDARİKÇİ', 'TİP', 'AKTİF', 'PERİYOT', 'SON SENK.');
        echo str_repeat('─', 100) . "\n";
        foreach ($feeds as $f) {
            printf(
                "%-4d %-24s %-22s %-7s %-6s %-10s %-19s\n",
                (int) $f['id'],
                mb_substr((string) $f['name'], 0, 24),
                mb_substr((string) ($f['supplier_name'] ?? ''), 0, 22),
                (string) $f['source_type'],
                ((int) $f['is_active']) === 1 ? 'evet' : 'hayır',
                (string) $f['interval_minutes'] . ' dk',
                (string) ($f['last_success_sync_at'] ?? '-')
            );
        }
        echo "\nSenkron: php bin/console xml:sync --feed=ID  |  zamanı gelenler: php bin/console xml:sync --all\n";
        return 0;
    }
}
