<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notification\NotificationService;

/**
 * AŞAMA 5 — Bildirim kuyruğunu boşaltır. Gönderim hataları log'a yazılır ve
 * kayıt deneme sayısı aşılana kadar kuyrukta bekletilir (tekrarlı gönderim yok).
 *
 * Kullanım: php bin/console notifications:send [--limit=100]
 * Crontab önerisi: her 5 dakikada bir.
 * Tam yol: app/Console/Commands/NotificationsSendCommand.php
 */
final class NotificationsSendCommand
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        $options = \App\Console\ConsoleKernel::parseOptions($args);
        $limit   = isset($options['limit']) ? max(1, (int) $options['limit']) : 100;

        $result = NotificationService::processQueue($limit);

        printf(
            "[%s] notifications:send — gönderilen: %d, başarısız: %d, kuyrukta: %d\n",
            date('c'),
            $result['sent'],
            $result['failed'],
            $result['remaining']
        );
        return 0;
    }
}
