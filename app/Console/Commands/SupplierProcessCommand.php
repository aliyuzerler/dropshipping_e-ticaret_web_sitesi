<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Supplier\SupplierOrderService;

/**
 * AŞAMA 5 — Bekleyen tedarikçi siparişlerini işler (API olanları gönderir;
 * manuel tedarikçiler panelde görev olarak kalır).
 *
 * Kullanım:
 *   php bin/console supplier:process
 *   php bin/console supplier:process --id=15      (tek kayıt — admin butonu)
 *   php bin/console supplier:process --limit=100
 *
 * Crontab önerisi: her 5 dakikada bir.
 * Tam yol: app/Console/Commands/SupplierProcessCommand.php
 */
final class SupplierProcessCommand
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        $options = \App\Console\ConsoleKernel::parseOptions($args);
        $limit   = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;
        $id      = isset($options['id']) ? (int) $options['id'] : null;

        $service = new SupplierOrderService();
        $result  = $service->processPending($id, $limit);

        printf(
            "[%s] supplier:process — gönderilen: %d, hata: %d, belirsiz: %d, manuel kuyruk: %d, atlanan: %d\n",
            date('c'),
            $result['sent'],
            $result['errors'],
            $result['unknown'],
            $result['manual'],
            $result['skipped']
        );
        return 0;
    }
}
