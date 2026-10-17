<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shipping\ShipmentService;

/**
 * AŞAMA 5 — Kargo takip senkronu: takip numarası olan gönderiler kargo
 * adaptöründen sorgulanır. Gerçek kargo API'si bağlanmadıysa (varsayılan
 * manuel adaptör) hiçbir dış çağrı yapılmaz — takip numaraları panelde
 * MANUEL güncellenir.
 *
 * Kullanım: php bin/console shipments:sync
 * Crontab önerisi: her 30 dakikada bir.
 * Tam yol: app/Console/Commands/ShipmentsSyncCommand.php
 */
final class ShipmentsSyncCommand
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        $options = \App\Console\ConsoleKernel::parseOptions($args);
        $limit   = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;

        $service = new ShipmentService();
        $result  = $service->syncFromAdapter($limit);

        printf(
            "[%s] shipments:sync — sorgulanan: %d, güncellenen: %d (adaptör: %s)\n",
            date('c'),
            $result['checked'],
            $result['updated'],
            \App\Services\Shipping\ShippingAdapterFactory::resolve() instanceof \App\Services\Shipping\ManualShippingAdapter ? 'manuel' : 'api'
        );
        return 0;
    }
}
