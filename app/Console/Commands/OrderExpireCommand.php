<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Order\OrderService;

/**
 * AŞAMA 4 — Süresi geçen 'pending_payment' siparişlerini iptal eder.
 *
 * Kullanım:
 *   php bin/console order:expire
 *   php bin/console order:expire --minutes=90   (varsayılan: config ORDER_EXPIRE_MINUTES)
 *
 * Crontab önerisi: her 10 dakikada bir.
 * Tam yol: app/Console/Commands/OrderExpireCommand.php
 */
final class OrderExpireCommand
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        $options = \App\Console\ConsoleKernel::parseOptions($args);
        $minutes = isset($options['minutes'])
            ? max(5, (int) $options['minutes'])
            : max(5, (int) config('payment.order_expire_minutes', 120));

        $service = new OrderService();
        $count   = $service->expireStalePending($minutes);

        printf(
            "[%s] order:expire — %d dk süresi geçen %d sipariş iptal edildi.\n",
            date('c'),
            $minutes,
            $count
        );
        return 0;
    }
}
