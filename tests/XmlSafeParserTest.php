<?php

declare(strict_types=1);

/**
 * XmlStreamReader (XXE-güvenli parser) testleri.
 * Tam yol: tests/XmlSafeParserTest.php
 */
use App\Services\Import\XmlStreamReader;

return static function (TestRunner $t): void {
    $t->addTest('XmlStreamReader: geçerli XML akış halinde okunur', static function (): void {
        $file = tempnam(sys_get_temp_dir(), 'xml_ok_') . '.xml';
        file_put_contents($file, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<catalog>
    <product>
        <sku>SKU-001</sku>
        <name>Test Ürün Bir</name>
        <price>149.90</price>
        <stock>12</stock>
    </product>
    <product>
        <sku>SKU-002</sku>
        <name>Test Ürün İki</name>
        <price>79.50</price>
        <stock>0</stock>
    </product>
</catalog>
XML);

        $rows = [];
        $result = (new XmlStreamReader())->parse($file, 'product', static function (array $fields) use (&$rows): void {
            $rows[] = $fields;
        });

        Assert::same(2, $result['count']);
        Assert::count(2, $rows);
        Assert::same('SKU-001', $rows[0]['sku']);
        Assert::same('149.90', $rows[0]['price']);
        Assert::same('Test Ürün İki', $rows[1]['name']);
        @unlink($file);
    });

    $t->addTest('XmlStreamReader: XXE (DOCTYPE+ENTITY) reddedilir — dosya okunmaz', static function (): void {
        $file = tempnam(sys_get_temp_dir(), 'xml_xxe_') . '.xml';
        file_put_contents($file, <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE product [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<catalog>
  <product><name>&xxe;</name></product>
</catalog>
XML);

        $read = false;
        Assert::throws(RuntimeException::class, static function () use ($file, &$read): void {
            (new XmlStreamReader())->parse($file, 'product', static function (array $fields) use (&$read): void {
                $read = true;
            });
        });
        Assert::false($read, 'XXE payload\'ı callback\'e ulaşmamalı');
        @unlink($file);
    });

    $t->addTest('XmlStreamReader: harici varlık yükleyici devre dışı bırakılır', static function (): void {
        // Parser çalıştıktan sonra orijinal loader restore edilmeli (finally garantisi)
        $before = function_exists('libxml_get_external_entity_loader') ? libxml_get_external_entity_loader() : null;

        $file = tempnam(sys_get_temp_dir(), 'xml_ldr_') . '.xml';
        file_put_contents($file, '<?xml version="1.0"?><catalog><product><sku>X</sku></product></catalog>');
        (new XmlStreamReader())->parse($file, 'product', static function (): void {
        });
        @unlink($file);

        $after = function_exists('libxml_get_external_entity_loader') ? libxml_get_external_entity_loader() : null;
        Assert::same($before === null, $after === null, 'Entity loader restore edilmeli');
    });

    $t->addTest('XmlStreamReader: bozuk XML hata döndürür, çökmez', static function (): void {
        $file = tempnam(sys_get_temp_dir(), 'xml_bad_') . '.xml';
        file_put_contents($file, '<?xml version="1.0"?><catalog><product><sku>yarım');

        Assert::throws(\Throwable::class, static function () use ($file): void {
            (new XmlStreamReader())->parse($file, 'product', static function (): void {
            });
        });
        @unlink($file);
    });

    $t->addTest('XmlStreamReader: olmayan dosya RuntimeException verir', static function (): void {
        Assert::throws(RuntimeException::class, static fn () => (new XmlStreamReader())->parse('/tmp/olmayan_' . bin2hex(random_bytes(4)) . '.xml', 'product', static function (): void {
        }));
    });
};
