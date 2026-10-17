<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Supplier\SupplierOrderService;

/**
 * AŞAMA 5 — Durumu belirsiz (unknown/sending) tedarikçi siparişlerinin
 * tedarikçiden durumunu sorgular. ASLA yeniden gönderim YAPMAZ: önce sorgu,
 * cevap alınamazsa manuel inceleme (görev gereği).
 *
 * Kullanım:
 *   php bin/console supplier:query
 *   php bin/console supplier:query --id=15
 *
 * Crontab önerisi: her 15 dakikada bir.
 * Tam yol: app/Console/Commands/SupplierQueryCommand.php
 */
final class SupplierQueryCommand
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        $options = \App\Console\ConsoleKernel::parseOptions($args);
        $limit   = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;
        $id      = isset($options['id']) ? (int) $options['id'] : null;

        $service = new SupplierOrderService();
        $result  = $service->queryStatuses($id, $limit);

        printf(
            "[%s] supplier:query — sorgulanan: %d, onaylanan: %d, kayıt yok: %d, başarısız sorgu: %d\n",
            date('c'),
            $result['queried'],
            $result['confirmed'],
            $result['not_found'],
            $result['failed']
        );
        return 0;
    }
}
