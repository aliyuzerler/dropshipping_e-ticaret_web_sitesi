<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Config;

/**
 * XML alan eşleyici: xml_field_mappings profiline göre ham XML alanlarını
 * sistem alanlarına dönüştürür. Tedarikçi XML yapısı değişse de motor değişmez;
 * yalnız eşleme profili farklıdır.
 *
 * Hedef alanlar (target_field):
 *  supplier_sku, barcode, name, description, category, brand,
 *  price (alış fiyatı), currency, vat (KDV %), stock, image (çoklu), variant (çoklu)
 *
 * Dönüşümler (transform):
 *  trim (varsayılan), upper, lower, number (1.234,56 → 1234.56), int, strip_tags, default:değer
 *
 * Tam yol: app/Services/Import/FieldMapper.php
 */
final class FieldMapper
{
    /** Eşlenebilir sistem alanları. */
    public const TARGETS = [
        'supplier_sku' => 'Tedarikçi Ürün Kodu',
        'barcode'      => 'Barkod',
        'name'         => 'Ürün Adı',
        'description'  => 'Açıklama',
        'category'     => 'Kategori',
        'brand'        => 'Marka',
        'price'        => 'Alış Fiyatı',
        'currency'     => 'Para Birimi',
        'vat'          => 'KDV (%)',
        'stock'        => 'Stok Miktarı',
        'image'        => 'Ürün Görseli (çoklu)',
        'variant'      => 'Varyant (çoklu)',
    ];

    /** Kritik alanlar: eksikse satır işlenmez. */
    public const REQUIRED_TARGETS = ['supplier_sku', 'name'];

    /**
     * @param list<array{source_path:string, target_field:string, transform:?string, default_value:?string}> $mappings
     */
    public function __construct(
        private readonly array $mappings
    ) {
    }

    /**
     * Ham alanları normalize edilmiş ürün DTO'suna dönüştürür.
     *
     * @param array<string,string> $raw XmlStreamReader'ın ürettiği alan haritası
     * @return array{
     *   data: array{
     *     supplier_sku:?string, barcode:?string, name:?string, description:?string,
     *     category:?string, brand:?string, cost:?float, cost_original:?float,
     *     source_currency:?string, vat:?float, stock:?int,
     *     images:list<string>, variants:list<string>
     *   },
     *   problems: list<string>
     * }
     */
    public function map(array $raw): array
    {
        $data = [
            'supplier_sku'    => null,
            'barcode'         => null,
            'name'            => null,
            'description'     => null,
            'category'        => null,
            'brand'           => null,
            'cost'            => null,
            'cost_original'   => null,
            'source_currency' => null,
            'vat'             => null,
            'stock'           => null,
            'images'          => [],
            'variants'        => [],
        ];
        $problems = [];

        foreach ($this->mappings as $m) {
            $source = (string) $m['source_path'];
            $target = (string) $m['target_field'];
            if (!array_key_exists($source, $raw)) {
                // Kaynak alan bu üründe yok → default_value varsa kullan
                $default = $m['default_value'] ?? null;
                if ($default === null || $default === '') {
                    continue;
                }
                $value = (string) $default;
            } else {
                $value = $raw[$source];
            }

            $value = $this->transform($value, $m['transform'] ?? null, $m['default_value'] ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            switch ($target) {
                case 'supplier_sku':
                case 'barcode':
                case 'name':
                case 'description':
                case 'category':
                case 'brand':
                case 'currency':
                    $data[$target === 'currency' ? 'source_currency' : $target] = $value;
                    break;

                case 'price':
                    $number = self::parseNumber($value);
                    if ($number === null) {
                        $problems[] = sprintf('Alış fiyatı sayısal değil: "%s"', mb_substr($value, 0, 40));
                    } else {
                        $data['cost_original'] = $number;
                    }
                    break;

                case 'vat':
                    $number = self::parseNumber($value);
                    if ($number === null || $number < 0 || $number > 100) {
                        $problems[] = sprintf('KDV değeri geçersiz: "%s"', mb_substr($value, 0, 40));
                    } else {
                        $data['vat'] = $number;
                    }
                    break;

                case 'stock':
                    $number = self::parseNumber($value);
                    if ($number === null) {
                        $problems[] = sprintf('Stok değeri sayısal değil: "%s"', mb_substr($value, 0, 40));
                    } else {
                        $data['stock'] = (int) floor($number);
                    }
                    break;

                case 'image':
                    foreach ($this->splitList($value) as $img) {
                        $data['images'][] = $img;
                    }
                    break;

                case 'variant':
                    foreach ($this->splitList($value) as $variant) {
                        $data['variants'][] = $variant;
                    }
                    break;

                default:
                    // Bilinmeyen hedef alanlar sessizce yok sayılır (profil esnekliği)
                    break;
            }
        }

        // Para birimi çevirisi (bilinen oran yoksa problem olarak işaretlenir — sessizce kabul edilmez)
        if ($data['cost_original'] !== null) {
            $currency = strtoupper((string) ($data['source_currency'] ?? 'TRY'));
            $rates = (array) Config::get('xml.sync.currency_rates', ['TRY' => 1.0]);
            if (!isset($rates[$currency])) {
                $problems[] = sprintf('Bilinmeyen para birimi: "%s"', $currency);
            } else {
                $data['source_currency'] = $currency;
                $data['cost'] = round($data['cost_original'] * (float) $rates[$currency], 4);
            }
        }

        // NOT: SKU öneki burada UYGULANMAZ. supplier_sku, tedarikçi eşleştirmesinin (psd)
        // ham anahtarıdır; öneki yalnız ürün SKU'su üretilirken eklenir
        // (ProductSyncService::resolveProductSku). Böylece önek değişse bile eşleştirme bozulmaz.

        return ['data' => $data, 'problems' => $problems];
    }

    private function transform(string $value, ?string $transform, ?string $defaultValue): ?string
    {
        $value = trim($value);
        if ($value === '' && $defaultValue !== null && $defaultValue !== '') {
            $value = trim($defaultValue);
        }

        $op = strtolower((string) $transform);
        return match ($op) {
            'upper'       => mb_strtoupper($value, 'UTF-8'),
            'lower'       => mb_strtolower($value, 'UTF-8'),
            'number'      => (string) (self::parseNumber($value) ?? ''),
            'int'         => (string) (self::parseNumber($value) !== null ? (int) floor(self::parseNumber($value)) : ''),
            'strip_tags'  => trim(strip_tags($value)),
            'default'     => $value !== '' ? $value : trim((string) $defaultValue),
            '', 'trim'    => $value,
            default       => $value, // bilinmeyen dönüşüm → trim uygula
        };
    }

    /**
     * Türkçe biçimli fiyat/numara ayrıştırıcı:
     *  "1.234,56 TL" → 1234.56 | "1234.56" → 1234.56 | "%20" → 20.0
     * Harf içeren değerler (ürün kodları gibi) reddedilir — sessizce sayıya çevrilmez.
     */
    public static function parseNumber(string $value): ?float
    {
        $original = trim($value);
        // Para birimi eklerini soy (bağımsız kelime olarak)
        $cleaned = preg_replace('~(YTL|TL|TRY|USD|EUR|GBP)(?![A-Za-z])~i', ' ', $original) ?? $original;
        // Kalan harf varsa bu bir sayı değildir (ürün kodu vb.) → reddet
        if (preg_match('~[A-Za-zçğıöşüÇĞİÖŞÜ]~u', $cleaned) === 1) {
            return null;
        }

        $value = str_replace(["\xC2\xA0", ' '], '', $cleaned); // nbsp + boşluk
        $value = preg_replace('~[^0-9,.\-]~', '', $value) ?? '';
        if ($value === '' || $value === '-' || $value === '-.') {
            return null;
        }

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            // Son ayraç ondalık separatör kabul edilir
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma) {
            // 123,45 → 123.45 ; 1,234 → muhtemelen binlik → 1234
            $parts = explode(',', $value);
            $last = end($parts);
            if (count($parts) === 2 && strlen((string) $last) !== 3) {
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        }

        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /** "|" ile ayrılmış liste değerlerini temiz liste hâline getirir. @return list<string> */
    private function splitList(string $value): array
    {
        $out = [];
        foreach (explode('|', $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }
}
