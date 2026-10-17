<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/**
 * XXE-güvenli, streaming (XMLReader tabanlı) XML okuyucu.
 *
 * Koruma:
 *  - LIBXML_NONET (ağ erişimi yok)
 *  - libxml_set_external_entity_loader(fn() => null) → harici varlık yok
 *  - LIBXML_NOENT KULLANILMAZ (varlık ikamesi yok)
 *  - DOCTYPE/ENTITY prologu taranır ve reddedilir
 *  - Streaming: tüm dosya belleğe alınmaz
 *
 * Tam yol: app/Services/Import/XmlStreamReader.php
 */
final class XmlStreamReader
{
    /** @var list<string> */
    private array $xmlErrors = [];

    /**
     * XML dosyasını akış halinde okur; her <productNode> için callback çağırır.
     *
     * @param callable(array<string,string>):void $onProduct
     * @param int|null $maxNodes Bu sayıda ürün düğümü işlendikten sonra okuma erken durdurulur
     *                           (alan tespiti / önizleme için). null = tüm dosya.
     * @return array{count:int, errors:list<string>, truncated:bool}
     *
     * @throws RuntimeException
     */
    public function parse(string $filePath, string $productNode, callable $onProduct, ?int $maxNodes = null): array
    {
        $this->xmlErrors = [];

        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('XML dosyası okunamıyor: dosya bulunamadı.');
        }

        if (!class_exists(\XMLReader::class)) {
            throw new RuntimeException('xmlreader PHP eklentisi yüklü değil.');
        }

        // Prolog güvenlik taraması (DOCTYPE/ENTITY reddi)
        $this->rejectPrologThreats($filePath);

        $previousEntityLoader = null;
        $entityLoaderOverridden = false;
        $prevInternalErrors = libxml_use_internal_errors(true);

        $count = 0;

        try {
            // Harici varlık yükleyicisini tamamen devre dışı bırak (XXE)
            $previousEntityLoader = libxml_get_external_entity_loader();
            libxml_set_external_entity_loader(static function (string $public, string $system, array $context): ?string {
                throw new RuntimeException('Harici XML varlık yüklenmesi engellendi: ' . ($system ?: $public));
            });
            $entityLoaderOverridden = true;

            $reader = new \XMLReader();
            $flags = defined('LIBXML_NONET') ? LIBXML_NONET : 0;
            if (defined('LIBXML_COMPACT')) {
                $flags |= LIBXML_COMPACT;
            }

            $opened = $reader->open($filePath, null, $flags);
            if (!$opened) {
                throw new RuntimeException('XML dosyası XMLReader ile açılamadı.');
            }

            $hasFatalError = false;
            $truncated = false;

            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === $productNode) {
                    // Node'u DOM'a genişlet (yalnız bu node — bellek dostu).
                    // @: ErrorHandler uyarıları ErrorException'a çevirir; bozuk tek düğüm
                    // tüm aktarımı düşürmemeli — expand hatası düğüm atlatma olarak işlenir.
                    $node = @$reader->expand();
                    if ($node instanceof \DOMNode) {
                        $fields = $this->extractFields($node);
                        if ($fields !== []) {
                            $count++;
                            $onProduct($fields);
                            if ($maxNodes !== null && $count >= $maxNodes) {
                                $truncated = true;
                                break; // erken durdurma (tam dosya taraması gerekmez)
                            }
                        }
                    }
                }
            }

            $reader->close();

            // Bozuk/yarım XML: ERROR veya FATAL seviye libxml hatası varsa reddet
            foreach (libxml_get_errors() as $error) {
                $this->xmlErrors[] = trim($error->message);
                if (defined('LIBXML_ERR_ERROR') && $error->level >= LIBXML_ERR_ERROR) {
                    $hasFatalError = true;
                }
            }
            if ($hasFatalError) {
                throw new RuntimeException('XML ayrıştırma hatası: dosya bozuk veya yarım.');
            }
        } finally {
            foreach (libxml_get_errors() as $error) {
                $this->xmlErrors[] = trim($error->message);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($prevInternalErrors);
            if ($entityLoaderOverridden) {
                libxml_set_external_entity_loader($previousEntityLoader);
            }
        }

        return ['count' => $count, 'errors' => $this->xmlErrors, 'truncated' => $truncated ?? false];
    }

    /**
     * Bir <product> node'unun alanlarını düz metin olarak çıkarır.
     *
     * Desteklenen yapılar (tedarikçi XML'leri farklı olduğu için sabit etiket varsayılmaz):
     *  - Nitelik (attribute):       <product code="A1">            → '@code' => 'A1'
     *  - Basit çocuk:               <name>Kalem</name>             → 'name' => 'Kalem'
     *  - Tekrarlayan çocuk:         <image>a</image><image>b</image> → 'image' => 'a|b'
     *  - Bir seviye iç içe:         <images><image>a</image></images> → 'images.image' => 'a'
     *
     * @return array<string,string>
     */
    private function extractFields(\DOMNode $productNode): array
    {
        $fields = [];

        // 1) Nitelikler
        if ($productNode->attributes !== null) {
            foreach ($productNode->attributes as $attr) {
                $value = trim((string) $attr->nodeValue);
                if ($value !== '') {
                    $fields['@' . $attr->nodeName] = $value;
                }
            }
        }

        // 2) Çocuk elemanlar
        foreach ($productNode->childNodes ?? [] as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $name = $child->nodeName;

            $elementChildren = [];
            foreach ($child->childNodes ?? [] as $grand) {
                if ($grand instanceof \DOMElement) {
                    $elementChildren[] = $grand;
                }
            }

            if ($elementChildren !== []) {
                // Bir seviye iç içe: parent.child anahtarları
                foreach ($elementChildren as $grand) {
                    $key = $name . '.' . $grand->nodeName;
                    $value = trim($grand->textContent);
                    if ($value === '') {
                        continue;
                    }
                    $fields[$key] = isset($fields[$key]) && $fields[$key] !== ''
                        ? $fields[$key] . '|' . $value
                        : $value;
                }
            } else {
                $value = trim($child->textContent);
                if ($value === '') {
                    continue;
                }
                // Tekrarlayan etiketler | ile birleştirilir
                $fields[$name] = isset($fields[$name]) && $fields[$name] !== ''
                    ? $fields[$name] . '|' . $value
                    : $value;
            }
        }

        return $fields;
    }

    private function rejectPrologThreats(string $filePath): void
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Dosya açılamadı.');
        }
        $head = (string) fread($handle, 65536);
        fclose($handle);

        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            throw new RuntimeException('XML, DOCTYPE/ENTITY tanımı içeriyor; güvenlik nedeniyle reddedildi.');
        }
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->xmlErrors;
    }
}
