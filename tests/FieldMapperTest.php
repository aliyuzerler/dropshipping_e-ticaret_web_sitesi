<?php

declare(strict_types=1);

/**
 * FieldMapper birim testleri (VERİTABANISIZ — saf birim testi).
 * Kapsam: parseNumber (TR/düz/yüzde/bozuk/boş), map() alan eşleme,
 * "|" ile liste bölme, SKU öneki, bilinmeyen para birimi, transform'lar,
 * eksik kaynak için default_value.
 * Tam yol: tests/FieldMapperTest.php
 */
use App\Services\Import\FieldMapper;

return static function (TestRunner $t): void {
    /** Eşleme satırı üretici (xml_field_mappings satır şekli). */
    $row = static fn (string $source, string $target, ?string $transform = null, ?string $default = null): array => [
        'source_path'   => $source,
        'target_field'  => $target,
        'transform'     => $transform,
        'default_value' => $default,
    ];

    $t->addTest('FieldMapper: parseNumber TR biçimi "1.234,56" → 1234.56', static function (): void {
        Assert::same(1234.56, FieldMapper::parseNumber('1.234,56'));
    });

    $t->addTest('FieldMapper: parseNumber TR ondalık "42,50" → 42.5', static function (): void {
        Assert::same(42.5, FieldMapper::parseNumber('42,50'));
    });

    $t->addTest('FieldMapper: parseNumber düz biçim "1234.90" → 1234.9', static function (): void {
        Assert::same(1234.9, FieldMapper::parseNumber('1234.90'));
    });

    $t->addTest('FieldMapper: parseNumber yüzde "%20" → 20.0', static function (): void {
        Assert::same(20.0, FieldMapper::parseNumber('%20'));
    });

    $t->addTest('FieldMapper: parseNumber anlamsız metin "abc" → null', static function (): void {
        Assert::same(null, FieldMapper::parseNumber('abc'));
    });

    $t->addTest('FieldMapper: parseNumber boş değer → null', static function (): void {
        Assert::same(null, FieldMapper::parseNumber(''));
        Assert::same(null, FieldMapper::parseNumber('   '));
    });

    $t->addTest('FieldMapper: parseNumber harf içeren değerleri reddeder (ürün kodu sayıya çevrilmez)', static function (): void {
        Assert::same(null, FieldMapper::parseNumber('TK-1001'), 'Ürün kodu sessizce sayıya çevrilmemeli');
        Assert::same(42.5, FieldMapper::parseNumber('42,50 TL'), 'Para birimi eki soyulmalı');
        Assert::same(20.0, FieldMapper::parseNumber('%20'));
    });

    $t->addTest('FieldMapper: map temel alanları eşler (ham sku, fiyat, kdv, stok)', static function () use ($row): void {
        $mapper = new FieldMapper([
            $row('URUN_KODU', 'supplier_sku'),
            $row('URUN_ADI', 'name'),
            $row('ALIS_FIYAT', 'price'),
            $row('DOVIZ', 'currency'),
            $row('KDV', 'vat'),
            $row('STOK', 'stock'),
        ]);

        $out = $mapper->map([
            'URUN_KODU'  => '1001',
            'URUN_ADI'   => 'Mavi Tükenmez Kalem',
            'ALIS_FIYAT' => '42,50',
            'DOVIZ'      => 'TRY',
            'KDV'        => '20',
            'STOK'       => '15',
        ]);

        Assert::same([], $out['problems'], 'Problem üretilmemeli');
        $d = $out['data'];
        Assert::same('1001', $d['supplier_sku'], 'supplier_sku HAM kod kalmalı — öneki yalnız ürün SKU\'sunda uygulanır');
        Assert::same('Mavi Tükenmez Kalem', $d['name']);
        Assert::same(42.5, $d['cost_original']);
        Assert::same(42.5, $d['cost'], 'TRY kuru 1.0 olduğundan cost = cost_original olmalı');
        Assert::same('TRY', $d['source_currency']);
        Assert::same(20.0, $d['vat']);
        Assert::same(15, $d['stock']);
    });

    $t->addTest('FieldMapper: image listesi "|" ile bölünür', static function () use ($row): void {
        $mapper = new FieldMapper([$row('GORSEL', 'image'), $row('GORSEL2', 'image')]);
        $out = $mapper->map([
            'GORSEL'  => 'https://cdn.example-supplier.local/images/a.jpg|https://cdn.example-supplier.local/images/b.jpg',
            'GORSEL2' => 'https://cdn.example-supplier.local/images/c.jpg',
        ]);
        Assert::count(3, $out['data']['images']);
        Assert::same('https://cdn.example-supplier.local/images/a.jpg', $out['data']['images'][0]);
        Assert::same('https://cdn.example-supplier.local/images/c.jpg', $out['data']['images'][2]);
    });

    $t->addTest('FieldMapper: varyant listesi "|" ile bölünür', static function () use ($row): void {
        $mapper = new FieldMapper([$row('VARYANT', 'variant')]);
        // "beden:S|M|L" boru ile bölünür; anahtar yalnız ilk parçada taşınır
        // (ProductSyncService "beden:S" → {"beden":"S"}, "M" → {"varyant":"M"} yazar).
        $out = $mapper->map(['VARYANT' => 'beden:S|M|L']);
        Assert::count(3, $out['data']['variants']);
        Assert::same('beden:S', $out['data']['variants'][0]);
        Assert::same('M', $out['data']['variants'][1]);
        Assert::same('L', $out['data']['variants'][2]);
    });

    $t->addTest('FieldMapper: bilinmeyen para birimi problem üretir, cost null kalır', static function () use ($row): void {
        $mapper = new FieldMapper([$row('ALIS_FIYAT', 'price'), $row('DOVIZ', 'currency')]);
        $out = $mapper->map(['ALIS_FIYAT' => '10,00', 'DOVIZ' => 'XYZ']);

        Assert::count(1, $out['problems'], 'Tek problem üretilmeli');
        Assert::contains('Bilinmeyen para birimi', $out['problems'][0]);
        Assert::contains('XYZ', $out['problems'][0]);
        Assert::same(null, $out['data']['cost'], 'Kur bilinmiyorsa cost hesaplanmamalı');
        Assert::same(10.0, $out['data']['cost_original']);
    });

    $t->addTest('FieldMapper: transform upper / lower / number uygulanır', static function () use ($row): void {
        $upper = new FieldMapper([$row('URUN_ADI', 'name', 'upper')]);
        Assert::same('TÜKENMEZ KALEM', $upper->map(['URUN_ADI' => 'Tükenmez Kalem'])['data']['name']);

        $lower = new FieldMapper([$row('URUN_ADI', 'name', 'lower')]);
        Assert::same('tükenmez kalem', $lower->map(['URUN_ADI' => 'Tükenmez Kalem'])['data']['name']);

        $number = new FieldMapper([$row('ALIS_FIYAT', 'price', 'number')]);
        $out = $number->map(['ALIS_FIYAT' => '1.234,56']);
        Assert::same(1234.56, $out['data']['cost_original']);
        Assert::same([], $out['problems']);
    });

    $t->addTest('FieldMapper: eksik kaynak default_value ile doldurulur', static function () use ($row): void {
        $mapper = new FieldMapper([$row('MARKA', 'brand', null, 'Genel')]);
        $out = $mapper->map(['URUN_ADI' => 'Test Ürün']);
        Assert::same('Genel', $out['data']['brand'], 'Kaynak yoksa default_value kullanılmalı');
    });
};
