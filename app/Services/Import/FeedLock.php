<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\App;
use RuntimeException;

/**
 * Kaynak başına eşzamanlı aktarım kilidi (flock).
 * Aynı feed için aynı anda tek senkronizasyon çalışabilir;
 * kilit alınamazsa aktarım güvenli şekilde atlanır.
 *
 * Tam yol: app/Services/Import/FeedLock.php
 */
final class FeedLock
{
    /** @var array<int,resource> */
    private array $handles = [];
    /**
     * Kilidi deneme modunda (LOCK_NB) alır. Alınamazsa null döner.
     *
     * @return resource|null
     */
    public function acquire(int $feedId)
    {
        $dir = App::storagePath('locks');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Kilit dizini oluşturulamadı: ' . $dir);
        }

        $handle = fopen($dir . '/feed_' . $feedId . '.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Kilit dosyası açılamadı (feed #' . $feedId . ').');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        $this->handles[$feedId] = $handle;
        return $handle;
    }

    public function release(int $feedId): void
    {
        if (isset($this->handles[$feedId])) {
            flock($this->handles[$feedId], LOCK_UN);
            fclose($this->handles[$feedId]);
            unset($this->handles[$feedId]);
        }
    }

    public function __destruct()
    {
        foreach (array_keys($this->handles) as $feedId) {
            $this->release((int) $feedId);
        }
    }
}
