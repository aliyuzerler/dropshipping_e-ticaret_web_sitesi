<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Logger;
use RuntimeException;

/**
 * Doğrulanmış XML yüklemesini storage/imports altına güvenli biçimde kaydeder.
 *  - Rastgele dosya adı (tahmin edilemez)
 *  - YYYY/MM alt dizinleri
 *  - 0640 dosya izni
 *
 * Tam yol: app/Services/Import/XmlUploadManager.php
 */
final class XmlUploadManager
{
    /**
     * @param array{tmp_name:string, original_name:string, size:int, stored_name:string} $validated XmlUploadValidator çıktısı
     * @return array{path:string, stored_name:string, relative:string}
     */
    public function store(array $validated): array
    {
        $relativeDir = date('Y') . '/' . date('m');
        $dir = storage_path('imports/' . $relativeDir);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Yükleme dizini oluşturulamadı.');
        }

        $target = $dir . '/' . $validated['stored_name'];
        if (!@move_uploaded_file($validated['tmp_name'], $target) && !@rename($validated['tmp_name'], $target)) {
            // CLI/test ortamında move_uploaded_file çalışmaz; copy ile dene
            if (!@copy($validated['tmp_name'], $target)) {
                throw new RuntimeException('Dosya kaydedilemedi.');
            }
        }
        @chmod($target, 0640);

        Logger::info('app', 'xml.upload.stored', [
            'stored' => $validated['stored_name'],
            'size'   => $validated['size'],
        ]);

        return [
            'path'        => $target,
            'stored_name' => $validated['stored_name'],
            'relative'    => $relativeDir . '/' . $validated['stored_name'],
        ];
    }
}
