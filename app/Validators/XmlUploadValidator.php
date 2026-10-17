<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/**
 * XML yükleme doğrulaması (çok katmanlı):
 *  1) PHP yükleme hatası, 2) boyut, 3) uzantı, 4) MIME (finfo),
 *  5) içerik sniff (<?xml), 6) DOCTYPE/ENTITY reddi (XXE savunma hattı 2).
 *
 * Tam yol: app/Validators/XmlUploadValidator.php
 */
final class XmlUploadValidator
{
    private const SNIFF_SIZE = 8192;
    private const DTD_SCAN_SIZE = 65536;

    /**
     * @return array{tmp_name:string, original_name:string, size:int, stored_name:string}
     *
     * @throws ValidationException
     */
    public function validate(array $file): array
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
            throw new ValidationException(['dosya' => ['Dosya yüklenmedi.']]);
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException(['dosya' => ['Dosya yükleme sırasında hata oluştu (kod: ' . (int) $file['error'] . ').']]);
        }

        $maxBytes = (int) config('xml.upload.max_mb', 100) * 1024 * 1024;
        $size = (int) $file['size'];
        if ($size <= 0 || $size > $maxBytes) {
            throw new ValidationException(['dosya' => ['Dosya boyutu izin verilen sınırı aşıyor (max ' . config('xml.upload.max_mb', 100) . ' MB).']]);
        }

        $originalName = (string) $file['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, (array) config('xml.upload.allowed_ext', ['xml']), true)) {
            throw new ValidationException(['dosya' => ['Yalnızca .xml dosyaları kabul edilir.']]);
        }

        $tmp = (string) $file['tmp_name'];
        if (!is_file($tmp) || !is_readable($tmp)) {
            throw new ValidationException(['dosya' => ['Yüklenen dosya okunamadı.']]);
        }

        // MIME kontrolü (finfo ile gerçek içerik tipi)
        $allowedMime = (array) config('xml.upload.allowed_mime', ['text/xml', 'application/xml']);
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if (!in_array($mime, $allowedMime, true)) {
                    throw new ValidationException(['dosya' => ['Geçersiz dosya tipi (' . $mime . '). Yalnız XML kabul edilir.']]);
                }
            }
        }

        // İçerik sniff + DTD taraması
        $handle = fopen($tmp, 'rb');
        if ($handle === false) {
            throw new ValidationException(['dosya' => ['Dosya açılamadı.']]);
        }
        $head = (string) fread($handle, self::DTD_SCAN_SIZE);
        fclose($handle);

        if (!$this->looksLikeXml($head)) {
            throw new ValidationException(['dosya' => ['Dosya içeriği XML değil.']]);
        }

        if ($this->containsPrologThreats($head)) {
            throw new ValidationException(['dosya' => ['Dosya DOCTYPE/ENTITY tanımı içeriyor; güvenlik nedeniyle reddedildi.']]);
        }

        return [
            'tmp_name'      => $tmp,
            'original_name' => basename($originalName),
            'size'          => $size,
            'stored_name'   => bin2hex(random_bytes(16)) . '.xml',
        ];
    }

    private function looksLikeXml(string $head): bool
    {
        // UTF-8 BOM'u soy
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }
        $trimmed = ltrim($head);
        return str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<');
    }

    private function containsPrologThreats(string $head): bool
    {
        return stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false;
    }
}
